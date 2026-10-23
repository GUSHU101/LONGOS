<?php

namespace Beike\Admin\Http\Controllers;

use Beike\Admin\Http\Requests\CategoryRequest;
use Beike\Admin\Http\Resources\CategoryResource;
use Beike\Admin\Http\Resources\ProductSimple;
use Beike\Admin\Services\CategoryService;
use Beike\Admin\Services\ProductCategoryAssignmentService;
use Beike\Models\Brand;
use Beike\Models\Category;
use Beike\Repositories\CategoryRepo;
use Beike\Repositories\LanguageRepo;
use Beike\Repositories\ProductRepo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class CategoryController extends Controller
{
    protected string $defaultRoute = 'categories.index';

    public function index(ProductCategoryAssignmentService $assignmentService)
    {
        $categories       = CategoryRepo::getAdminList();
        $adminRoutePrefix = admin_name() . '.';
        $smartRoutesReady = collect([
            'categories.smart_products',
            'categories.smart_products.store',
            'categories.smart_products.rule',
        ])->every(static fn (string $name): bool => Route::has($adminRoutePrefix . $name));
        $smartAssignmentAvailable = $smartRoutesReady && $assignmentService->rulesAvailable();

        $data = [
            'categories' => CategoryResource::collection($categories),
            'brands'     => $smartAssignmentAvailable
                ? Brand::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(static fn (Brand $brand): array => [
                        'id'   => (int) $brand->id,
                        'name' => (string) $brand->name,
                    ])
                    ->values()
                : collect(),
            'smart_assignment_available' => $smartAssignmentAvailable,
            // Generate new named routes only after the router confirms that
            // the uploaded route file/cache and controller revision agree.
            'smart_products_url_template' => $smartAssignmentAvailable
                ? admin_route('categories.smart_products', ['category' => '__CATEGORY__'])
                : '',
            'smart_rule_url_template' => $smartAssignmentAvailable
                ? admin_route('categories.smart_products.rule', ['category' => '__CATEGORY__'])
                : '',
            // Precompute translated JavaScript templates here. Passing a
            // multi-line associative array through Blade's @json directive
            // can be parsed as an unterminated PHP expression.
            'smart_title_template' => __('admin/category.smart_title', [
                'category' => '__CATEGORY__',
            ]),
            'smart_summary_template' => __('admin/category.smart_summary', [
                'scanned'  => '__SCANNED__',
                'eligible' => '__ELIGIBLE__',
                'matches'  => '__MATCHES__',
                'returned' => '__RETURNED__',
            ]),
        ];
        $data = hook_filter('admin.category.index.data', $data);

        return view('admin::pages.categories.index', $data);
    }

    public function create(Request $request)
    {
        return $this->form($request);
    }

    public function store(CategoryRequest $request)
    {
        return $this->save($request);
    }

    public function edit(Request $request, Category $category)
    {
        return $this->form($request, $category);
    }

    public function update(CategoryRequest $request, Category $category)
    {
        return $this->save($request, $category);
    }

    /**
     * 删除分类
     * @param Request  $request
     * @param Category $category
     * @return JsonResponse
     * @throws \Exception
     */
    public function destroy(Request $request, Category $category): JsonResponse
    {
        CategoryRepo::delete($category);
        hook_action('admin.category.destroy.after', $category);

        return json_success(trans('common.deleted_success'));
    }

    public function name(int $id)
    {
        $name = CategoryRepo::getName($id);

        return json_success(trans('common.get_success'), $name);
    }

    protected function form(Request $request, ?Category $category = null)
    {
        if ($category) {
            $descriptions  = $category->descriptions->keyBy('locale');
            $descendantIds = $category->getDescendantIds();
        }

        // 取出所有分类
        $categories = CategoryRepo::flatten(locale());

        // 如果是编辑分类，就排除自己和所有后代
        if ($category && $category->id) {
            $categories = $categories->reject(function ($item) use ($category, $descendantIds) {
                return $item->id == $category->id || in_array($item->id, $descendantIds);
            });
        }

        $data = [
            'category'     => $category ?? new Category,
            'languages'    => LanguageRepo::all()->where('code', 'en'),
            'descriptions' => $descriptions ?? null,
            'categories'   => $categories,
            '_redirect'    => $this->getRedirect(),
        ];

        $data = hook_filter('admin.category.form.data', $data);

        return view('admin::pages.categories.form', $data);
    }

    protected function save(Request $request, ?Category $category = null)
    {
        $requestData = $request->all();

        $isUpdate    = ! is_null($category);
        $category    = (new CategoryService)->createOrUpdate($requestData, $category);
        $data        = [
            'category'     => $category,
            'request_data' => $requestData,
        ];

        hook_action('admin.category.save.after', $data);

        $success = trans('common.updated_success');

        return $isUpdate
        ? redirect()->back()->with('success', $success)
        : redirect($this->getRedirect())->with('success', $success);
    }

    public function autocomplete(Request $request)
    {
        $categories = CategoryRepo::autocomplete($request->get('name') ?? '');

        return json_success(trans('common.get_success'), $categories);
    }

    public function smartProducts(
        Request $request,
        Category $category,
        ProductCategoryAssignmentService $assignmentService
    ): JsonResponse {
        if (! $assignmentService->rulesAvailable()) {
            return json_fail(trans('admin/category.smart_upgrade_pending'), [], 503);
        }

        $validated = $request->validate([
            'keywords'    => ['sometimes', 'nullable', 'string', 'max:2000'],
            'brand_ids'   => ['sometimes', 'array', 'max:100'],
            'brand_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'limit'       => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $suggestions = $assignmentService->suggest(
            $category,
            $request->exists('keywords') ? ($validated['keywords'] ?? '') : null,
            $request->exists('brand_ids') ? ($validated['brand_ids'] ?? []) : null,
            (int) ($validated['limit'] ?? 100),
        );

        return json_success(trans('admin/category.smart_preview_ready'), $suggestions);
    }

    public function storeSmartProducts(
        Request $request,
        Category $category,
        ProductCategoryAssignmentService $assignmentService
    ): JsonResponse {
        if (! $assignmentService->rulesAvailable()) {
            return json_fail(trans('admin/category.smart_upgrade_pending'), [], 503);
        }

        $validated = $request->validate([
            'product_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'product_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'keywords'      => ['present', 'nullable', 'string', 'max:2000'],
            'brand_ids'     => ['required', 'array', 'max:100'],
            'brand_ids.*'   => ['required', 'integer', 'distinct', 'min:1'],
            'auto_enabled'  => ['required', 'boolean'],
        ]);

        $result = DB::transaction(function () use ($assignmentService, $category, $validated): array {
            $assignmentService->saveRule(
                $category,
                $validated['keywords'] ?? '',
                $validated['brand_ids'],
                (bool) $validated['auto_enabled'],
            );

            return $assignmentService->assign($validated['product_ids'], [$category->id]);
        });

        return json_success(
            trans('admin/category.smart_added', ['count' => $result['inserted']]),
            $result,
        );
    }

    public function updateSmartRule(
        Request $request,
        Category $category,
        ProductCategoryAssignmentService $assignmentService
    ): JsonResponse {
        if (! $assignmentService->rulesAvailable()) {
            return json_fail(trans('admin/category.smart_upgrade_pending'), [], 503);
        }

        $validated = $request->validate([
            'keywords'    => ['present', 'nullable', 'string', 'max:2000'],
            'brand_ids'   => ['required', 'array', 'max:100'],
            'brand_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'enabled'     => ['required', 'boolean'],
        ]);

        $rule = $assignmentService->saveRule(
            $category,
            $validated['keywords'] ?? '',
            $validated['brand_ids'],
            (bool) $validated['enabled'],
        );

        return json_success(trans('admin/category.smart_rule_saved'), $rule);
    }

    /**
     * 获取分类下商品列表
     *
     * @param Request  $request
     * @param Category $category
     * @return JsonResponse
     * @throws \Exception
     */
    public function getProducts(Request $request, Category $category): JsonResponse
    {
        $limit          = $request->get('limit', 10);
        $productList    = ProductRepo::getBuilder(['category_id' => $category->id, 'active' => 1])->limit($limit)->get();
        $products       = ProductSimple::collection($productList)->jsonSerialize();

        return json_success(trans('common.get_success'), $products);
    }
}
