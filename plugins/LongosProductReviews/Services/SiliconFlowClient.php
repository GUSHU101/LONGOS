<?php

namespace Plugin\LongosProductReviews\Services;

use App\Services\SiliconFlowRateLimitCatalog;
use App\Services\SiliconFlowRateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Plugin\LongosProductReviews\Exceptions\GenerationOutputException;
use Plugin\LongosProductReviews\Exceptions\RetryableSiliconFlowException;
use Plugin\LongosProductReviews\Exceptions\SiliconFlowException;
use Throwable;

class SiliconFlowClient
{
    private const API_URL = 'https://api.siliconflow.cn/v1';

    private const MODELS_CACHE_MINUTES = 15;

    private const MODELS_STALE_HOURS = 24;

    public function __construct(
        private readonly SiliconFlowSettings $settings,
        private readonly SiliconFlowRateLimitCatalog $rateLimits,
        private readonly SiliconFlowRateLimiter $rateLimiter,
    ) {}

    /** @return list<string> */
    public function availableChatModels(): array
    {
        $runtime  = $this->configuredRuntime();
        $cacheKey = 'longos:siliconflow:chat-models:' . hash('sha256', $runtime['api_key']);
        $cached   = $this->normalizeModelList(Cache::get($cacheKey));
        if ($cached !== []) {
            return $cached;
        }

        $traceId = (string) Str::uuid();
        $model   = $runtime['model'];

        try {
            // A browser action must never wait through a full provider cooldown
            // and become a proxy timeout. Record a 429 in the shared limiter,
            // then return a controlled form message (or stale known-good data).
            $response = $this->requestWithRetry(
                fn (): Response => Http::acceptJson()
                    ->withHeaders(['X-Trace-Id' => $traceId])
                    ->withToken($runtime['api_key'])
                    ->connectTimeout(5)
                    ->timeout($runtime['timeout'])
                    ->get(self::API_URL . '/models', ['sub_type' => 'chat']),
                beforeAttempt: function () use ($runtime, $model): void {
                    if ($this->rateLimiter->reserveCapacity($runtime['api_key'], $model, 1) > 0) {
                        throw new RetryableSiliconFlowException(trans('LongosProductReviews::reviews.ai_error_rate_limit'));
                    }
                },
                onRateLimit: fn (Response $response) => $this->rateLimiter->penalize(
                    $runtime['api_key'],
                    $model,
                    $this->retryDelaySeconds($response),
                ),
                maxAttempts: 1,
            );
            $this->assertSuccessful($response);
        } catch (RetryableSiliconFlowException $exception) {
            $stale = $this->normalizeModelList(Cache::get($cacheKey . ':stale'));
            if ($stale !== []) {
                return $stale;
            }

            throw $exception;
        }

        $models = collect((array) $response->json('data', []))
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && trim($id) !== '')
            ->map(fn (string $id): string => trim($id))
            ->unique()
            ->values()
            ->all();
        if ($models !== []) {
            Cache::put($cacheKey, $models, now()->addMinutes(self::MODELS_CACHE_MINUTES));
            Cache::put($cacheKey . ':stale', $models, now()->addHours(self::MODELS_STALE_HOURS));
        }

