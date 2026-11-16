<?php

namespace Plugin\LongosBotShield\Middleware\Shop;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Plugin\LongosBotShield\Services\BotRiskEngine;
use Plugin\LongosBotShield\Services\BotShieldSettings;
use Plugin\LongosBotShield\Services\ClearanceToken;
use Plugin\LongosBotShield\Services\InternalHomepageRequest;
use Plugin\LongosBotShield\Services\MainSiteHomepage;
use Plugin\LongosBotShield\Services\RiskAssessment;
use Symfony\Component\HttpFoundation\Response;

class ProtectStorefront
{
    private const CHALLENGE_HEADER = 'X-Longos-Bot-Challenge-Proof';

    private const REFERENCE_ATTRIBUTE = '_longos_bot_shield_reference';

    public function __construct(
        private readonly BotRiskEngine $engine,
        private readonly BotShieldSettings $settings,
        private readonly ClearanceToken $clearance,
        private readonly InternalHomepageRequest $internalRequest,
        private readonly MainSiteHomepage $mainSiteHomepage,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isOperationalBypass($request) || $this->isCorsPreflight($request)) {
            return $next($request);
        }

        if ($this->internalRequest->valid($request)) {
            // Consume internal authentication material at the middleware
            // boundary so controllers, hooks, and application logs never see it.
            $request->headers->remove(InternalHomepageRequest::HEADER);

            return $next($request);
        }

        $mode           = $this->settings->enforcementMode();
        $challengeProof = trim((string) $request->header(self::CHALLENGE_HEADER));
        if ($mode === 'challenge' && $request->isMethod('GET') && $challengeProof !== '') {
            return $this->completeChallenge($request, $challengeProof);
        }

        $assessment = $this->engine->assess($request);
        if ($assessment->allowlisted) {
            $this->record($request, $assessment, 'allowlist');

            return $next($request);
        }

        if ($mode === 'homepage') {
            if (! $assessment->matchesConfiguredShieldingScope()) {
                return $next($request);
            }

            $this->record($request, $assessment, 'homepage');

            return $this->homepage($request, $assessment);
        }

        if (! $assessment->matchesEnforcementScope()) {
            return $next($request);
        }

        if ($mode === 'monitor') {
            $this->record($request, $assessment, 'monitor');

            return $next($request);
        }

        if ($assessment->verifiedCrawler && $this->settings->crawlerPolicy() === 'allow_verified') {
            $this->record($request, $assessment, 'verified-crawler');

            return $next($request);
        }

        if ($mode === 'challenge'
            && ! $assessment->requiresImmediateBlock()
            && $this->clearance->valid($request)) {
            $this->record($request, $assessment, 'clearance');

            return $next($request);
        }

        if ($mode === 'challenge'
            && ! $assessment->requiresImmediateBlock()
            && $request->isMethodCacheable()
            && ! $this->isStructuredRequest($request)) {
            $this->record($request, $assessment, 'challenge');

            return $this->challenge($request, $assessment);
        }

        $this->record($request, $assessment, 'block');

