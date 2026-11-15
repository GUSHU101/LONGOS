<?php

namespace Beike\Shop\Http\Controllers;

use App\Saas\Support\SensitiveValueRedactor;
use Beike\Models\Category;
use Beike\Plugin\RuntimeGuard;
use Beike\Repositories\CategoryRepo;
use Beike\Repositories\FlattenCategoryRepo;
use Beike\Repositories\ProductRepo;
use Beike\Shop\Http\Resources\CategoryDetail;
use Beike\Shop\Http\Resources\ProductSimple;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        return redirect('/');
    }

    public function show(Request $request, Category $category)
    {
        if (! $category->active) {
            return redirect(shop_route('home.index'));
        }
        $filterData     = $request->only('attr', 'price', 'sort', 'order', 'per_page');
        $products       = ProductRepo::getProductsByCategory($category->id, $filterData);
        $productsFormat = ProductSimple::collection($products)->jsonSerialize();
        $productsFormat = $this->withProductReviewSummaries($productsFormat);
        $category->load('description');
        $filterData = array_merge($filterData, ['category_id' => $category->id, 'active' => 1]);

        $data       = [
            'all_categories'  => FlattenCategoryRepo::getCategoryList(),
            'category'        => $category,
            'children'        => CategoryDetail::collection($category->activeChildren)->jsonSerialize(),
            'filter_data'     => [
                'attr'  => ProductRepo::getFilterAttribute($filterData),
                'price' => ProductRepo::getFilterPrice($filterData),
            ],
            'products_format' => $productsFormat,
            'products'        => $products,
            'per_pages'       => CategoryRepo::getPerPages(),
        ];

        $data = hook_filter('category.show.data', $data);

        return view('category', $data);
    }

    /**
     * Add the published review average and count to every collection card in
     * one batch. Like the product detail controller, this runs before Blade
     * rendering so tenant context and failures are handled deterministically.
     *
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private function withProductReviewSummaries(array $products): array
    {
        $service = \Plugin\LongosProductReviews\Services\ReviewService::class;
        if ($products === [] || ! class_exists($service) || ! RuntimeGuard::isActive('longos_product_reviews')) {
            return $products;
        }

        try {
            $productIds = array_values(array_filter(array_map(
                static fn (array $product): int => (int) ($product['id'] ?? 0),
                $products,
            )));
            $summaries = app($service)->storefrontSummaries($productIds);

            foreach ($products as &$product) {
                $productId = (int) ($product['id'] ?? 0);
                if (isset($summaries[$productId])) {
                    $product['review_summary'] = $summaries[$productId];
                }
            }
            unset($product);
        } catch (Throwable $exception) {
            Log::warning('Product review summaries could not be loaded for a collection.', [
                'exception' => $exception::class,
                'message'   => SensitiveValueRedactor::redact($exception->getMessage()),
            ]);
        }

        return $products;
    }
}
