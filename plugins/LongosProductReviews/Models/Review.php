<?php

namespace Plugin\LongosProductReviews\Models;

use Beike\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $table = 'longos_product_reviews';

    protected $fillable = [
        'product_id',
        'author_name',
        'author_country',
        'rating',
        'title',
        'body',
        'images',
        'verified_purchase',
        'published',
        'sort_order',
        'reviewed_at',
    ];

    protected $casts = [
        'images'            => 'array',
        'rating'            => 'integer',
        'verified_purchase' => 'boolean',
        'published'         => 'boolean',
        'sort_order'        => 'integer',
        'reviewed_at'       => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    public function scopeStorefrontOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc('sort_order')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id');
    }

    public function scopeStorefrontSort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'newest'  => $query->orderByDesc('reviewed_at')->orderByDesc('id'),
            'highest' => $query->orderByDesc('rating')->orderByDesc('reviewed_at')->orderByDesc('id'),
            'lowest'  => $query->orderBy('rating')->orderByDesc('reviewed_at')->orderByDesc('id'),
            default   => $query->storefrontOrder(),
        };
    }
}
