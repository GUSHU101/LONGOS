<?php

namespace App\Saas\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyRecord extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $connection = 'saas';

    protected $table = 'saas_idempotency_records';

    protected $guarded = [];

    protected $hidden = ['request_hash', 'response_ciphertext', 'lock_token'];

    protected $casts = [
        'response_status' => 'integer',
        'locked_at'       => 'datetime',
        'expires_at'      => 'datetime',
    ];
}
