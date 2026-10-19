<?php

namespace Plugin\LongosProductReviews\Controllers;

use App\Services\BundledPluginSynchronizer;
use Beike\Models\Product;
use Beike\Shop\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugin\LongosProductReviews\Services\ReviewService;

class ShopReviewController extends Controller
{
    public function index(Request $request, Product $product, ReviewService $service): JsonResponse
    {
        abort_unless($product->active && ! $product->trashed(), 404);
        app(BundledPluginSynchronizer::class)->ensureProductReviewsReady();
        $rating = (int) $request->query('rating', 0);
        $rating = in_array($rating, range(1, 5), true) ? $rating : 0;

        $page = $service->storefrontPage(
            (int) $product->id,
            max(0, (int) $request->query('offset', 0)),
            null,
            [
                'rating'      => $rating,
                'with_images' => $request->boolean('with_images'),
                'sort'        => in_array($request->query('sort'), ['featured', 'newest', 'highest', 'lowest'], true)
                    ? (string) $request->query('sort')
                    : 'featured',
            ],
        );

        return response()->json([
            'html'         => view('LongosProductReviews::shop.cards', ['reviews' => $page['reviews']])->render(),
            'next_offset'  => $page['next_offset'],
            'has_more'     => $page['has_more'],
            'total'        => $page['total'],
            'result_label' => trans_choice('LongosProductReviews::reviews.filtered_result_count', $page['total'], ['count' => $page['total']]),
        ]);
    }
}
