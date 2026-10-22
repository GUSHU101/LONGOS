<?php

namespace Beike\Shop\Http\Controllers;

use App\Saas\Support\SensitiveValueRedactor;
use Beike\Models\Product;
use Beike\Plugin\RuntimeGuard;
use Beike\Repositories\ProductRepo;
use Beike\Shop\Http\Resources\ProductDetail;
use Beike\Shop\Http\Resources\ProductSimple;
use Beike\Shop\Services\ProductFeedService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProductController extends Controller
{
    /**
     * 商品详情页
     * @param Request $request
     * @param Product $product
     * @return mixed
     */
    public function show(Request $request, Product $product)
    {
        $relationIds = $product->relations->pluck('id')->toArray();
        $product     = ProductRepo::getProductDetail($product);
        ProductRepo::viewAdd($product);
        $productData = (new ProductDetail($product))->jsonSerialize();

        $data        = [
            'product'   => $productData,
            'relations' => ProductRepo::getRelatedProducts($product, $relationIds, 4)->jsonSerialize(),
            'has_video' => data_get($product, 'video'),
            'iconfont'  => '<i class="iconfont">&#xe628;</i>',
            // The bundled review feature is rendered as normal product view
            // data. Previously it queried and rendered from a late Blade
            // hook; an exception there was intentionally swallowed and left
            // merchants with reviews in the database but no storefront UI.
            'product_reviews' => $this->productReviews((int) ($productData['id'] ?? 0)),
        ];

        $data = hook_filter('product.show.data', $data);

        return view('product/product', $data);
    }

    /** @return array<string, mixed>|null */
    private function productReviews(int $productId): ?array
    {
        $service = \Plugin\LongosProductReviews\Services\ReviewService::class;
        if ($productId < 1 || ! class_exists($service) || ! RuntimeGuard::isActive('longos_product_reviews')) {
            return null;
        }

        try {
            return app($service)->storefrontPayload($productId);
        } catch (Throwable $exception) {
            Log::warning('Product reviews could not be loaded for the storefront.', [
                'product_id' => $productId,
                'exception'  => $exception::class,
                'message'    => SensitiveValueRedactor::redact($exception->getMessage()),
            ]);

            return null;
        }
    }

    /**
     * 通过关键字搜索商品
     *
     * @param Request $request
     * @return mixed
     */
    public function search(Request $request, ProductFeedService $feeds)
    {
        $rawKeyword = $request->query('keyword', '');
        $keyword    = is_string($rawKeyword) ? mb_substr(trim($rawKeyword), 0, 120) : '';
        $attr       = $request->get('attr');
        $price      = $request->get('price');
        $products   = ProductRepo::getBuilder(['keyword' => $keyword, 'attr' => $attr])
            ->where('active', true)
            ->paginate(perPage())
            ->withQueryString();

        $data = [
            'products'    => $products,
            'items'       => ProductSimple::collection($products)->jsonSerialize(),
            'keyword'     => $keyword,
            'recommended' => $products->isEmpty() ? $feeds->bestsellers(8) : [],
        ];

        $data = hook_filter('product.search.data', $data);

        return view('search', $data);
    }

    public function autocomplete(Request $request)
    {
        $isHtml   = $request->get('html')  ?? false;
        $class    = $request->get('class') ?? '';
        $limit    = $request->get('limit');
        $products = ProductRepo::autocomplete($request->get('name') ?? '', $limit);

        if ($isHtml) {
            $data = [
                'products' => $products,
                'class'    => $class,
            ];

            return view('product.search-product', $data);
        }

        return json_success(trans('common.get_success'), $products);
    }

    /**
     * 获取热销商品（用于搜索弹窗 AJAX 懒加载）
     */
    public function hotProducts(ProductFeedService $feeds)
    {
        $products = $feeds->bestsellers(5);

        return view('product.search-product', ['products' => $products, 'class' => '', 'showAll' => false]);
    }

    /** Native latest-products landing page retained for existing menu links. */
    public function latestProducts(ProductFeedService $feeds)
    {
        return view('product.latest_products', $feeds->paginateLatest((int) perPage()));
    }
}
