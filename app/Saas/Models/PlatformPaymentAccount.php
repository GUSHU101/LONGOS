<?php

namespace App\Saas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform-owned receiving account.
 *
 * The account lives in the central database, never in a child tenant.  Its
 * callback hostname is immutable after creation so a provider return can be
 * tied to the exact account that created the payment.
 */
class PlatformPaymentAccount extends Model
{
    public const PROVIDER_PAYPAL = 'paypal';

    protected $connection = 'saas';

    protected $table = 'saas_platform_payment_accounts';

    protected $fillable = [
        'uuid', 'provider', 'label', 'api_mode', 'client_id', 'secret_ciphertext', 'merchant_id',
        'callback_host', 'enabled', 'rotation_weight', 'rotation_count', 'last_selected_at',
        'health_status', 'consecutive_failures', 'circuit_open_until', 'last_health_checked_at',
    ];

    protected $hidden = ['secret_ciphertext'];

    protected $casts = [
        'enabled'                 => 'boolean',
        'rotation_weight'         => 'integer',
        'rotation_count'          => 'integer',
        'consecutive_failures'    => 'integer',
        'circuit_open_until'      => 'datetime',
        'last_selected_at'        => 'datetime',
        'last_health_checked_at'  => 'datetime',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(PlatformPaymentSession::class, 'payment_account_id');
    }

    public function scopePaypal(Builder $query): Builder
    {
        return $query->where('provider', self::PROVIDER_PAYPAL);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }
}
