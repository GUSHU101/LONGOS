<?php

namespace Plugin\LongosProductReviews\Models;

use Beike\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewGenerationBatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'longos_product_review_generation_batches';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'admin_user_id',
        'product_id',
        'status',
        'model',
        'requested_count',
        'processed_count',
        'request_ciphertext',
        'result_ciphertext',
        'error',
        'started_at',
        'finished_at',
        'expires_at',
    ];

    protected $casts = [
        'admin_user_id'    => 'integer',
        'product_id'       => 'integer',
        'requested_count'  => 'integer',
        'processed_count'  => 'integer',
        'started_at'       => 'datetime',
        'finished_at'      => 'datetime',
        'expires_at'       => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_COMMITTED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
