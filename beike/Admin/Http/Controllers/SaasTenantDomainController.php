<?php

namespace Beike\Admin\Http\Controllers;

use App\Http\Middleware\TrustProxies;
use App\Saas\CaddyEdgeStatus;
use App\Saas\DomainVerificationService;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\SiteLifecycleService;
use App\Saas\Support\SensitiveValueRedactor;
use App\Saas\TenantManager;
use Beike\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class SaasTenantDomainController extends Controller
{
    public function page(TenantManager $tenants): View
    {
        $this->authorizeTenant($tenants);

        return view('admin::pages.saas.domains');
    }

    public function index(Request $request, TenantManager $tenants, DomainVerificationService $verification): JsonResponse
    {
        $site               = $this->authorizeTenant($tenants);
        $tlsDriver          = strtolower((string) config('saas.tls.driver', 'caddy'));
        $automaticTls       = $tlsDriver === 'caddy';
        $caddyObserved      = $request->attributes->getBoolean(TrustProxies::CADDY_REQUEST_ATTRIBUTE);
        $edgePlatform       = CaddyEdgeStatus::platform(config('saas.edge.platform', 'coolify'));
        $edgeWarning        = CaddyEdgeStatus::warning(
            $automaticTls,
            $caddyObserved,
            $edgePlatform,
        );

        return response()->json([
            'site'        => ['id' => $site->id, 'code' => $site->code, 'name' => $site->name],
            'dns_targets' => (array) config('saas.dns_targets', []),
            'base_domain' => (string) config('saas.base_domain', ''),
            'tls'         => [
                'driver'    => $tlsDriver,
                'automatic' => $automaticTls,
                'message'   => $automaticTls
                    ? 'Coolify Caddy 会为主站、平台子域及已验证的租户自定义域名自动签发和续期证书。'
                    : '外部 HTTPS 模式必须真实绑定 Host 并部署有效证书，LONGOS 探测成功后才会启用。',
            ],
            'edge'        => [
                'observed' => $caddyObserved,
                'healthy'  => CaddyEdgeStatus::healthy(
                    $automaticTls,
                    $caddyObserved,
                    $edgePlatform,
                ),
                'mode'     => $edgePlatform,
                'reason'   => null,
                'warning'  => $edgeWarning,
            ],
            'cdn'         => [
                'provider' => strtolower((string) config('longos.cloudflare.mode', 'optional')) === 'off' ? null : 'cloudflare',
                'mode'     => (string) config('longos.cloudflare.mode', 'optional'),
                'message'  => match (strtolower((string) config('longos.cloudflare.mode', 'optional'))) {
                    'required' => 'Cloudflare 严格回源已启用；DNS 记录必须开启代理（橙色云），SSL/TLS 必须使用 Full (strict)。',
                    'off'      => '当前未启用 Cloudflare CDN 感知。',
                    default    => '支持 Cloudflare CDN；可开启代理（橙色云），SSL/TLS 请使用 Full (strict)。',
                },
            ],
            'domains'     => $site->domains()->orderByDesc('is_primary')->orderBy('id')->get()
                ->map(fn (Domain $domain): array => $this->present($domain, $verification))->all(),
        ]);
    }

    public function updateSubdomain(
        Request $request,
        TenantManager $tenants,
        SiteLifecycleService $lifecycle,
        DomainVerificationService $verification,
    ): JsonResponse {
        $site      = $this->authorizeTenant($tenants);
        $validated = $request->validate(['subdomain' => ['required', 'string', 'max:63']]);

        return $this->operation(
            fn (): array => $this->present($lifecycle->changeSubdomain($site, $validated['subdomain']), $verification)
        );
    }

    public function store(Request $request, TenantManager $tenants, DomainVerificationService $verification): JsonResponse
    {
        $site      = $this->authorizeTenant($tenants);
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
        ]);

        return $this->operation(function () use ($site, $validated, $verification): array {
            $domain = $verification->request($site, $validated['domain']);

            return $this->present($domain, $verification);
        }, 201);
    }

    public function verify(
        Request $request,
        TenantManager $tenants,
        DomainVerificationService $verification,
        SiteLifecycleService $lifecycle,
        Domain $domain,
    ): JsonResponse {
        $site = $this->authorizeTenant($tenants);
        $this->authorizeDomain($site, $domain);
        $validated = $request->validate(['primary' => ['sometimes', 'boolean']]);

        return $this->operation(function () use ($site, $domain, $validated, $verification, $lifecycle): array {
            $verified = $verification->verify($site, $domain);
            if ((bool) ($validated['primary'] ?? false) && ! $verified->is_primary) {
                $verified = $lifecycle->makePrimary($site, $verified);
            }

            return $this->present($verified, $verification);
        });
    }

    public function rotate(
        Request $request,
        TenantManager $tenants,
        DomainVerificationService $verification,
        Domain $domain,
    ): JsonResponse {
        $site = $this->authorizeTenant($tenants);
        $this->authorizeDomain($site, $domain);

        return $this->operation(fn (): array => $this->present($verification->rotate($site, $domain), $verification));
    }

    public function makePrimary(
        Request $request,
        TenantManager $tenants,
        SiteLifecycleService $lifecycle,
        DomainVerificationService $verification,
        Domain $domain,
    ): JsonResponse {
        $site = $this->authorizeTenant($tenants);
        $this->authorizeDomain($site, $domain);

        return $this->operation(fn (): array => $this->present($lifecycle->makePrimary($site, $domain), $verification));
    }

    public function updateRouting(
        Request $request,
        TenantManager $tenants,
        SiteLifecycleService $lifecycle,
        DomainVerificationService $verification,
        Domain $domain,
    ): JsonResponse {
        $site = $this->authorizeTenant($tenants);
        $this->authorizeDomain($site, $domain);
        $validated = $request->validate(['mode' => ['required', 'in:redirect,alias']]);

        return $this->operation(fn (): array => $this->present(
            $lifecycle->setDomainRouting($site, $domain, $validated['mode']),
            $verification,
        ));
    }

    public function destroy(
        Request $request,
        TenantManager $tenants,
        SiteLifecycleService $lifecycle,
        Domain $domain,
    ): JsonResponse {
        $site = $this->authorizeTenant($tenants);
        $this->authorizeDomain($site, $domain);

        return $this->operation(function () use ($site, $domain, $lifecycle): null {
            $lifecycle->removeDomain($site, $domain);

            return null;
        });
    }

    private function authorizeTenant(TenantManager $tenants): Site
    {
        abort_unless(config('saas.enabled') && config('saas.custom_domains.enabled', true), 404);
        abort_unless(Auth::guard(AdminUser::AUTH_GUARD)->user()?->is_root, 403);
        $site = $tenants->current();
        abort_unless($site?->status === Site::STATUS_ACTIVE, 404);

        return $site;
    }

    private function authorizeDomain(Site $site, Domain $domain): void
    {
        abort_unless($domain->site_id === $site->id, 404);
    }

    /** @return array<string, mixed> */
    private function present(Domain $domain, DomainVerificationService $verification): array
    {
        $operational     = $domain->isRoutable();
        $effectiveStatus = $domain->publication_error !== null && ! $operational
            ? 'publication_failed'
            : $domain->status;

        return [
            'id'                    => $domain->id,
            'host'                  => $domain->host,
            'scheme'                => $domain->scheme,
            'status'                => $domain->status,
            'effective_status'      => $effectiveStatus,
            'operational'           => $operational,
            'origin'                => $domain->origin,
            'is_primary'            => $domain->is_primary,
            'routing_type'          => $domain->routing_type,
            'verified_at'           => $domain->verified_at?->toIso8601String(),
            'last_checked_at'       => $domain->last_checked_at?->toIso8601String(),
            'verification_attempts' => $domain->verification_attempts,
            'verification_error'    => $domain->verification_error,
            'published_at'          => $domain->published_at?->toIso8601String(),
            'last_probe_at'         => $domain->last_probe_at?->toIso8601String(),
            'publication_error'     => $domain->publication_error,
            'verification'          => $domain->status === Domain::STATUS_PENDING && is_string($domain->verification_token)
                ? $verification->instructions($domain)
                : null,
            'capabilities' => [
                'verify'       => $domain->status === Domain::STATUS_PENDING,
                'rotate'       => $domain->origin === Domain::ORIGIN_TENANT && $domain->status !== Domain::STATUS_ACTIVE,
                'make_primary' => $operational                              && ! $domain->is_primary,
                'set_routing'  => $operational                              && ! $domain->is_primary,
                'remove'       => ! ($domain->origin === Domain::ORIGIN_PLATFORM
                    && config('saas.domains.retain_platform_domain', true)),
            ],
            'tls' => [
                'automatic' => $domain->scheme === 'https' && config('saas.tls.driver') === 'caddy',
                'mode'      => (string) config('saas.tls.driver', 'caddy'),
                'ready'     => $operational && $domain->published_at !== null,
            ],
        ];
    }

    private function operation(callable $callback, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['data' => $callback()], $status);
        } catch (Throwable $exception) {
            return response()->json(['message' => SensitiveValueRedactor::redact($exception->getMessage())], 422);
        }
    }
}
