<?php

namespace App\Saas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;
use Spatie\Multitenancy\Concerns\UsesMultitenancyConfig;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;

class Site extends Model implements IsTenant
{
    use ImplementsTenant;
    use UsesMultitenancyConfig;

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_READY = 'ready';

    public const STATUS_ACTIVATING = 'activating';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETING = 'deleting';

    protected $connection = 'saas';

    protected $table = 'saas_sites';

    protected $fillable = [
        'uuid', 'code', 'name', 'database_name', 'status', 'locale',
        'timezone', 'meta', 'provisioned_at', 'health_status', 'health_checked_at',
        'provisioning_token', 'provisioning_heartbeat_at',
    ];

    protected $casts = [
        'meta'                      => 'array',
        'provisioned_at'            => 'datetime',
        'health_checked_at'         => 'datetime',
        'provisioning_heartbeat_at' => 'datetime',
    ];

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function mailSenderDomains(): HasMany
    {
        return $this->hasMany(MailSenderDomain::class);
    }

    public function primaryDomain(): HasOne
    {
        return $this->hasOne(Domain::class)
            ->where('is_primary', true)
            ->routable();
    }

    public function events(): HasMany
    {
        return $this->hasMany(SiteEvent::class);
    }

    public function getDatabaseName(): string
    {
        if ($this->usesSharedDatabase()) {
            $database = trim((string) config('saas.shared_database', ''));
            if ($database === '') {
                throw new RuntimeException('共享数据库模式未配置 SAAS_SHARED_DATABASE 或 DB_DATABASE。');
            }

            return $database;
        }

        return $this->database_name;
    }

    public function getDatabasePrefix(): string
    {
        if (! $this->usesSharedDatabase()) {
            return '';
        }

        $prefix = (string) data_get($this->meta, 'database_prefix', '');
        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/', $prefix)) {
            throw new RuntimeException("站点 {$this->code} 的租户表前缀无效。");
        }

        return $prefix;
    }

    public function usesSharedDatabase(): bool
    {
        return data_get($this->meta, 'database_mode') === 'prefix';
    }

    public function getPrimaryUrlAttribute(): ?string
    {
        $domain = $this->relationLoaded('domains')
            ? $this->domains->first(static fn (Domain $domain): bool => $domain->is_primary && $domain->isRoutable())
                ?? $this->domains->first(static fn (Domain $domain): bool => $domain->isRoutable())
            : $this->domains()->routable()->where('is_primary', true)->first()
                ?? $this->domains()->routable()->first();

        return $domain ? "{$domain->scheme}://{$domain->host}" : null;
    }
}