        return $this->blocked($request, $assessment);
    }

    private function homepage(Request $request, RiskAssessment $assessment): Response
    {
        $homepage = $this->mainSiteHomepage->render();
        if ($homepage === null) {
            return $this->blocked($request, $assessment);
        }

        $response = response($homepage['html'], 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $response->headers->set('X-Longos-Bot-Shield', 'homepage');
        $response->headers->set('X-Longos-Bot-Homepage-Source', $homepage['source']);
        $response->headers->set('X-Longos-Bot-Reference', $this->reference($request, $assessment));
        $response->headers->set('Content-Security-Policy', "sandbox; frame-ancestors 'none'");
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $this->harden($response);
        if ($request->isMethod('HEAD')) {
            $response->setContent('');
        }

        return $response;
    }

    private function challenge(Request $request, RiskAssessment $assessment): Response
    {
        $nonce     = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $challenge = $this->clearance->issueChallenge($request);
        $response  = response()->view('LongosBotShield::shop.challenge', [
            'challengeToken' => $challenge['token'],
            'cspNonce'       => $nonce,
            'reference'      => $this->reference($request, $assessment),
        ], 503);
        $response->headers->set('Retry-After', '3');
        $response->headers->set('X-Longos-Bot-Shield', 'challenge');
        $response->headers->set('Content-Security-Policy', $this->interactiveCsp($nonce));
        $this->harden($response);

        return $response;
    }

    private function completeChallenge(Request $request, string $proof): Response
    {
        $rateKey = 'longos:bot-shield:challenge-proof:' . hash(
            'sha256',
            $this->settings->tenantKey() . '|' . $this->settings->ipHash((string) $request->ip()),
        );
        if (RateLimiter::tooManyAttempts($rateKey, 20)) {
            return $this->challengeRejected(429);
        }
        RateLimiter::hit($rateKey, 60);

        if (! $this->clearance->consumeChallenge($request, $proof)) {
            return $this->challengeRejected(403);
        }

        $response = response('', 204);
        $response->withCookie(cookie(
            ClearanceToken::COOKIE,
            $this->clearance->issue($request),
            15,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));
        $response->headers->set('X-Longos-Bot-Shield', 'challenge-cleared');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        $this->harden($response);

        return $response;
    }

    private function challengeRejected(int $status): Response
    {
        $response = response()->json([
            'message' => 'Browser challenge proof is invalid, expired, or rate limited.',
        ], $status, ['Content-Type' => 'application/problem+json']);
        if ($status === 429) {
            $response->headers->set('Retry-After', '60');
        }
        $response->headers->set('X-Longos-Bot-Shield', 'challenge-rejected');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        $this->harden($response);

        return $response;
    }

    private function blocked(Request $request, RiskAssessment $assessment): Response
    {
        $rateLimited = $assessment->hasReason('rate_limit') || $assessment->hasReason('rate_burst');
        $status      = $rateLimited ? 429 : 403;
        $reference   = $this->reference($request, $assessment);
        if ($this->isStructuredRequest($request)) {
            $response = response()->json([
                'message'   => 'Request rejected by the storefront security policy.',
                'reference' => $reference,
            ], $status, ['Content-Type' => 'application/problem+json']);
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        } else {
            $nonce    = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $response = response()->view('LongosBotShield::shop.blocked', [
                'cspNonce'  => $nonce,
                'reference' => $reference,
            ], $status);
            $response->headers->set('Content-Security-Policy', $this->styleOnlyCsp($nonce));
        }
        if ($status === 429) {
            $response->headers->set('Retry-After', $assessment->hasReason('rate_burst') ? '10' : '60');
        }
        $response->headers->set('X-Longos-Bot-Shield', 'blocked');
        $this->harden($response);

        return $response;
    }

    private function harden(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()');
        $response->headers->set('Vary', 'User-Agent', false);
    }

    private function isStructuredRequest(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api') || $request->is('api/*');
    }

    private function isOperationalBypass(Request $request): bool
    {
        return $request->is('api/health');
    }

    private function isCorsPreflight(Request $request): bool
    {
        return $request->isMethod('OPTIONS')
            && trim((string) $request->header('Origin'))                        !== ''
            && trim((string) $request->header('Access-Control-Request-Method')) !== '';
    }

    private function interactiveCsp(string $nonce): string
    {
        return "default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
    }

    private function styleOnlyCsp(string $nonce): string
    {
        return "default-src 'none'; style-src 'nonce-{$nonce}'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
    }

    private function reference(Request $request, RiskAssessment $assessment): string
    {
        $existing = $request->attributes->get(self::REFERENCE_ATTRIBUTE);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $reference = strtoupper(substr(hash_hmac(
            'sha256',
            implode('|', [
                $this->settings->tenantKey(),
                $this->settings->ipHash((string) $request->ip()),
                implode(',', $assessment->reasons),
                (string) floor(time() / 600),
            ]),
            (string) config('app.key', 'longos-bot-shield'),
        ), 0, 12));
        $request->attributes->set(self::REFERENCE_ATTRIBUTE, $reference);

        return $reference;
    }

    private function record(Request $request, RiskAssessment $assessment, string $action): void
    {
        if (! $this->settings->diagnosticLog()) {
            return;
        }

        Log::notice('LONGOS Bot Shield evaluated a storefront request.', [
            'tenant'    => $this->settings->tenantKey(),
            'ip_hash'   => substr($this->settings->ipHash((string) $request->ip()), 0, 20),
            'method'    => $request->getMethod(),
            'reference' => $this->reference($request, $assessment),
            'signals'   => count($assessment->reasons),
            'reasons'   => $assessment->reasons,
            'action'    => $action,
        ]);
    }
}
