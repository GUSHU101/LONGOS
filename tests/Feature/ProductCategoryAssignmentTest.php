<?php

namespace Tests\Feature;

use Beike\Admin\Http\Controllers\CategoryController;
use Beike\Admin\Services\ProductCategoryAssignmentService;
use Beike\Admin\Services\ProductService;
use Beike\Models\Brand;
use Beike\Models\Category;
use Beike\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class ProductCategoryAssignmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'app.url'                   => 'https://longos.test',
            'database.default'          => 'saas',
            'database.connections.saas' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('saas');
        DB::setDefaultConnection('saas');

        self::assertSame(0, Artisan::call('migrate:fresh', [
            '--force'          => true,
            '--no-interaction' => true,
        ]));
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_local_smart_selection_scores_relevant_products_and_ignores_unrelated_products(): void
    {
        $category = $this->category('Running Shoes', 'sneaker, trainer');
        $exact    = $this->product('Lightweight Running Shoes', 'Breathable shoes for daily runs.');
        $synonym  = $this->product('Street Sneaker', 'A comfortable everyday trainer.');
        $this->product('Insulated Travel Mug', 'Sneaker trainer text in the description must not classify this product.');

        $result = (new ProductCategoryAssignmentService)->suggest($category, null, null, 100);
        $ids    = collect($result['candidates'])->pluck('id')->all();

        self::assertContains($exact->id, $ids);
        self::assertContains($synonym->id, $ids);
        self::assertCount(2, $ids);
        self::assertSame(3, $result['scanned']);
        self::assertSame(2, $result['total_matches']);

        $this->assertBrandRuleMatchesAndAutoAssigns();
        $this->assertBatchAssignmentIsIdempotent();
        $this->assertProductServiceAppliesSavedRule();
        $this->assertAdminPresentationContracts();
        $this->assertCategoryIndexDegradesDuringPartialUpgrade();
        $this->assertInactiveCategoriesAreRejected();
    }

    private function assertCategoryIndexDegradesDuringPartialUpgrade(): void
    {
        $this->category('Existing category');
        Schema::table('categories', static function ($table): void {
            $table->dropColumn([
                'auto_assign_enabled',
                'auto_assign_keywords',
                'auto_assign_brand_ids',
            ]);
        });

        $service = new ProductCategoryAssignmentService;
        self::assertFalse($service->rulesAvailable());

        $view = (new CategoryController)->index($service);
        self::assertSame('admin::pages.categories.index', $view->getName());
        self::assertFalse($view->getData()['smart_assignment_available']);
        self::assertSame('', $view->getData()['smart_products_url_template']);
        self::assertNotEmpty($view->getData()['categories']->resource);
    }

    private function assertBrandRuleMatchesAndAutoAssigns(): void
    {
        $selectedBrand = $this->brand('Selected Brand');
        $otherBrand    = $this->brand('Other Brand');
        $category      = $this->category('Brand collection');
        $matching      = $this->product('No keyword in this title', '', $selectedBrand->id);
        $this->product('Another unrelated title', '', $otherBrand->id);
        $service = new ProductCategoryAssignmentService;

        $service->saveRule($category, '', [$selectedBrand->id], true);
        $preview = $service->suggest($category->fresh('descriptions'), '', [$selectedBrand->id], 100);
        $result  = $service->autoAssign($matching);

        self::assertSame([$matching->id], collect($preview['candidates'])->pluck('id')->all());
        self::assertSame(1, $result['inserted']);
        self::assertTrue($matching->fresh()->categories->contains($category->id));
    }

    private function assertBatchAssignmentIsIdempotent(): void
    {
        $firstCategory  = $this->category('Shoes');
        $secondCategory = $this->category('Sale');
        $firstProduct   = $this->product('Runner One');
        $secondProduct  = $this->product('Runner Two');
        $firstProduct->categories()->attach($firstCategory->id);
        $assignmentCountBefore = DB::table('product_categories')->count();

        $service = new ProductCategoryAssignmentService;
        $first   = $service->assign([$firstProduct->id, $secondProduct->id], [$secondCategory->id]);
        $repeat  = $service->assign([$firstProduct->id, $secondProduct->id], [$secondCategory->id]);

        self::assertSame(2, $first['inserted']);
        self::assertSame(0, $repeat['inserted']);
        self::assertSame($assignmentCountBefore + 2, DB::table('product_categories')->count());
        self::assertEqualsCanonicalizing(
            [$firstCategory->id, $secondCategory->id],
            $firstProduct->fresh()->categories->pluck('id')->all(),
        );
    }

    private function assertProductServiceAppliesSavedRule(): void
    {
        $category = $this->category('Running products');
        (new ProductCategoryAssignmentService)->saveRule($category, 'trail runner', [], true);

        $product = (new ProductService)->create([
            'brand_id'     => 0,
            'images'       => [],
            'video'        => '',
            'position'     => 0,
            'active'       => true,
            'variables'    => '[]',
            'tax_class_id' => 0,
            'weight'       => 0,
            'weight_class' => 'kg',
            'shipping'     => true,
            'descriptions' => [
                'en' => [
                    'name'             => 'Lightweight Trail Runner Shoes',
                    'content'          => '',
                    'meta_title'       => '',
                    'meta_description' => '',
                    'meta_keywords'    => '',
                ],
            ],
            'attributes' => [],
            'skus'       => [[
                'sku'          => 'AUTO-RUNNER-1',
                'model'        => '',
                'variants'     => [],
                'images'       => [],
                'price'        => 10,
                'origin_price' => 10,
                'cost_price'   => 5,
                'weight'       => 0,
                'quantity'     => 3,
                'is_default'   => true,
                'active'       => true,
            ]],
            'categories' => [],
            'relations'  => [],
        ]);

        self::assertTrue($product->fresh()->categories->contains($category->id));
    }

    private function assertInactiveCategoriesAreRejected(): void
    {
        $category = $this->category('Disabled category', '', false);
        $product  = $this->product('Candidate');
        $service  = new ProductCategoryAssignmentService;

        try {
            $service->assign([$product->id], [$category->id]);
            self::fail('Inactive categories must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('category_ids', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $service->suggest($category);
    }

    private function assertAdminPresentationContracts(): void
    {
        $routes         = file_get_contents(base_path('beike/Admin/Routes/admin.php'));
        $productsView   = file_get_contents(base_path('resources/beike/admin/views/pages/products/index.blade.php'));
        $categoriesView = file_get_contents(base_path('resources/beike/admin/views/pages/categories/index.blade.php'));

        self::assertStringContainsString("name('products.batch_categories')", $routes);
        self::assertStringContainsString("name('categories.smart_products')", $routes);
        self::assertStringContainsString("name('categories.smart_products.rule')", $routes);
        self::assertStringContainsString('openBatchCategoryDialog', $productsView);
        self::assertStringContainsString('openSmartProducts', $categoriesView);
        self::assertStringContainsString('autoAssign($product)', file_get_contents(base_path('beike/Admin/Services/ProductService.php')));
    }

    private function category(string $name, string $keywords = '', bool $active = true): Category
    {
        $category = Category::query()->create([
            'parent_id' => 0,
            'position'  => 0,
            'active'    => $active,
        ]);
        $category->descriptions()->create([
            'locale'           => 'en',
            'name'             => $name,
            'content'          => '',
            'meta_title'       => '',
            'meta_description' => '',
            'meta_keywords'    => $keywords,
        ]);

        return $category->fresh('descriptions');
    }

    private function brand(string $name): Brand
    {
        return Brand::query()->create([
            'name'       => $name,
            'first'      => mb_substr($name, 0, 1),
            'logo'       => '',
            'sort_order' => 0,
            'active'     => true,
        ]);
    }

    private function product(string $name, string $content = '', int $brandId = 0): Product
    {
        $product = Product::query()->create([
            'brand_id'     => $brandId,
            'images'       => [],
            'video'        => '',
            'position'     => 0,
            'active'       => true,
            'variables'    => [],
            'tax_class_id' => 0,
            'weight'       => 0,
            'weight_class' => 'kg',
            'shipping'     => true,
        ]);
        $product->descriptions()->create([
            'locale'           => 'en',
            'name'             => $name,
            'content'          => $content,
            'meta_title'       => '',
            'meta_description' => '',
            'meta_keywords'    => '',
        ]);

        return $product;
    }
}
