<?php

namespace App\Http\Middleware;

use App\Saas\TenantFinder\DomainTenantFinder;
use App\Saas\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantResolved
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isInternalDomainEndpoint($request)) {
            return $next($request);
        }

        if (is_installer() || ! installed() || ! config('saas.enabled')) {
            return $next($request);
        }

        // Spatie resolves ordinary HTTP requests. The fallback keeps custom
        // kernels and long-lived workers safe when the package middleware is
        // not part of their request pipeline.
        if (! $this->tenants->resolved()
            && ! (bool) $request->attributes->get(DomainTenantFinder::REQUEST_ATTRIBUTE, false)) {
            $this->tenants->initializeFromRequest($request);
        }

        abort_unless(
            $this->tenants->controlTablesReady() && $this->tenants->hasSites(),
            503,
            'SaaS 控制平面尚未就绪，请检查控制库连接、迁移和首站注册状态。',
        );

        // An unknown Host must never fall through to the application's
        // original/default database in production. The opt-out exists only
        // for local migration diagnostics before DNS is ready.
        if (! config('saas.reject_unknown') && app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        abort_unless($this->tenants->resolved(), 404, '站点不存在、未启用或域名尚未绑定。');

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (is_installer() || ! installed() || ! config('saas.enabled')) {
            return;
        }

        if ($this->tenants->resolved()) {
            $this->tenants->deactivate();
        }

        // The configured switch tasks restore every mutable runtime value.
    }

    private function isInternalDomainEndpoint(Request $request): bool
    {
        return $request->is(
            ltrim((string) config('saas.tls.ask_path', '/_longos/tls/authorize'), '/'),
            ltrim((string) config('saas.custom_domains.probe_path', '/_longos/domain/probe'), '/'),
        );
    }
}