        return $models;
    }

    /** @param list<array{role:string,content:string}> $messages
     *  @return array<string, mixed>
     */
    public function chatJson(array $messages, int $maxTokens = 4096, ?string $model = null): array
    {
        $runtime      = $this->configuredRuntime();
        $traceId      = (string) Str::uuid();
        $model        = $this->model($model);
        $maxTokens    = max(512, min(8192, $maxTokens));
        $tokenCeiling = $this->tokenCeiling($messages, $maxTokens);
        $reservedAt   = 0;

        $response = $this->requestWithRetry(
            fn (): Response => Http::acceptJson()
                ->asJson()
                ->withHeaders(['X-Trace-Id' => $traceId])
                ->withToken($runtime['api_key'])
                ->connectTimeout(min(12, $runtime['timeout']))
                ->timeout($runtime['timeout'])
                ->post(self::API_URL . '/chat/completions', $this->requestBody($messages, $maxTokens, $model)),
            beforeAttempt: function () use ($runtime, $model, $tokenCeiling, &$reservedAt): void {
                $this->rateLimiter->awaitCapacity($runtime['api_key'], $model, $tokenCeiling);
                $reservedAt = time();
            },
            onRateLimit: function (Response $response) use ($runtime, $model): void {
                $this->rateLimiter->penalize($runtime['api_key'], $model, $this->retryDelaySeconds($response));
            },
            afterAttempt: function (?Response $response) use ($runtime, $model, $messages, $tokenCeiling, &$reservedAt): void {
                if ($reservedAt < 1) {
                    return;
                }
                $this->rateLimiter->settleCapacity(
                    $runtime['api_key'],
                    $model,
                    $tokenCeiling,
                    $this->actualTokenUsage($response, $messages),
                    $reservedAt,
                );
                $reservedAt = 0;
            },
        );
        $this->assertSuccessful($response);

        return $this->decodeResponse($response);
    }

    /**
     * @param list<array{messages:list<array{role:string,content:string}>,max_tokens:int,model:string}> $requests
     * @return list<array<string, mixed>>
     */
    public function chatJsonMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        $runtime  = $this->configuredRuntime();
        $prepared = [];
        $ready    = [];
        foreach (array_values($requests) as $index => $request) {
            $messages         = $request['messages'];
            $model            = $this->model($request['model']);
            $maxTokens        = max(512, min(8192, (int) $request['max_tokens']));
            $tokenCeiling     = $this->tokenCeiling($messages, $maxTokens);
            $prepared[$index] = [
                'messages'      => $messages,
                'model'         => $model,
                'max_tokens'    => $maxTokens,
                'token_ceiling' => $tokenCeiling,
                'trace_id'      => (string) Str::uuid(),
                'reserved_at'   => 0,
            ];
            if ($this->rateLimiter->reserveCapacity($runtime['api_key'], $model, $tokenCeiling) === 0) {
                $prepared[$index]['reserved_at'] = time();
                $ready[$index]                   = $prepared[$index];
            }
        }

        $responses = [];
        if ($ready !== []) {
            $responses = Http::pool(function (Pool $pool) use ($ready, $runtime): void {
                foreach ($ready as $index => $request) {
                    $pool->as((string) $index)
                        ->acceptJson()
                        ->asJson()
                        ->withHeaders(['X-Trace-Id' => $request['trace_id']])
                        ->withToken($runtime['api_key'])
                        ->connectTimeout(min(12, $runtime['timeout']))
                        ->timeout($runtime['timeout'])
                        ->post(
                            self::API_URL . '/chat/completions',
                            $this->requestBody($request['messages'], $request['max_tokens'], $request['model']),
                        );
                }
            }, min(SiliconFlowSettings::MAX_MODELS, count($ready)));
        }

        $decoded = [];
        foreach ($prepared as $index => $request) {
            $response = $responses[(string) $index] ?? null;
            if ($request['reserved_at'] > 0) {
                $this->rateLimiter->settleCapacity(
                    $runtime['api_key'],
                    $request['model'],
                    $request['token_ceiling'],
                    $this->actualTokenUsage($response instanceof Response ? $response : null, $request['messages']),
                    $request['reserved_at'],
                );
            }
            if ($response instanceof Response && $response->successful()) {
                try {
                    $decoded[$index] = $this->decodeResponse($response);
                } catch (GenerationOutputException) {
                    $decoded[$index] = $this->chatJson(
                        $request['messages'],
                        $request['max_tokens'],
                        $request['model'],
                    );
                }

                continue;
            }
            if ($response instanceof Response && $response->status() === 429) {
                $this->rateLimiter->penalize(
                    $runtime['api_key'],
                    $request['model'],
                    $this->retryDelaySeconds($response),
                );
            } elseif ($response instanceof Response && ! in_array($response->status(), [408, 425, 500, 502, 503, 504], true)) {
                $this->assertSuccessful($response);
            } elseif ($response instanceof Throwable && ! $response instanceof ConnectionException) {
                throw new RetryableSiliconFlowException(
                    trans('LongosProductReviews::reviews.ai_error_connection'),
                    previous: $response,
                );
            }

            // A deferred admission or failed pooled request is retried through
            // the normal bounded path. Already-ready models are never held back
            // by another model's cooldown window.
            $decoded[$index] = $this->chatJson(
                $request['messages'],
                $request['max_tokens'],
                $request['model'],
            );
        }
        ksort($decoded);

        return array_values($decoded);
    }

    /** @return array<string, mixed> */
    private function decodeResponse(Response $response): array
    {

        $finishReason = trim((string) $response->json('choices.0.finish_reason', ''));
        if ($finishReason === 'length') {
            throw new GenerationOutputException(trans('LongosProductReviews::reviews.ai_error_truncated'));
        }
        if ($finishReason === 'content_filter') {
            throw new GenerationOutputException(trans('LongosProductReviews::reviews.ai_error_filtered'));
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new GenerationOutputException(trans('LongosProductReviews::reviews.ai_error_empty_response'));
        }

        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $content) ?? $content;
        }

        try {
            $decoded = json_decode(trim($content), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new GenerationOutputException(
                trans('LongosProductReviews::reviews.ai_error_invalid_json'),
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new GenerationOutputException(trans('LongosProductReviews::reviews.ai_error_invalid_json'));
        }

        return $decoded;
    }

    public function model(?string $override = null): string
    {
        $override = trim((string) $override);
        if ($override !== '') {
            return $override;
        }

        return $this->settings->runtime()['model'];
    }

    public function configuredModel(): string
    {
        return $this->configuredRuntime()['model'];
    }

    /** @return list<string> */
    public function configuredModels(): array
    {
        return $this->configuredRuntime()['models'];
    }

    /** @return array{model:string,level:int,rpm:int,tpm:int,source:string} */
    public function configuredRateLimits(bool $refresh = false): array
    {
        return $this->rateLimits->profile($this->configuredModel(), refresh: $refresh);
    }

    /** @return list<array{model:string,level:int,rpm:int,tpm:int,source:string}> */
    public function configuredRateLimitProfiles(bool $refresh = false): array
    {
        return array_map(
            fn (string $model): array => $this->rateLimits->profile($model, refresh: $refresh),
            $this->configuredModels(),
        );
    }

    /** @return array{api_key:string,model:string,models:list<string>,timeout:int} */
    private function configuredRuntime(): array
    {
        $runtime = $this->settings->runtime();
        if ($runtime['api_key'] === '') {
            throw new SiliconFlowException(trans('LongosProductReviews::reviews.ai_api_key_required'));
        }

        return $runtime;
    }

    /** @param list<array{role:string,content:string}> $messages */
    private function tokenCeiling(array $messages, int $maxTokens): int
    {
        return array_sum(array_map(
            static fn (array $message): int => SiliconFlowRateLimiter::estimateTokens((string) ($message['content'] ?? '')),
            $messages,
        )) + $maxTokens + 128;
    }

    /** @param list<array{role:string,content:string}> $messages */
    private function actualTokenUsage(?Response $response, array $messages): int
    {
        if ($response === null || ! $response->successful()) {
            return 0;
        }

        $reported = (int) $response->json('usage.total_tokens', 0);
        if ($reported > 0) {
            return $reported;
        }

        $completion = (string) $response->json('choices.0.message.content', '');

        return array_sum(array_map(
            static fn (array $message): int => SiliconFlowRateLimiter::estimateTokens((string) ($message['content'] ?? '')),
            $messages,
        )) + SiliconFlowRateLimiter::estimateTokens($completion) + 128;
    }

    /** @param list<array{role:string,content:string}> $messages
     * @return array<string, mixed>
     */
    private function requestBody(array $messages, int $maxTokens, string $model): array
    {
        return [
            'model'           => $model,
            'messages'        => $messages,
            'response_format' => ['type' => 'json_object'],
            'temperature'     => 0.85,
            'max_tokens'      => $maxTokens,
            'stream'          => false,
        ];
    }

    /** @param callable(): Response $request
     * @param (callable(): void)|null          $beforeAttempt
     * @param (callable(Response): void)|null  $onRateLimit
     * @param (callable(?Response): void)|null $afterAttempt
     */
    private function requestWithRetry(
        callable $request,
        ?callable $beforeAttempt = null,
        ?callable $onRateLimit = null,
        int $maxAttempts = 6,
        ?callable $afterAttempt = null,
    ): Response {
        $lastConnectionException = null;
        $maxAttempts             = min(10, max(1, $maxAttempts));
        foreach (range(1, $maxAttempts) as $attempt) {
            try {
                if ($beforeAttempt !== null) {
                    $beforeAttempt();
                }
                $response = $request();
                if ($afterAttempt !== null) {
                    $afterAttempt($response);
                }
                if ($response->status() === 429) {
                    if ($onRateLimit !== null) {
                        $onRateLimit($response);
                    }
                }
                if (! in_array($response->status(), [408, 425, 429, 500, 502, 503, 504], true) || $attempt === $maxAttempts) {
                    return $response;
                }

                $delayMs = $this->retryDelayMs($response, $attempt);
            } catch (ConnectionException $exception) {
                if ($afterAttempt !== null) {
                    $afterAttempt(null);
                }
                $lastConnectionException = $exception;
                if ($attempt >= min(4, $maxAttempts)) {
                    break;
                }
                $delayMs = $this->retryDelayMs(null, $attempt);
            }
            usleep($delayMs * 1000);
        }

        throw new RetryableSiliconFlowException(
            trans('LongosProductReviews::reviews.ai_error_connection'),
            previous: $lastConnectionException,
        );
    }

    /** @return list<string> */
    private function normalizeModelList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return collect($value)
            ->filter(static fn (mixed $id): bool => is_string($id) && trim($id) !== '')
            ->map(static fn (string $id): string => trim($id))
            ->unique()
            ->values()
            ->all();
    }

    private function retryDelayMs(?Response $response, int $attempt): int
    {
        $retryAfter = trim((string) $response?->header('Retry-After'));
        $seconds    = null;
        if ($retryAfter !== '') {
            if (ctype_digit($retryAfter)) {
                $seconds = (int) $retryAfter;
            } else {
                $timestamp = strtotime($retryAfter);
                if ($timestamp !== false) {
                    $seconds = max(0, $timestamp - time());
                }
            }
        }

        $base = $seconds === null
            ? ($response?->status() === 429
                ? 60_000
                : min(15_000, 500 * (2 ** max(0, $attempt - 1))))
            : min(120_000, $seconds * 1000);

        return $base + random_int(0, min(1000, max(50, (int) ($base / 10))));
    }

    private function retryDelaySeconds(Response $response): int
    {
        $retryAfter = trim((string) $response->header('Retry-After'));
        if (ctype_digit($retryAfter)) {
            return min(600, max(5, (int) $retryAfter));
        }
        if ($retryAfter !== '' && ($timestamp = strtotime($retryAfter)) !== false) {
            return min(600, max(5, $timestamp - time()));
        }

        return 60;
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = match ($response->status()) {
            401, 403 => trans('LongosProductReviews::reviews.ai_error_auth'),
            404      => trans('LongosProductReviews::reviews.ai_error_model'),
            429      => trans('LongosProductReviews::reviews.' . match (SiliconFlowRateLimitCatalog::rateLimitCode((array) $response->json())) {
                'RATE_LIMIT_TPM' => 'ai_error_rate_limit_tpm',
                'RATE_LIMIT_RPM' => 'ai_error_rate_limit_rpm',
                default          => 'ai_error_rate_limit',
            }),
            503, 504 => trans('LongosProductReviews::reviews.ai_error_unavailable'),
            default  => trans('LongosProductReviews::reviews.ai_error_request', ['status' => $response->status()]),
        };
        $traceId = trim((string) $response->header('x-siliconcloud-trace-id'));
        if ($traceId !== '') {
            $message .= ' ' . trans('LongosProductReviews::reviews.ai_trace_id', ['id' => $traceId]);
        }

        $exception = in_array($response->status(), [408, 425, 429, 500, 502, 503, 504], true)
            ? RetryableSiliconFlowException::class
            : SiliconFlowException::class;

        throw new $exception($message);
    }
}
