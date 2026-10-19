<?php

namespace App\Saas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteEvent extends Model
{
    public $timestamps = false;

    protected $connection = 'saas';

    protected $table = 'saas_site_events';

    protected $fillable = ['site_id', 'event', 'status', 'message', 'context', 'created_at'];

    protected $casts = [
        'context'    => 'array',
        'created_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
