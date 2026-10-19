<?php

namespace App\Saas\TenantFinder;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\Support\DomainName;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;
use Throwable;

class DomainTenantFinder extends TenantFinder
{
    public const REQUEST_ATTRIBUTE = '_saas_tenant_lookup_complete';

    public function findForRequest(Request $request): ?IsTenant
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, true);

        $path = '/' . ltrim($request->path(), '/');

        // Installation always runs on the control database and owns an
        // isolated session. A half-finished previous attempt can leave
        // SAAS_ENABLED=true and control rows behind; resolving that site here
        // would switch the DB and suffix the session cookie before the
        // installer middleware runs.
        if ($path === '/installer' || str_starts_with($path, '/installer/')
                                   || ! installed() || ! config('saas.enabled')) {
            return null;
        }

        try {
            $schema = app('db')->connection('saas')->getSchemaBuilder();
            if (! $schema->hasTable('saas_sites') || ! $schema->hasTable('saas_domains')) {
                return null;
            }

            $host   = DomainName::normalize($request->getHost());
            $domain = Domain::query()
                ->with('site.domains')
                ->where('host', $host)
                ->routable()
                ->first();

            return $domain?->site?->status === Site::STATUS_ACTIVE ? $domain->site : null;
        } catch (Throwable) {
            // Installation and migrations must remain usable before the
            // landlord tables or database connection are available.
            return null;
        }
    }
}
