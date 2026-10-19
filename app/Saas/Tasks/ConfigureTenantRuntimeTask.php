<?php

namespace App\Saas\Tasks;

use App\Saas\Models\Site;
use App\Saas\Support\TenantRuntimeKey;
use App\Saas\TenantRuntimeState;
use Beike\Services\TimezoneService;
use Beike\Shop\Providers\ShopServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;
use Throwable;

class ConfigureTenantRuntimeTask implements SwitchTenantTask
{
    private readonly string $originalUrl;

    private readonly string $originalSessionCookie;

    private readonly ?string $originalSessionDomain;

    private readonly string $originalSessionFiles;

    private readonly string $originalCatalogRoot;

    private readonly string $originalUploadRoot;

    private readonly string $originalTimezone;

    public function __construct()
    {
        $this->originalUrl           = (string) config('app.url', '');
        $this->originalSessionCookie = (string) config('session.cookie', 'longos_session');
        $this->originalSessionDomain = config('session.domain');
        $this->originalSessionFiles  = (string) config('session.files', storage_path('framework/sessions'));
        $this->originalCatalogRoot   = (string) config('filesystems.disks.catalog.root', public_path('image/catalog'));
        $this->originalUploadRoot    = (string) config('filesystems.disks.upload.root', public_path('upload'));
        $this->originalTimezone      = (string) config('app.timezone', 'UTC');
    }

    public function makeCurrent(IsTenant $tenant): void
    {
        if (! $tenant instanceof Site) {
            throw new RuntimeException('LONGOS SaaS 租户必须使用 Site 模型。');
        }

        try {
            app()->instance('saas.current_site', $tenant);
            $isolated     = (bool) data_get($tenant->meta, 'isolate_files', config('saas.isolate_files', true));
            $catalog      = $isolated ? 'image/catalog/tenants/' . $tenant->code : 'image/catalog';
            $upload       = $isolated ? 'upload/tenants/' . $tenant->code : 'upload';
            $primaryUrl   = $tenant->primary_url ?: $this->originalUrl;
            $timezone     = TimezoneService::normalize($tenant->timezone, $this->originalTimezone);
            $sessionFiles = TenantRuntimeKey::sessionDirectory($this->originalSessionFiles, $tenant->code);
            app(Filesystem::class)->ensureDirectoryExists($sessionFiles);

            config([
                'app.url'                        => $primaryUrl,
                'app.timezone'                   => $timezone,
                'session.cookie'                 => TenantRuntimeKey::sessionCookie(
                    $this->originalSessionCookie,
                    $tenant->uuid ?: $tenant->code,
                ),
                // Host-only cookies and per-tenant file storage prevent a
                // session id from being replayed across platform/custom hosts.
                'session.domain'                 => null,
                'session.files'                  => $sessionFiles,
                'filesystems.disks.catalog.root' => public_path($catalog),
                'filesystems.disks.upload.root'  => public_path($upload),
            ]);

            TimezoneService::apply($timezone, $this->originalTimezone);
            $this->forgetRuntimeDrivers();
            TenantRuntimeState::reset();
            if ($primaryUrl !== '') {
                URL::forceRootUrl($primaryUrl);
                URL::forceScheme(str_starts_with($primaryUrl, 'http://') ? 'http' : 'https');
            }

            // Provisioning switches to a freshly-created, empty database
            // before migrations. Runtime settings become available only after
            // the core schema exists.
            if (DB::connection('tenant')->getSchemaBuilder()->hasTable('settings')) {
                (new ShopServiceProvider(app()))->refreshTenantConfiguration($tenant);
            }
        } catch (Throwable $exception) {
            $this->forgetCurrent();

            throw $exception;
        }
    }

    public function forgetCurrent(): void
    {
        config([
            'app.url'                        => $this->originalUrl,
            'app.timezone'                   => $this->originalTimezone,
            'session.cookie'                 => $this->originalSessionCookie,
            'session.domain'                 => $this->originalSessionDomain,
            'session.files'                  => $this->originalSessionFiles,
            'filesystems.disks.catalog.root' => $this->originalCatalogRoot,
            'filesystems.disks.upload.root'  => $this->originalUploadRoot,
        ]);

        app()->forgetInstance('saas.current_site');
        TimezoneService::apply($this->originalTimezone, 'UTC');
        $this->forgetRuntimeDrivers();
        TenantRuntimeState::reset();
        URL::forceRootUrl($this->originalUrl !== '' ? $this->originalUrl : null);
        $scheme = $this->originalUrl !== '' ? parse_url($this->originalUrl, PHP_URL_SCHEME) : null;
        URL::forceScheme(is_string($scheme) && $scheme !== '' ? $scheme : null);

        (new ShopServiceProvider(app()))->resetTenantConfiguration();
    }

    private function forgetRuntimeDrivers(): void
    {
        if (app()->resolved('session')) {
            app('session')->forgetDrivers();
        }
        if (app()->resolved('auth')) {
            app('auth')->forgetGuards();
        }
        if (app()->resolved('filesystem')) {
            app('filesystem')->forgetDisk(['catalog', 'upload']);
        }
    }
}
