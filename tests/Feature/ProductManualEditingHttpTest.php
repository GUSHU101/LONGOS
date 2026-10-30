<?php

namespace Tests\Feature;

use Beike\Models\AdminUser;
use Beike\Models\Language;
use Beike\Models\Product;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class ProductManualEditingHttpTest extends TestCase
{
    public function createApplication()
    {
        // Keep optional plugin migrations out of this isolated core-editor
        // database. PHPUnit boots in console mode, so the normal migration
        // guard can be used while the application providers are registered.
        $arguments          = $_SERVER['argv'] ?? [];
        $_SERVER['argv'][1] = 'migrate:fresh';

        try {
            $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
            $app->make(ConsoleKernel::class)->bootstrap();
        } finally {
            $_SERVER['argv'] = $arguments;
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url'                    => 'https://longos.test',
            'database.default'           => 'saas',
            'database.connections.saas'  => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'session.driver'              => 'array',
        ]);
        DB::purge('saas');
        DB::setDefaultConnection('saas');

        self::assertSame(0, Artisan::call('migrate:fresh', [
            '--force'          => true,
            '--no-interaction' => true,
        ]));

        Language::query()->create([
            'name'       => 'English',
            'code'       => 'en',
            'locale'     => 'en-US',
            'image'      => '',
            'sort_order' => 0,
            'status'     => 1,
        ]);
    }

    public function test_root_administrator_can_submit_a_complete_manual_product_edit(): void
    {
        $admin = AdminUser::query()->create([
            'name'     => 'Root',
            'email'    => 'root@longos.test',
            'password' => bcrypt('test-password'),
            'active'   => true,
            'locale'   => 'en',
        ]);
        $product = $this->product();
        $sku     = $product->skus()->sole();
        $editUrl = admin_route('products.edit', $product);

        view()->share('languages', languages());
        view()->share('admin_base_url', admin_route('home.index'));
        view()->share('shop_base_url', shop_route('home.index'));
        $this->actingAs($admin, AdminUser::AUTH_GUARD);
        $editPage = $this->get($editUrl);
        $editPage->assertOk();
        $editPage->assertSee('name="_product_form_complete"', false);
        $editPage->assertSee('HTTP-BEFORE');

        $response = $this
            ->from($editUrl)
            ->put(admin_route('products.update', $product), [
                '_product_form_complete' => '1',
                'descriptions'           => [
                    'en' => [
                        'name'             => 'Saved through HTTP',
                        'content'          => '<p>Updated details</p>',
                        'meta_title'       => '',
                        'meta_description' => '',
                        'meta_keywords'    => '',
                    ],
                ],
                'brand_id'     => null,
                'images'       => [],
                'video'        => '',
                'position'     => 0,
                'active'       => 1,
                'variables'    => '[]',
                'tax_class_id' => 0,
                'weight'       => 0,
                'weight_class' => 'kg',
                'shipping'     => 1,
                'attributes'   => [],
                'categories'   => [],
                'relations'    => [],
                'skus'         => [[
                    'id'           => $sku->id,
                    'sku'          => 'HTTP-SAVED',
                    'model'        => '',
                    'price'        => '24.95',
                    'origin_price' => '',
                    'cost_price'   => '',
                    'quantity'     => '8',
                    'weight'       => '',
                    'images'       => [],
                    'variants'     => [],
                    'is_default'   => 1,
                    'active'       => 1,
                ]],
            ]);

        $response->assertRedirect($editUrl);
        $response->assertSessionHasNoErrors();
        self::assertSame('Saved through HTTP', $product->fresh()->descriptions()->sole()->name);
        self::assertSame('<p>Updated details</p>', $product->fresh()->descriptions()->sole()->content);
        self::assertSame('HTTP-SAVED', $product->fresh()->skus()->sole()->sku);
        self::assertSame(24.95, (float) $product->fresh()->skus()->sole()->price);
    }

    private function product(): Product
    {
        $product = Product::query()->create([
            'brand_id'     => 0,
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
            'name'             => 'Before HTTP edit',
            'content'          => '',
            'meta_title'       => '',
            'meta_description' => '',
            'meta_keywords'    => '',
        ]);
        $product->skus()->create([
            'sku'          => 'HTTP-BEFORE',
            'model'        => '',
            'variants'     => [],
            'images'       => [],
            'price'        => 10,
            'origin_price' => 0,
            'cost_price'   => 0,
            'weight'       => 0,
            'quantity'     => 3,
            'position'     => 0,
            'is_default'   => true,
            'active'       => true,
        ]);

        return $product;
    }
}
