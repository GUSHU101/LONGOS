<?php

namespace Beike\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbandonedOrderRecovery extends Base
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'order_id', 'admin_user_id', 'discount_id', 'converted_order_id',
        'recipient_email', 'delivery_id', 'access_token_hash', 'status',
        'sender_category', 'subject', 'body', 'discount_code',
        'transport_attempts', 'sent_at', 'failed_at', 'opened_at',
        'restored_at', 'converted_at', 'suppressed_at', 'expires_at',
        'failure_message', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'      => 'array',
            'sent_at'       => 'datetime',
            'failed_at'     => 'datetime',
            'opened_at'     => 'datetime',
            'restored_at'   => 'datetime',
            'converted_at'  => 'datetime',
            'suppressed_at' => 'datetime',
            'expires_at'    => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
