<?php

namespace Beike\Shop\Http\Resources;

use App\Saas\Support\SensitiveValueRedactor;
use Beike\Plugin\RuntimeGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Product-card collection shared by every storefront feed.
 *
 * Keeping the review decoration here covers category, search, brand, page,
 * Builder, latest, bestseller, autocomplete and related-product cards without
 * introducing one aggregate query per card. Admin/API serialization remains
 * untouched because only named storefront routes are decorated.
 */
final class ProductSimpleCollection extends AnonymousResourceCollection
{
    /** @return array<int, array<string, mixed>> */
    public function toArray(Request $request): array
    {
        $products = parent::toArray($request);
        $service  = \Plugin\LongosProductReviews\Services\ReviewService::class;

        if (
            $products === []
            || ! $request->routeIs('shop.*')
            || ! class_exists($service)
            || ! RuntimeGuard::isActive('longos_product_reviews')
        ) {
            return $products;
        }

        try {
            return app($service)->decorateStorefrontProducts($products);
        } catch (Throwable $exception) {
            // Reviews are supplemental storefront content. A partially
            // migrated or temporarily unavailable plugin must never prevent
            // customers from browsing or purchasing products.
            Log::warning('Product-card review summaries could not be loaded.', [
                'exception' => $exception::class,
                'message'   => SensitiveValueRedactor::redact($exception->getMessage()),
            ]);

            return $products;
        }
    }
}
