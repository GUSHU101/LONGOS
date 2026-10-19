<?php

namespace App\Saas;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\Support\DomainName;
use App\Saas\TenantFinder\DomainTenantFinder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Throwable;

/**
 * Compatibility facade for LONGOS services.
 *
 * Tenant activation is delegated to Spatie's IsTenant lifecycle and the
 * configured switch tasks. Keeping this facade avoids coupling the rest of
 * the SaaS application layer to a specific tenancy package API.
 */
class TenantManager
{
    private ?bool $controlTablesReady = null;

    public function __construct(private readonly DatabaseManager $database) {}

    public function initializeFromRequest(Request $request): ?Site
    {
        if (! config('saas.enabled') || ! $this->controlTablesReady()) {
            Site::forgetCurrent();

            return null;
        }

        try {
            $host = DomainName::normalize($request->getHost());
        } catch (Throwable) {
            Site::forgetCurrent();

            return null;
        }

        $current = Site::current();
        if ($current) {
            $matchesCurrent = $current->status === Site::STATUS_ACTIVE
                && $current->domains()
                    ->where('host', $host)
                    ->routable()
                    ->exists();
            if ($matchesCurrent) {
                return $current;
            }

            Site::forgetCurrent();
        }

        $domain = Domain::query()
            ->with('site.domains')
            ->where('host', $host)
            ->routable()
            ->first();

        if (! $domain || ! $domain->site || $domain->site->status !== Site::STATUS_ACTIVE) {
            return null;
        }

        $domain->site->makeCurrent();

        return $domain->site;
    }

    public function activate(Site $site): void
    {
        $site->makeCurrent();
    }

    public function deactivate(): void
    {
        Site::forgetCurrent();
    }

    public function run(Site $site, callable $callback): mixed
    {
        return $site->execute($callback);
    }

    public function current(): ?Site
    {
        return Site::current();
    }

    public function resolved(): bool
    {
        return Site::checkCurrent();
    }

    public function controlTablesReady(): bool
    {
        if ($this->controlTablesReady === true) {
            return true;
        }

        try {
            $schema = $this->database->connection('saas')->getSchemaBuilder();

            $ready = $schema->hasTable('saas_sites') && $schema->hasTable('saas_domains');
            if ($ready) {
                $this->controlTablesReady = true;
            }

            return $ready;
        } catch (Throwable) {
            // Do not cache a transient database outage for the lifetime of an
            // Octane or queue worker.
            return false;
        }
    }

    public function hasSites(): bool
    {
        if (! $this->controlTablesReady()) {
            return false;
        }

        try {
            return Site::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function isControlHost(string $host): bool
    {
        $current = Site::current();
        if (! (bool) data_get($current?->meta, 'control_site', false)) {
            return false;
        }

        try {
            $host = DomainName::normalize($host);
        } catch (Throwable) {
            return false;
        }

        $configured = [];
        foreach ((array) config('saas.control_domains', []) as $domain) {
            try {
                $configured[] = DomainName::normalize((string) $domain);
            } catch (Throwable) {
                continue;
            }
        }

        if ($configured !== []) {
            return in_array($host, $configured, true);
        }

        return $current?->domains()
            ->where('host', $host)
            ->routable()
            ->where('is_primary', true)
            ->exists() ?? false;
    }

    /** @return array<int, string> */
    public function trustedHostPatterns(?string $requestedHost = null): array
    {
        if (! $this->controlTablesReady()) {
            return [];
        }

        try {
            $requestedHost = DomainName::normalize($requestedHost ?? request()->getHost());
        } catch (Throwable) {
            return [];
        }

        $current = Site::current();
        if ($current?->status === Site::STATUS_ACTIVE
            && $current->relationLoaded('domains')
            && $current->domains->contains(static fn (Domain $domain): bool => $domain->host === $requestedHost
                && $domain->isRoutable())) {
            return ['^' . preg_quote($requestedHost, '/') . '$'];
        }

        // The package finder has already performed an indexed domain lookup
        // during application boot. A missing current tenant means it was not
        // trusted, so avoid issuing the same query again in TrustHosts.
        if ((bool) request()->attributes->get(DomainTenantFinder::REQUEST_ATTRIBUTE, false)) {
            return [];
        }

        $trusted = Domain::query()
            ->where('host', $requestedHost)
            ->routable()
            ->whereHas('site', static fn ($query) => $query->where('status', Site::STATUS_ACTIVE))
            ->exists();

        return $trusted ? ['^' . preg_quote($requestedHost, '/') . '$'] : [];
    }
}
