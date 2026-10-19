<?php

namespace Plugin\ShopifyProductTransfer\Models;

use Beike\Models\Base;

class ShopifyProductImport extends Base
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'admin_user_id', 'original_name', 'file_path', 'replace_existing', 'status',
        'processed', 'total', 'result', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'replace_existing' => 'boolean',
        'processed'        => 'integer',
        'total'            => 'integer',
        'result'           => 'array',
        'started_at'       => 'datetime',
        'finished_at'      => 'datetime',
    ];
}
