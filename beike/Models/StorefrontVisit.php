<?php

namespace Beike\Models;

class StorefrontVisit extends Base
{
    protected $fillable = [
        'bucket_at',
        'visitor_key',
        'customer_id',
        'country_code',
        'source_key',
        'source_detail',
        'referer',
        'landing_path',
        'page_views',
        'first_seen_at',
        'last_seen_at',
        'human_confirmed_at',
        'confirmation_method',
    ];

    protected $casts = [
        'bucket_at'          => 'datetime',
        'first_seen_at'      => 'datetime',
        'last_seen_at'       => 'datetime',
        'human_confirmed_at' => 'datetime',
        'page_views'         => 'integer',
    ];
}
