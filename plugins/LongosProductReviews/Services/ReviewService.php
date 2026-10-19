<?php

namespace Plugin\LongosProductReviews\Services;

use App\Services\BundledPluginSynchronizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Plugin\LongosProductReviews\Models\Review;
use Throwable;

class ReviewService
{
    public const MAX_IMAGES = 6;

    /** @return array<string, mixed>|null */
    public function storefrontPayload(int $productId, ?int $limit = null): ?array
    {
        if ($productId < 1) {
            return null;
        }
        $this->ensureReady();

        $limit = $this->displayLimit($limit);
        $base  = Review::query()->published()->where('product_id', $productId);
        $total = (clone $base)->count();

        if ($total === 0) {
            return null;
        }

        // Keep aggregate operations independent. Some production MySQL modes
        // and compatibility layers did not hydrate the aliased COUNT/AVG row
        // consistently, which caused the late storefront hook to fail even
        // though the pagination endpoint could return the same review rows.
        $average = round((float) (clone $base)->avg('rating'), 1);

        $counts = (clone $base)
            ->selectRaw('rating, COUNT(*) AS aggregate')
            ->groupBy('rating')
            ->pluck('aggregate', 'rating')
            ->map(fn ($count): int => (int) $count);

        $distribution = [];
        foreach (range(5, 1) as $rating) {
            $count          = (int) ($counts[$rating] ?? 0);
            $distribution[] = [
                'rating'     => $rating,
                'count'      => $count,
                'percentage' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
            ];
        }

        return [
            'average'      => $average,
            'total'        => $total,
            'distribution' => $distribution,
            'reviews'      => (clone $base)->storefrontOrder()->limit($limit)->get(),
            'next_offset'  => min($limit, $total),
            'has_more'     => $total > $limit,
            'limit'        => $limit,
        ];
    }

    /**
     * @param array{rating?: int, with_images?: bool, sort?: string} $filters
     * @return array{reviews: \Illuminate\Database\Eloquent\Collection<int, Review>, next_offset: int, has_more: bool, total: int}
     */
    public function storefrontPage(int $productId, int $offset, ?int $limit = null, array $filters = []): array
    {
        $this->ensureReady();
        $limit  = $this->displayLimit($limit);
        $offset = max(0, $offset);
        $base   = Review::query()->published()->where('product_id', $productId);

        $rating = (int) ($filters['rating'] ?? 0);
        if (in_array($rating, range(1, 5), true)) {
            $base->where('rating', $rating);
        }
        if ((bool) ($filters['with_images'] ?? false)) {
            $base->whereJsonLength('images', '>', 0);
        }

        $sort = in_array(($filters['sort'] ?? ''), ['featured', 'newest', 'highest', 'lowest'], true)
            ? (string) $filters['sort']
            : 'featured';
        $total  = (clone $base)->count();
        $offset = min($total, $offset);
        $items  = $base->storefrontSort($sort)->offset($offset)->limit($limit)->get();
        $next   = min($total, $offset + $items->count());

        return ['reviews' => $items, 'next_offset' => $next, 'has_more' => $next < $total, 'total' => $total];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, array $uploads = []): Review
    {
        $this->ensureReady();
        $stored = $this->storeImages($uploads, self::MAX_IMAGES);

        try {
            return DB::transaction(function () use ($data, $stored): Review {
                $data['images'] = $stored;

                return Review::query()->create($this->attributes($data));
            });
        } catch (Throwable $exception) {
            $this->deleteImages($stored);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(Review $review, array $data, array $uploads = [], array $remove = []): Review
    {
        $existing = array_values(array_filter((array) $review->images, 'is_string'));
        $remove   = array_values(array_intersect($existing, array_filter($remove, 'is_string')));
        $kept     = array_values(array_diff($existing, $remove));
        $capacity = max(0, self::MAX_IMAGES - count($kept));
        $stored   = $this->storeImages($uploads, $capacity);

        try {
            DB::transaction(function () use ($review, $data, $kept, $stored): void {
                $data['images'] = array_values(array_merge($kept, $stored));
                $review->fill($this->attributes($data))->saveOrFail();
            });
        } catch (Throwable $exception) {
            $this->deleteImages($stored);

            throw $exception;
        }

        $this->deleteImages($remove);

        return $review->refresh();
    }

    public function delete(Review $review): void
    {
        $images = array_values(array_filter((array) $review->images, 'is_string'));
        $review->deleteOrFail();
        $this->deleteImages($images);
    }

    public function setPublished(Review $review, bool $published): Review
    {
        $this->ensureReady();
        $review->forceFill(['published' => $published])->saveOrFail();

        return $review->refresh();
    }

    /** @param array<int, int|string> $productIds */
    public function deleteForProducts(array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn (int $id): bool => $id > 0)));
        if ($productIds === [] || ! Schema::hasTable('longos_product_reviews')) {
            return;
        }

