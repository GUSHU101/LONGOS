<?php

namespace Plugin\LongosBotShield\Middleware\Shop;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugin\LongosBotShield\Services\BotRiskEngine;
use Plugin\LongosBotShield\Services\BotShieldSettings;
use Plugin\LongosBotShield\Services\InternalHomepageRequest;
use Plugin\LongosBotShield\Services\MainSiteHomepage;
use Plugin\LongosBotShield\Services\RiskAssessment;
use Symfony\Component\HttpFoundation\Response;

class ProtectStorefront
{
    private const REFERENCE_ATTRIBUTE = '_longos_bot_shield_reference';

    public function __construct(
        private readonly BotRiskEngine $engine,
        private readonly BotShieldSettings $settings,
        private readonly InternalHomepageRequest $internalRequest,
        private readonly MainSiteHomepage $mainSiteHomepage,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isOperationalBypass($request)
            || $this->isPaymentContinuationBypass($request)
            || $this->isCorsPreflight($request)) {
            return $next($request);
        }

        if ($this->internalRequest->valid($request)) {
            // Consume internal authentication material at the middleware
            // boundary so controllers, hooks, and application logs never see it.
            $request->headers->remove(InternalHomepageRequest::HEADER);

            return $this->passThrough($request, $next);
        }

        if ($this->isPresentationAsset($request) && ! $this->settings->blockAllTraffic()) {
            return $next($request);
        }

        $assessment = $this->engine->assess($request);
        if ($assessment->allowlisted) {
            $this->record($request, $assessment, 'allowlist');

            return $this->passThrough($request, $next);
        }

        if (! $assessment->matchesConfiguredShieldingScope()) {
            return $this->passThrough($request, $next);
        }

        if ($assessment->hasReason('global_deny')) {
            $this->record($request, $assessment, 'global-homepage');

            return $this->homepage($request, $assessment);
        }

        $this->record($request, $assessment, 'homepage');

        return $this->homepage($request, $assessment);
    }

    private function homepage(Request $request, RiskAssessment $assessment): Response
    {
        $homepage = $this->mainSiteHomepage->renderFor($request);
        if ($homepage === null) {
            return $this->unavailable($request, $assessment);
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

    private function unavailable(Request $request, RiskAssessment $assessment): Response
    {
        $reference = $this->reference($request, $assessment);
        if ($this->isStructuredRequest($request)) {
            $response = response()->json([
                'message'   => 'The protected homepage is temporarily unavailable.',
                'reference' => $reference,
            ], 503, ['Content-Type' => 'application/problem+json']);
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        } else {
            $nonce    = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $response = response()->view('LongosBotShield::shop.unavailable', [
                'cspNonce'  => $nonce,
                'reference' => $reference,
            ], 503);
            $response->headers->set('Content-Security-Policy', $this->styleOnlyCsp($nonce));
        }
        $response->headers->set('Retry-After', '5');
        $response->headers->set('X-Longos-Bot-Shield', 'unavailable');
        $this->harden($response);

        return $response;
    }

    private function harden(Response $response): void
    {
        $this->preventSharedCaching($response);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()');
        $response->headers->set('Vary', 'User-Agent', false);
    }

    private function passThrough(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->isMethodCacheable() && $this->isHtmlResponse($response)) {
            // The decision varies by verified visitor IP, country, ASN and
            // browser signals. A shared HTML cache would bypass this
            // middleware entirely and could serve a previously cached tenant
            // page to an in-scope visitor. Static assets retain normal caching.
            $this->preventSharedCaching($response);
        }

        return $response;
    }

    private function isHtmlResponse(Response $response): bool
    {
        return str_contains(
            strtolower((string) $response->headers->get('Content-Type')),
            'text/html',
        );
    }

    private function preventSharedCaching(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');
        $response->headers->set('Surrogate-Control', 'no-store');
    }

    private function isStructuredRequest(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api') || $request->is('api/*');
    }

    private function isOperationalBypass(Request $request): bool
    {
        return $request->is('api/health');
    }

    private function isPaymentContinuationBypass(Request $request): bool
    {
        // Provider callbacks validate their own signed/order-bound payloads.
        // They must remain reachable when a browser returns via cross-site
        // POST (no SameSite=Lax cookie) or a provider webhook originates from
        // a cloud ASN/script client. User-initiated create/capture endpoints
        // are deliberately not bypassed.
        if ($request->isMethod('POST')
            && ($request->is('callback/stripe') || $request->is('paypal/nvp/notify'))) {
            return true;
        }

        return $request->is('paypal/rest/return')
            || $request->is('paypal/rest/cancel')
            || $request->is('paypal/nvp/return')
            || $request->is('paypal/nvp/cancel');
    }

    private function isCorsPreflight(Request $request): bool
    {
        return $request->isMethod('OPTIONS')
            && trim((string) $request->header('Origin'))                        !== ''
            && trim((string) $request->header('Access-Control-Request-Method')) !== '';
    }

    private function isPresentationAsset(Request $request): bool
    {
        if (! $request->isMethodCacheable()) {
            return false;
        }

        $path = strtolower($request->getPathInfo());

        return preg_match(
            '/\.(?:avif|bmp|css|eot|gif|ico|jpe?g|js|mjs|mp4|ogg|png|svg|webm|webp|woff2?|ttf)(?:$|\/)/D',
            $path,
        ) === 1;
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
