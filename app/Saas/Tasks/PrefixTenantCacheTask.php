<?php

namespace App\Saas\Tasks;

use Spatie\Multitenancy\Tasks\PrefixCacheTask;

/**
 * Retains the application's cache namespace before adding the tenant key.
 * This prevents tenant id 1 in two LONGOS deployments from sharing keys when
 * both applications use the same Redis or Memcached service.
 */
class PrefixTenantCacheTask extends PrefixCacheTask
{
    private readonly string $originalFilePath;

    public function __construct()
    {
        $applicationPrefix      = trim((string) config('cache.prefix', ''), '_');
        $tenantPrefix           = ($applicationPrefix !== '' ? $applicationPrefix . '_' : '') . 'tenant_';
        $filePath               = config('cache.stores.file.path');
        $this->originalFilePath = is_string($filePath) && $filePath !== ''
            ? $filePath
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-cache';

        parent::__construct(cacheKeyBase: $tenantPrefix);
    }

    public function makeCurrent(\Spatie\Multitenancy\Contracts\IsTenant $tenant): void
    {
        // Laravel's FileStore does not consume cache.prefix. Isolate its
        // physical directory as well so installations that use the default
        // file cache cannot leak keys between storefronts.
        config()->set(
            'cache.stores.file.path',
            rtrim($this->originalFilePath, '/\\') . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenant->getKey(),
        );

        parent::makeCurrent($tenant);
    }

    public function forgetCurrent(): void
    {
        config()->set('cache.stores.file.path', $this->originalFilePath);

        parent::forgetCurrent();
    }
}
