<?php

namespace App\Http\Controllers;

use App\Mail\CloudflareMailDnsService;
use App\Mail\CloudMailTenantResolver;
use App\Mail\Exceptions\MailSenderAuthorizationException;
use App\Mail\MailSenderProfileService;
use Beike\Models\AdminUserToken;
use Beike\Repositories\AdminUserTokenRepo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CloudMailRelayController extends Controller
{
    public function send(
        Request $request,
        MailSenderProfileService $senders,
        CloudMailTenantResolver $tenants,
    ): JsonResponse {
        if (! $tenants->gatewayAvailable($request)) {
            return $this->error('Cloud Mail integration is available only on the LONGOS control domain.', Response::HTTP_FORBIDDEN);
        }

        $token = $this->authenticate($request, AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND);
        if (! $token) {
            return $this->error('Invalid or expired integration token.', Response::HTTP_UNAUTHORIZED);
        }

        $raw = $request->getContent();
        $max = max(1024, (int) config('mail.relay.max_request_bytes', 26214400));
        if ($raw === '' || strlen($raw) > $max) {
            return $this->error('Request body is empty or too large.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error('Invalid JSON request.', Response::HTTP_BAD_REQUEST);
        }
        if (! is_array($payload) || array_is_list($payload)) {
            return $this->error('JSON object expected.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $site = $tenants->resolve((string) data_get($payload, 'from.address', ''));
            $senders->assertAuthorizedForSite((string) data_get($payload, 'from.address', ''), $site);
        } catch (MailSenderAuthorizationException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Unable to verify the sender identity.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // The authenticated gateway owns the relay identity. Never trust the
        // external client to choose internal tenant or administrator audit
        // fields. Preserve only a bounded, non-authoritative category label.
        $clientContext = is_array($payload['senderContext'] ?? null) ? $payload['senderContext'] : [];
        $category      = is_string($clientContext['category'] ?? null)
            && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $clientContext['category'])
                ? $clientContext['category']
                : '';
        $tenantCode    = (string) ($site?->code ?: 'single-store');
        $tenantId      = (string) ($site?->uuid ?: $site?->getKey() ?: 'single-store');

        $payload['senderContext'] = [
            'source'     => 'cloud-mail',
            'tenantId'   => $tenantId,
            'tenantCode' => $tenantCode,
            'accountId'  => (string) $token->admin_user_id,
            'category'   => $category,
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->forward('POST', 'v1/send', $encoded);
    }

    public function health(Request $request, CloudMailTenantResolver $tenants): JsonResponse
    {
        if (! $tenants->gatewayAvailable($request)) {
            return $this->error('Cloud Mail integration is available only on the LONGOS control domain.', Response::HTTP_FORBIDDEN);
        }

        if (! $this->authenticate($request, AdminUserTokenRepo::ABILITY_CLOUD_MAIL_HEALTH)) {
            return $this->error('Invalid or expired integration token.', Response::HTTP_UNAUTHORIZED);
        }

        return $this->forward('GET', 'health', '');
    }

    /**
     * Return only public DNS material. Cloud Mail applies this manifest from
     * its protected deployment runner, so the Cloudflare credential never
     * enters LONGOS, Laravel logs, the database, or a production container.
     */
    public function dnsManifest(Request $request, CloudMailTenantResolver $tenants): JsonResponse
    {
        if (! $tenants->gatewayAvailable($request)) {
            return $this->error('Cloud Mail integration is available only on the LONGOS control domain.', Response::HTTP_FORBIDDEN);
        }
        if (! $this->authenticate($request, AdminUserTokenRepo::ABILITY_CLOUD_MAIL_HEALTH)) {
            return $this->error('Invalid or expired integration token.', Response::HTTP_UNAUTHORIZED);
        }
        try {
            $records = (new CloudflareMailDnsService)->expectedRecords();
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Unable to prepare the public mail DNS manifest.', Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if ($records === []) {
            return $this->error('No outbound mail domains are configured.', Response::HTTP_CONFLICT);
        }
        $pending = array_values(array_map(
            static fn (array $record): string => (string) ($record['name'] ?? ''),
            array_filter($records, static fn (array $record): bool => ! ($record['available'] ?? false)),
        ));
        if ($pending !== []) {
            return response()->json(
                ['status' => 'pending', 'message' => 'The mail relay has not published every DKIM public key yet.', 'pending' => $pending],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'],
            );
        }

        return response()->json(
            ['status' => 'ok', 'records' => $records],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function authenticate(Request $request, string $ability): ?AdminUserToken
    {
        return AdminUserTokenRepo::getAdminUserTokenByToken($request->bearerToken(), $ability);
    }

    private function forward(string $method, string $path, string $body): JsonResponse
    {
        $endpoint = rtrim((string) config('mail.mailers.self_hosted.endpoint'), '/');
        $secret   = (string) config('mail.relay.cloud_mail_secret');
        if (! $this->safeRelayEndpoint($endpoint) || strlen($secret) < 32) {
            return $this->error('The built-in mail relay is not configured.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $timestamp = (string) time();
        $nonce     = (string) Str::uuid();
        $signature = hash_hmac('sha256', "{$timestamp}.{$nonce}.{$body}", $secret);

        try {
            $client = Http::acceptJson()
                ->asJson()
                ->timeout(max(1, (int) config('mail.mailers.self_hosted.timeout', 30)))
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withHeaders([
                    'X-Relay-Client'    => 'cloud-mail',
                    'X-Relay-Timestamp' => $timestamp,
                    'X-Relay-Nonce'     => $nonce,
                    'X-Relay-Signature' => $signature,
                ]);
            $response = $method === 'GET'
                ? $client->get("{$endpoint}/{$path}")
                : $client->withBody($body, 'application/json')->post("{$endpoint}/{$path}");
        } catch (ConnectionException) {
            return $this->error('The built-in mail relay is unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $responseBody = $response->json();
        if (! is_array($responseBody)) {
            return $this->error('The built-in mail relay returned an invalid response.', Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(
            $responseBody,
            $response->status(),
            ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function safeRelayEndpoint(string $endpoint): bool
    {
        $parts  = parse_url($endpoint);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['mail-relay', '127.0.0.1', 'localhost'], true));
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(
            ['accepted' => false, 'message' => $message],
            $status,
            ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
