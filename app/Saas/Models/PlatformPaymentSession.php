<?php

namespace App\Saas\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PlatformPaymentSession extends Model
{
    /**
     * Provider callbacks and scheduled reconciliation can arrive after the
     * browser session expires. Keep those transactions recoverable for the
     * same window used by the reconciliation command.
     */
    public const RECOVERY_WINDOW_DAYS = 4;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $connection = 'saas';

    protected $table = 'saas_platform_payment_sessions';

    protected $fillable = [
        'uuid', 'token_hash', 'active_key', 'site_id', 'order_id', 'order_number', 'amount', 'currency', 'locale',
        'timestamps_utc',
        'status', 'provider', 'provider_order_id', 'provider_transaction_id',
        'provider_payload', 'expires_at', 'completed_at',
    ];

    protected $attributes = [
        'timestamps_utc' => true,
    ];

    protected $casts = [
        'amount'           => 'decimal:8',
        'timestamps_utc'   => 'boolean',
        'provider_payload' => 'array',
        'expires_at'       => 'datetime',
        'completed_at'     => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeFinanciallyUnsettled(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_PROCESSING, self::STATUS_EXPIRED])
            ->whereNotNull('provider')
            ->whereNotNull('provider_order_id')
            ->where(function (Builder $query): void {
                $query->where('created_at', '>=', now('UTC')->subDays(self::RECOVERY_WINDOW_DAYS))
                    // Stripe PaymentIntents do not have PayPal Orders' short
                    // approval lifetime. Keep an old intent locked until the
                    // reconciliation worker has confirmed or cancelled it.
                    ->orWhere(function (Builder $query): void {
                        $query->where('provider', 'stripe')->whereNotNull('active_key');
                    });
            });
    }

    public function scopeCredentialSensitive(Builder $query, string $provider): Builder
    {
        return $query
            ->where('provider', $provider)
            ->deletionSensitive();
    }

    public function scopeDeletionSensitive(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->financiallyUnsettled();
                })->orWhere(function (Builder $query): void {
                    // A provider claim is written before the remote API call.
                    // Protect that short race even before an order id exists.
                    $query->where('status', self::STATUS_PROCESSING)
                        ->whereNull('provider_order_id')
                        ->where('expires_at', '>', now('UTC'));
                });
            });
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)
            && $this->expires_at?->isFuture();
    }

    public function isFinanciallyRecoverable(): bool
    {
        return in_array($this->status, [self::STATUS_PROCESSING, self::STATUS_EXPIRED], true)
            && $this->provider          !== null
            && $this->provider_order_id !== null
            && ($this->created_at?->gte(now('UTC')->subDays(self::RECOVERY_WINDOW_DAYS))
                || ($this->provider === 'stripe' && $this->active_key !== null));
    }

    /**
     * Central payment timestamps are stored in UTC even while a request is
     * running in a child store's timezone. Convert them for presentation only
     * after parsing the database value as an absolute UTC instant.
     */
    protected function asDateTime($value): Carbon
    {
        if (! (bool) $this->timestamps_utc) {
            return parent::asDateTime($value);
        }

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
        if (empty($value) || ! (bool) $this->timestamps_utc) {
            return parent::fromDateTime($value);
        }

        $date = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse((string) $value, (string) config('app.timezone', 'UTC'));

        return $date->setTimezone('UTC')->format($this->getDateFormat());
    }

    public function freshTimestamp(): Carbon
    {
        return (bool) $this->timestamps_utc ? Carbon::now('UTC') : parent::freshTimestamp();
    }
}
