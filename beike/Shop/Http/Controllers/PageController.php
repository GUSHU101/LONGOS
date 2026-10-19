<?php

/**
 * PageController.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-11 18:13:06
 * @modified   2022-08-11 18:13:06
 */

namespace Beike\Shop\Http\Controllers;

use Beike\Models\Page;
use Beike\Repositories\PageCategoryRepo;
use Beike\Shop\Http\Resources\PageDetail;
use Beike\Shop\Http\Resources\ProductSimple;

class PageController extends Controller
{
    public function show(Page $page)
    {
        $page->load(['description', 'category.description', 'products.description', 'products.masterSku']);
        $page->updateViewed();

        // Imported/deleted products can leave a page relation behind without
        // a default SKU. Do not allow that stale relation to turn an otherwise
        // valid content page into a 500 response.
        $products = $page->products->filter(fn ($product) => $product->masterSku)->values();
        $page->setRelation('products', $products);

        $data = [
            'page'                   => $page,
            'active_page_categories' => PageCategoryRepo::getActiveList(),
            'page_format'            => (new PageDetail($page))->jsonSerialize(),
            'products'               => ProductSimple::collection($products)->jsonSerialize(),
        ];

        $data = hook_filter('page.show.data', $data);

        return view('pages/single', $data);
    }
}
