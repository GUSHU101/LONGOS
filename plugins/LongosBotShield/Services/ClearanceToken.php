<?php

namespace Plugin\LongosBotShield\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Plugin\LongosBotShield\Support\IpAddress;
use Throwable;

class ClearanceToken
{
    public const COOKIE = 'longos_bot_clearance';

    public function __construct(private readonly BotShieldSettings $settings) {}

    public function issue(Request $request, int $ttlSeconds = 3600): string
    {
        // The durable clearance intentionally is not IP-bound. Mobile shoppers
        // commonly move between Wi-Fi and carrier networks during a session;
        // tenant, host, UA, encryption, Secure and HttpOnly still prevent a
        // clearance from becoming a portable bearer token.
        $payload        = $this->binding($request, false);
        $payload['exp'] = time() + max(60, min(3600, $ttlSeconds));

        return $this->encrypt($payload);
    }

    /** @return array{token: string} */
    public function issueChallenge(Request $request, int $ttlSeconds = 90): array
    {
        $ttl               = max(30, min(120, $ttlSeconds));
        $payload           = $this->binding($request, true);
        $payload['id']     = bin2hex(random_bytes(32));
        $payload['exp']    = time() + $ttl;
        $payload['iat_ms'] = (int) floor(microtime(true) * 1000);
        $payload['target'] = $this->targetHash($request);
        Cache::put($this->challengeKey($payload['id']), $this->bindingHash($payload), now()->addSeconds($ttl));

        return [
            'token' => $this->encrypt($payload),
        ];
    }

    public function consumeChallenge(Request $request, string $token): bool
    {
        $payload = $this->decrypt($token);
        if ($payload === null
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($payload['id'] ?? '')) !== 1
            || (int) ($payload['exp'] ?? 0) < time()
            || (int) ($payload['exp'] ?? 0) > time() + 120
            || trim((string) ($payload['ip'] ?? '')) === ''
            || ! hash_equals($this->targetHash($request), (string) ($payload['target'] ?? ''))
            || ! $this->matchesRequest($payload, $request)) {
            return false;
        }

        $key    = $this->challengeKey((string) $payload['id']);
        $stored = Cache::get($key);
        if (! is_string($stored) || ! hash_equals($stored, $this->bindingHash($payload))) {
            return false;
        }

        // Cache::add is atomic on LONGOS production stores. It closes the
        // replay window even if two requests read the pending value together.
        if (! Cache::add($key . ':used', true, now()->addMinutes(2))) {
            return false;
        }
        Cache::forget($key);

        return true;
    }

    public function valid(Request $request): bool
    {
        $token = trim((string) $request->cookie(self::COOKIE, ''));
        if ($token === '') {
            return false;
        }

        try {
            $payload = $this->decrypt($token);
            if (! is_array($payload) || (int) ($payload['exp'] ?? 0) < time()) {
                return false;
            }

            return $this->matchesRequest($payload, $request);
        } catch (Throwable) {
            return false;
        }
    }

    private function binding(Request $request, bool $includeNetwork): array
    {
        $binding = [
            'tenant' => $this->settings->tenantKey(),
            'host'   => strtolower($request->getHost()),
            'ua'     => hash('sha256', (string) $request->userAgent()),
        ];
        if ($includeNetwork) {
            $binding['ip'] = $this->settings->ipHash(
                $this->networkBinding(IpAddress::normalize((string) $request->ip())),
            );
        }

        return $binding;
    }

    private function matchesRequest(array $payload, Request $request): bool
    {
        $binding = $this->binding($request, false);
        if (! hash_equals($binding['tenant'], (string) ($payload['tenant'] ?? ''))
            || ! hash_equals($binding['host'], (string) ($payload['host'] ?? ''))
            || ! hash_equals($binding['ua'], (string) ($payload['ua'] ?? ''))) {
            return false;
        }

        $payloadIp = trim((string) ($payload['ip'] ?? ''));
        if ($payloadIp === '') {
            return true;
        }

        $ip = IpAddress::normalize((string) $request->ip());

        // Accept both the current network-prefix challenge binding and the
        // exact-IP binding issued by older releases during a rolling deploy.
        return hash_equals($payloadIp, $this->settings->ipHash($this->networkBinding($ip)))
            || hash_equals($payloadIp, $this->settings->ipHash($ip));
    }

    private function bindingHash(array $payload): string
    {
        return hash_hmac('sha256', implode('|', [
            (string) ($payload['id'] ?? ''),
            (string) ($payload['tenant'] ?? ''),
            (string) ($payload['host'] ?? ''),
            (string) ($payload['ip'] ?? ''),
            (string) ($payload['ua'] ?? ''),
            (string) ($payload['exp'] ?? ''),
            (string) ($payload['iat_ms'] ?? ''),
            (string) ($payload['target'] ?? ''),
        ]), (string) config('app.key', 'longos-bot-shield'));
    }

    private function challengeKey(string $id): string
    {
        return 'longos:bot-shield:challenge:' . hash('sha256', $id);
    }

    private function targetHash(Request $request): string
    {
        return hash('sha256', strtolower($request->getHost()) . '|' . $request->getRequestUri());
    }

    private function networkBinding(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return 'invalid';
        }

        return match (strlen($packed)) {
            4       => 'v4:' . bin2hex(substr($packed, 0, 3)),
            16      => 'v6:' . bin2hex(substr($packed, 0, 7)),
            default => 'invalid',
        };
    }

    private function encrypt(array $payload): string
    {
        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function decrypt(string $token): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString(trim($token)), true, 8, JSON_THROW_ON_ERROR);

            return is_array($payload) ? $payload : null;
        } catch (Throwable) {
            return null;
        }
    }
}