        $reviews = Review::query()->whereIn('product_id', $productIds)->get(['id', 'images']);
        $images  = $reviews->flatMap(fn (Review $review): array => array_values(array_filter((array) $review->images, 'is_string')))->all();

        DB::transaction(static function () use ($productIds): void {
            Review::query()->whereIn('product_id', $productIds)->delete();
        });
        $this->deleteImages($images);
    }

    public function displayLimit(?int $limit = null): int
    {
        $configured = $limit ?? (int) plugin_setting('longos_product_reviews.display_limit', 6);

        return min(12, max(3, $configured));
    }

    /** @param array<string, mixed> $data */
    private function attributes(array $data): array
    {
        return [
            'product_id'         => (int) $data['product_id'],
            'author_name'        => trim((string) $data['author_name']),
            'author_country'     => ($data['author_country'] ?? null) ?: null,
            'rating'             => (int) $data['rating'],
            'title'              => trim((string) $data['title']),
            'body'               => trim((string) $data['body']),
            'images'             => array_values((array) ($data['images'] ?? [])),
            'verified_purchase'  => (bool) ($data['verified_purchase'] ?? false),
            'published'          => (bool) ($data['published'] ?? false),
            'sort_order'         => (int) ($data['sort_order'] ?? 0),
            'reviewed_at'        => $data['reviewed_at'],
        ];
    }

    private function ensureReady(): void
    {
        app(BundledPluginSynchronizer::class)->ensureProductReviewsReady();
    }

    /** @param array<int, UploadedFile> $uploads
     *  @return array<int, string>
     */
    private function storeImages(array $uploads, int $capacity): array
    {
        if (count($uploads) > $capacity) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => trans('LongosProductReviews::reviews.error_image_limit', ['count' => self::MAX_IMAGES]),
            ]);
        }

        $stored = [];

        try {
            foreach ($uploads as $upload) {
                if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
                    continue;
                }
                $extension = strtolower($upload->extension() ?: 'jpg');
                $relative  = 'reviews/' . now()->format('Y/m') . '/' . Str::uuid() . '.' . $extension;
                $stream    = fopen($upload->getRealPath(), 'rb');
                if ($stream === false || ! Storage::disk('upload')->put($relative, $stream)) {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    throw new \RuntimeException(trans('LongosProductReviews::reviews.error_upload'));
                }
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $stored[] = tenant_upload_prefix() . '/' . $relative;
            }
        } catch (Throwable $exception) {
            $this->deleteImages($stored);

            throw $exception;
        }

        return $stored;
    }

    /** @param array<int, string> $paths */
    private function deleteImages(array $paths): void
    {
        $prefix = rtrim(tenant_upload_prefix(), '/') . '/';
        foreach ($paths as $path) {
            if (! is_string($path) || ! str_starts_with($path, $prefix)) {
                continue;
            }
            $relative = ltrim(substr($path, strlen($prefix)), '/');
            if ($relative !== '' && str_starts_with($relative, 'reviews/')) {
                Storage::disk('upload')->delete($relative);
            }
        }
    }
}
