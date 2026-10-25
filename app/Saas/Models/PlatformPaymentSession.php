<?php

namespace App\Saas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformPaymentSession extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $connection = 'saas';

    protected $table = 'saas_platform_payment_sessions';

    protected $fillable = [
        'uuid', 'token_hash', 'site_id', 'order_id', 'order_number', 'amount', 'currency', 'locale',
        'status', 'provider', 'provider_order_id', 'provider_transaction_id',
        'provider_payload', 'expires_at', 'completed_at',
    ];

    protected $casts = [
        'amount'           => 'decimal:8',
        'provider_payload' => 'array',
        'expires_at'       => 'datetime',
        'completed_at'     => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)
            && $this->expires_at?->isFuture();
    }
}
