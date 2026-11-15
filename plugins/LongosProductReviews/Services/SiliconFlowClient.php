<?php

namespace Plugin\LongosProductReviews\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Plugin\LongosProductReviews\Exceptions\SiliconFlowException;

class SiliconFlowClient
{
    private const API_URL = 'https://api.siliconflow.cn/v1';

    public function __construct(private readonly SiliconFlowSettings $settings) {}

    /** @return list<string> */
    public function availableChatModels(): array
    {
        $runtime  = $this->configuredRuntime();
        $traceId  = (string) Str::uuid();

        $response = $this->requestWithRetry(fn (): Response => Http::acceptJson()
            ->withHeaders(['X-Trace-Id' => $traceId])
            ->withToken($runtime['api_key'])
            ->connectTimeout(5)
            ->timeout($runtime['timeout'])
            ->get(self::API_URL . '/models', ['sub_type' => 'chat']));
        $this->assertSuccessful($response);

        return collect((array) $response->json('data', []))
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && trim($id) !== '')
            ->map(fn (string $id): string => trim($id))
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<array{role:string,content:string}> $messages
     *  @return array<string, mixed>
     */
    public function chatJson(array $messages, int $maxTokens = 4096, ?string $model = null): array
    {
        $runtime  = $this->configuredRuntime();
        $traceId  = (string) Str::uuid();
        $model    = $this->model($model);

        $response = $this->requestWithRetry(fn (): Response => Http::acceptJson()
            ->asJson()
            ->withHeaders(['X-Trace-Id' => $traceId])
            ->withToken($runtime['api_key'])
            ->connectTimeout(5)
            ->timeout($runtime['timeout'])
            ->post(self::API_URL . '/chat/completions', [
                'model'           => $model,
                'messages'        => $messages,
                'response_format' => ['type' => 'json_object'],
                'temperature'     => 0.85,
                'max_tokens'      => max(512, min(8192, $maxTokens)),
                'stream'          => false,
            ]));
        $this->assertSuccessful($response);

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new SiliconFlowException(trans('LongosProductReviews::reviews.ai_error_empty_response'));
        }

        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $content) ?? $content;
        }

        try {
            $decoded = json_decode(trim($content), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SiliconFlowException(
                trans('LongosProductReviews::reviews.ai_error_invalid_json'),
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new SiliconFlowException(trans('LongosProductReviews::reviews.ai_error_invalid_json'));
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

    /** @return array{api_key:string,model:string,timeout:int} */
    private function configuredRuntime(): array
    {
        $runtime = $this->settings->runtime();
        if ($runtime['api_key'] === '') {
            throw new SiliconFlowException(trans('LongosProductReviews::reviews.ai_api_key_required'));
        }

        return $runtime;
    }

    /** @param callable(): Response $request */
    private function requestWithRetry(callable $request): Response
    {
        $lastConnectionException = null;
        foreach (range(1, 3) as $attempt) {
            try {
                $response = $request();
                if (! in_array($response->status(), [408, 425, 429, 500, 502, 503, 504], true) || $attempt === 3) {
                    return $response;
                }

                $retryAfter = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT);
                $delayMs    = $retryAfter === false
                    ? 200 * $attempt
                    : min(2000, max(0, $retryAfter * 1000));
            } catch (ConnectionException $exception) {
                $lastConnectionException = $exception;
                if ($attempt === 3) {
                    break;
                }
                $delayMs = 200 * $attempt;
            }
            usleep($delayMs * 1000);
        }

        throw new SiliconFlowException(
            trans('LongosProductReviews::reviews.ai_error_connection'),
            previous: $lastConnectionException,
        );
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = match ($response->status()) {
            401, 403 => trans('LongosProductReviews::reviews.ai_error_auth'),
            404      => trans('LongosProductReviews::reviews.ai_error_model'),
            429      => trans('LongosProductReviews::reviews.ai_error_rate_limit'),
            503, 504 => trans('LongosProductReviews::reviews.ai_error_unavailable'),
            default  => trans('LongosProductReviews::reviews.ai_error_request', ['status' => $response->status()]),
        };
        $traceId = trim((string) $response->header('x-siliconcloud-trace-id'));
        if ($traceId !== '') {
            $message .= ' ' . trans('LongosProductReviews::reviews.ai_trace_id', ['id' => $traceId]);
        }

        throw new SiliconFlowException($message);
    }
}
