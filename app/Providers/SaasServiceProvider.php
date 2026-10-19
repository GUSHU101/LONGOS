<?php

namespace App\Providers;

use App\Saas\Contracts\DnsRouteResolver;
use App\Saas\Contracts\DnsTxtResolver;
use App\Saas\Contracts\DomainTrafficProbe;
use App\Saas\Database\TenantBlueprint;
use App\Saas\Dns\NativeDnsRouteResolver;
use App\Saas\Dns\NativeDnsTxtResolver;
use App\Saas\DomainConfigWriter;
use App\Saas\DomainVerificationService;
use App\Saas\HttpDomainTrafficProbe;
use App\Saas\IdempotencyService;
use App\Saas\SiteAuditLogger;
use App\Saas\SiteDeletionService;
use App\Saas\SiteHealthChecker;
use App\Saas\SiteLifecycleService;
use App\Saas\SiteProvisioner;
use App\Saas\TenantManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class SaasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('saas.php'), 'saas');
        $this->configureConnections();

        // Laravel creates a fresh schema builder for every Schema facade call.
        // Binding the blueprint here keeps tenant-prefixed index/constraint
        // names isolated on PostgreSQL and SQLite as well as MySQL.
        $this->app->bind(Blueprint::class, TenantBlueprint::class);

        $this->app->singleton(TenantManager::class);
        $this->app->singleton(DnsTxtResolver::class, NativeDnsTxtResolver::class);
        $this->app->singleton(DnsRouteResolver::class, NativeDnsRouteResolver::class);
        $this->app->singleton(DomainTrafficProbe::class, HttpDomainTrafficProbe::class);
        $this->app->singleton(DomainConfigWriter::class);
        $this->app->singleton(DomainVerificationService::class);
        $this->app->singleton(IdempotencyService::class);
        $this->app->singleton(SiteProvisioner::class);
        $this->app->singleton(SiteAuditLogger::class);
        $this->app->singleton(SiteHealthChecker::class);
        $this->app->singleton(SiteLifecycleService::class);
        $this->app->singleton(SiteDeletionService::class);
    }

    public function boot(): void
    {
        // The package service provider owns request discovery and queue
        // propagation. LONGOS only supplies its finder and switch tasks.
    }

    private function configureConnections(): void
    {
        $central = $this->connectionWithOverrides(
            (string) config('saas.central_connection', config('database.default')),
            (array) config('saas.central', []),
        );
        $tenantOverrides = (array) config('saas.tenant', []);
        unset($tenantOverrides['grant_host']);
        $tenant = $this->connectionWithOverrides(
            (string) config('saas.tenant_connection', config('database.default')),
            $tenantOverrides,
        );
        $tenantAdmin        = $this->applyOverrides($tenant, (array) config('saas.tenant_admin', []));
        $tenant['database'] = null;
        $tenant['url']      = null;

        config([
            'database.connections.saas'         => $central,
            'database.connections.tenant'       => $tenant,
            'database.connections.tenant_admin' => $tenantAdmin,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function connectionWithOverrides(string $baseName, array $overrides): array
    {
        $connection = (array) config("database.connections.{$baseName}", []);

        return $this->applyOverrides($connection, $overrides);
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $overrides
     */
    private function applyOverrides(array $connection, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if ($value !== null && $value !== '') {
                $connection[$key] = $value;
            }
        }

        if (array_filter($overrides, static fn ($value): bool => $value !== null && $value !== '')) {
            $connection['url'] = null;
        }

        return $connection;
    }
}
