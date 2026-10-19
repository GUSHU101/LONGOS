<?php

namespace Beike\Admin\Http\Controllers;

use Beike\Admin\Repositories\PageRepo;
use Beike\Models\AdminUser;
use Beike\Models\Brand;
use Beike\Models\Category;
use Beike\Models\Page;
use Beike\Models\Product;
use Beike\Models\Setting;
use Beike\Repositories\BrandRepo;
use Beike\Repositories\CategoryRepo;
use Beike\Repositories\MenuRepo;
use Beike\Repositories\ProductRepo;
use Beike\Repositories\SettingRepo;
use Beike\Services\BuilderStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Dedicated header-navigation workspace.
 *
 * Navigation remains part of the atomic Store Builder state. This controller
 * exposes a focused editing surface without creating a second source of truth.
 */
class DesignNavigationController extends Controller
{
    public function index(): View
    {
        $draft     = BuilderStateService::draft();
        $published = BuilderStateService::published();

        return view('admin::pages.design.navigation', [
            'menu_settings'           => $draft['menu'],
            'draft_version'           => $draft['version'],
            'has_unpublished_changes' => BuilderStateService::hasUnpublishedChanges($draft, $published),
            'storefront_preview_url'  => url('/?design=1'),
            'resource_search_url'     => admin_route('design.navigation.resources'),
            'link_types'              => [
                ['value' => 'custom', 'label' => __('admin/design_navigation.link_custom')],
                ['value' => 'category', 'label' => __('admin/design_navigation.link_category')],
                ['value' => 'product', 'label' => __('admin/design_navigation.link_product')],
                ['value' => 'page', 'label' => __('admin/design_navigation.link_page')],
                ['value' => 'brand', 'label' => __('admin/design_navigation.link_brand')],
                ['value' => 'static', 'label' => __('admin/design_navigation.link_static')],
            ],
            'static_routes'          => [
                ['value' => 'home.index', 'label' => __('admin/design_navigation.route_home')],
                ['value' => 'products.index', 'label' => __('admin/design_navigation.route_products')],
                ['value' => 'brands.index', 'label' => __('admin/design_navigation.route_brands')],
                ['value' => 'account.index', 'label' => __('admin/design_navigation.route_account')],
                ['value' => 'account.order.index', 'label' => __('admin/design_navigation.route_orders')],
                ['value' => 'carts.index', 'label' => __('admin/design_navigation.route_cart')],
            ],
        ]);
    }

