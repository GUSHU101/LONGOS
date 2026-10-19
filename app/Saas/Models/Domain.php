<?php

namespace App\Saas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Domain extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ACTIVATING = 'activating';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RETIRED = 'retired';

    public const ORIGIN_PLATFORM = 'platform';

    public const ORIGIN_TENANT = 'tenant';

    public const VERIFICATION_DNS_TXT = 'dns_txt';

    public const ROUTING_REDIRECT = 'redirect';

    public const ROUTING_ALIAS = 'alias';

    protected $connection = 'saas';

    protected $table = 'saas_domains';

    protected $fillable = [
        'site_id', 'host', 'scheme', 'is_primary', 'routing_behavior', 'status', 'origin',
        'verification_method', 'verification_token', 'verification_expires_at',
        'verification_attempts', 'verified_at', 'last_checked_at', 'verification_error',
        'published_at', 'last_probe_at', 'publication_error',
    ];

    protected $hidden = ['verification_token'];

    protected $casts = [
        'is_primary'              => 'boolean',
        'verified_at'             => 'datetime',
        'published_at'            => 'datetime',
        'last_probe_at'           => 'datetime',
        'last_checked_at'         => 'datetime',
        'verification_expires_at' => 'datetime',
        'verification_attempts'   => 'integer',
    ];

    protected $appends = ['routing_type'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeRoutable(Builder $query): Builder
    {
        // Publication migrations normalize every historical tenant domain
        // that was prematurely marked active back to pending. Keeping the
        // runtime lookup on the long-standing status column lets the first
        // request after a release still reach the site before migrations run.
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isRoutable(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getVerificationRecordNameAttribute(): ?string
    {
        return $this->verification_method === self::VERIFICATION_DNS_TXT
            ? '_longos-challenge.' . $this->host
            : null;
    }

    public function getVerificationRecordValueAttribute(): ?string
    {
        return $this->verification_method === self::VERIFICATION_DNS_TXT && is_string($this->verification_token)
            ? 'longos-site-verification=' . $this->verification_token
            : null;
    }

    public function getRoutingTypeAttribute(): string
    {
        return $this->is_primary ? 'primary' : ($this->routing_behavior ?: self::ROUTING_REDIRECT);
    }
}
