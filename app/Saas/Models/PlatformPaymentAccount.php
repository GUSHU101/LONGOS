<?php

namespace App\Saas\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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

    /**
     * The account pool is shared by every tenant. Persist all scheduling and
     * circuit-breaker timestamps as UTC so a request running in a child
     * store's timezone cannot reopen or extend another account's circuit.
     */
    protected function asDateTime($value): Carbon
    {
        $runtimeTimezone = (string) config('app.timezone', 'UTC');
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->setTimezone($runtimeTimezone);
        }
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value, 'UTC')->setTimezone($runtimeTimezone);
        }
        if ($this->isStandardDateFormat($value)) {
            return Carbon::createFromFormat('Y-m-d', $value, 'UTC')->startOfDay()->setTimezone($runtimeTimezone);
        }

        $date = Carbon::createFromFormat($this->getDateFormat(), (string) $value, 'UTC');
        if (! $date) {
            $date = Carbon::parse((string) $value, 'UTC');
        }

        return $date->setTimezone($runtimeTimezone);
    }

    public function fromDateTime($value): ?string
    {
        if (empty($value)) {
            return parent::fromDateTime($value);
        }

        $date = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse((string) $value, (string) config('app.timezone', 'UTC'));

        return $date->setTimezone('UTC')->format($this->getDateFormat());
    }

    public function freshTimestamp(): Carbon
    {
        return Carbon::now('UTC');
    }
}
