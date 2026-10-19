<?php

namespace App\Saas\Tasks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantDatabaseTask;

class SwitchDefaultTenantDatabaseTask extends SwitchTenantDatabaseTask
{
    private readonly string $originalConnection;

    private readonly string $originalPrefix;

    public function __construct()
    {
        $this->originalConnection = (string) config('database.default');
        $this->originalPrefix     = (string) config(
            'database.connections.' . $this->tenantDatabaseConnectionName() . '.prefix',
            '',
        );
    }

    public function makeCurrent(IsTenant $tenant): void
    {
        $prefix = method_exists($tenant, 'getDatabasePrefix')
            ? (string) $tenant->getDatabasePrefix()
            : '';
        config([
            'database.connections.' . $this->tenantDatabaseConnectionName() . '.prefix' => $prefix,
        ]);

        try {
            parent::makeCurrent($tenant);
        } catch (\Throwable $exception) {
            config([
                'database.connections.' . $this->tenantDatabaseConnectionName() . '.prefix' => $this->originalPrefix,
            ]);

            throw $exception;
        }

        config(['database.default' => $this->tenantDatabaseConnectionName()]);
        DB::setDefaultConnection($this->tenantDatabaseConnectionName());
        Model::setConnectionResolver(app('db'));
    }

    public function forgetCurrent(): void
    {
        config([
            'database.connections.' . $this->tenantDatabaseConnectionName() . '.prefix' => $this->originalPrefix,
        ]);
        parent::forgetCurrent();

        config(['database.default' => $this->originalConnection]);
        DB::setDefaultConnection($this->originalConnection);
        Model::setConnectionResolver(app('db'));
    }
}