    /**
     * Search real storefront resources for the navigation destination picker.
     * The stored menu contract remains unchanged: the UI writes the selected
     * resource identifier to link.value, while administrators work with names,
     * images and publication status instead of raw database IDs.
     */
    public function resources(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'  => 'required|string|in:product,category,page,brand',
            'query' => 'nullable|string|max:120',
            'id'    => 'nullable|integer|min:1',
        ]);

        $type = $validated['type'];
        if (isset($validated['id'])) {
            $item  = $this->findResource($type, (int) $validated['id']);
            $items = $item ? [$item] : [];
        } else {
            $items = $this->searchResources($type, trim((string) ($validated['query'] ?? '')));
        }

        return json_success(__('common.get_success'), ['items' => $items]);
    }

    public function update(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        if (! is_string($raw) || $raw === '' || strlen($raw) > 524_288) {
            return json_fail(__('admin/design_navigation.invalid_payload'));
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload) || ! is_array($payload['menu'] ?? null)) {
            return json_fail(__('admin/design_navigation.invalid_payload'));
        }

        $expectedVersion = $request->header('X-Builder-Draft-Version');
        if (! is_string($expectedVersion) || ! preg_match('/^\d{1,10}$/', $expectedVersion)) {
            return json_fail(__('admin/design_navigation.refresh_required'), [], 409);
        }

        $menu    = MenuRepo::normalizeMenuSetting($payload['menu']);
        $publish = ($payload['publish'] ?? false)      === true;
        $force   = $request->header('X-Builder-Force') === '1';

        try {
            $result = DB::transaction(function () use ($menu, $publish, $force, $expectedVersion): array {
                // Lock the stable base settings group first. It serializes the
                // first draft write as well as all later Builder writers.
                Setting::query()
                    ->where('type', 'system')
                    ->where('space', 'base')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                $draftRecord = Setting::query()
                    ->where('type', 'system')
                    ->where('space', 'base')
                    ->where('name', BuilderStateService::DRAFT_SETTING)
                    ->lockForUpdate()
                    ->first();
                $stored       = $this->decodeSetting($draftRecord);
                $current      = is_array($stored) && is_array($stored['design'] ?? null)
                    ? BuilderStateService::normalize($stored, BuilderStateService::published())
                    : BuilderStateService::published();

                if (! $force && (int) $expectedVersion !== $current['version']) {
                    return ['conflict' => true, 'draft' => $current, 'published' => false];
                }

                $next         = $current;
                $next['menu'] = $menu;
                $adminId      = Auth::guard(AdminUser::AUTH_GUARD)->id();
                $draft        = BuilderStateService::makeDraft(
                    $next,
                    $current['version'] + 1,
                    is_numeric($adminId) ? (int) $adminId : null
                );
                SettingRepo::storeValue(BuilderStateService::DRAFT_SETTING, $draft, 'base', 'system', false);

                $publishedState = BuilderStateService::published();
                if ($publish) {
                    SettingRepo::storeValue('menu_setting', $draft['menu'], 'base', 'system', false);
                    $savedMenu = $this->decodeSetting(Setting::query()
                        ->where('type', 'system')
                        ->where('space', 'base')
                        ->where('name', 'menu_setting')
                        ->lockForUpdate()
                        ->first());
                    if (MenuRepo::normalizeMenuSetting($savedMenu) !== $draft['menu']) {
                        throw new \RuntimeException('Published navigation verification failed.');
                    }
                    $publishedState['menu'] = $draft['menu'];
                }

                return [
                    'conflict'                => false,
                    'draft'                   => $draft,
                    'published'               => $publish,
                    'has_unpublished_changes' => BuilderStateService::hasUnpublishedChanges($draft, $publishedState),
                ];
            });
        } catch (\Throwable $exception) {
            report($exception);
            logger()->error('The dedicated header navigation editor could not save.', [
                'exception' => $exception->getMessage(),
            ]);

            return json_fail(__('admin/design_navigation.save_failed'));
        }

        if ($result['conflict']) {
            return json_fail(__('admin/design_navigation.conflict'), [
                'draft_version' => $result['draft']['version'],
            ], 409);
        }

        $cacheRefreshed = true;

        try {
            SettingRepo::clearCache();
        } catch (\Throwable $exception) {
            $cacheRefreshed = false;
            report($exception);
        }

        return json_success(
            $publish ? __('admin/design_navigation.published') : __('admin/design_navigation.draft_saved'),
            [
                'menu'                    => $result['draft']['menu'],
                'draft_version'           => $result['draft']['version'],
                'published'               => $result['published'],
                'has_unpublished_changes' => $result['has_unpublished_changes'],
                'cache_refreshed'         => $cacheRefreshed,
            ]
        );
    }

    private function decodeSetting(?Setting $setting): mixed
    {
        if (! $setting) {
            return null;
        }
        if (! $setting->json) {
            return $setting->value;
        }
        $decoded = json_decode((string) $setting->value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function searchResources(string $type, string $query): array
    {
        $items = match ($type) {
            'product'  => ProductRepo::autocomplete($query, 20),
            'category' => array_slice(CategoryRepo::autocomplete($query), 0, 20),
            'page'     => array_slice(PageRepo::autocomplete($query), 0, 20),
            'brand'    => BrandRepo::autocomplete($query, 1)->take(20)->map(fn (Brand $brand): array => [
                'id'     => $brand->id,
                'name'   => $brand->name ?? '',
                'status' => $brand->active,
                'image'  => $this->previewImage($brand->logo),
            ])->values()->all(),
        };

        return $this->normalizeResources(is_array($items) ? $items : []);
    }

    private function findResource(string $type, int $id): ?array
    {
        $item = match ($type) {
            'product'  => $this->findProduct($id),
            'category' => ($category = Category::query()->with('description')->find($id)) ? [
                'id'     => $category->id,
                'name'   => $category->description->name ?? '',
                'status' => $category->active,
                'image'  => $this->previewImage($category->image ?? ''),
            ] : null,
            'page' => ($page = Page::query()->with('description')->find($id)) ? [
                'id'     => $page->id,
                'name'   => $page->description->title ?? '',
                'status' => $page->active,
                'image'  => '',
            ] : null,
            'brand' => ($brand = Brand::query()->find($id)) ? [
                'id'     => $brand->id,
                'name'   => $brand->name ?? '',
                'status' => $brand->active,
                'image'  => $this->previewImage($brand->logo),
            ] : null,
        };

        if (! is_array($item)) {
            return null;
        }

        return $this->normalizeResources([$item])[0] ?? null;
    }

    private function findProduct(int $id): ?array
    {
        $product = Product::query()->with(['description', 'masterSku'])->find($id);
        if (! $product) {
            return null;
        }

        $images = is_array($product->images) ? $product->images : [];
        $image  = collect($images)->first(fn ($path): bool => is_string($path) && ! str_ends_with(strtolower($path), '.mp4'));

        return [
            'id'     => $product->id,
            'name'   => $product->description->name ?? '',
            'status' => $product->active,
            'image'  => $this->previewImage(is_string($image) ? $image : ''),
            'meta'   => $product->masterSku ? currency_format($product->masterSku->price) : '',
        ];
    }

    private function normalizeResources(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $item = is_array($item) ? $item : [];
            $id   = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $name = trim(strip_tags((string) ($item['name'] ?? $item['name_format'] ?? '')));
            if (! $id || $name === '') {
                continue;
            }

            $normalized[] = [
                'id'     => (int) $id,
                'name'   => mb_substr($name, 0, 190),
                'status' => (bool) ($item['status'] ?? true),
                'image'  => $this->normalizePreviewUrl((string) ($item['image_format'] ?? $item['image'] ?? '')),
                'meta'   => mb_substr(trim(strip_tags((string) ($item['meta'] ?? $item['price_format'] ?? ''))), 0, 120),
            ];
        }

        return array_values($normalized);
    }

    private function previewImage(?string $path): string
    {
        if (! is_string($path) || trim($path) === '') {
            return '';
        }

        try {
            return image_resize($path, 120, 120);
        } catch (\Throwable) {
            return '';
        }
    }

    private function normalizePreviewUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : '';
    }
}
