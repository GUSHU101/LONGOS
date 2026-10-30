<?php

namespace Tests\Feature;

use Beike\Admin\Http\Requests\ProductRequest;
use Beike\Admin\Services\ProductFormState;
use Beike\Admin\Services\ProductService;
use Beike\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\TestCase;

class ProductManualEditingTest extends TestCase
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

    public function test_manual_edit_preserves_sku_identity_and_accepts_empty_optional_values(): void
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
            'name'             => 'Original product',
            'content'          => '',
            'meta_title'       => '',
            'meta_description' => '',
            'meta_keywords'    => '',
        ]);
        $sku = $product->skus()->create([
            'sku'          => 'MANUAL-OLD',
            'model'        => '',
            'variants'     => [],
            'images'       => [],
            'price'        => 10,
            'origin_price' => 12,
            'cost_price'   => 5,
            'weight'       => 0,
            'quantity'     => 3,
            'position'     => 0,
            'is_default'   => true,
            'active'       => true,
        ]);

        $updated = (new ProductService)->update($product, $this->payload([
            'id'       => $sku->id,
            'sku'      => 'MANUAL-NEW',
            'price'    => '19.95',
            'quantity' => '',
        ]));

        $freshSku = $updated->fresh()->skus()->sole();
        self::assertSame($sku->id, $freshSku->id);
        self::assertSame('MANUAL-NEW', $freshSku->sku);
        self::assertSame(19.95, (float) $freshSku->price);
        self::assertSame(0.0, (float) $freshSku->origin_price);
        self::assertSame(0.0, (float) $freshSku->cost_price);
        self::assertSame(0, $freshSku->quantity);
        self::assertSame('Edited manually', $updated->fresh()->descriptions()->sole()->name);
    }

    public function test_product_request_allows_optional_blanks_and_rejects_duplicate_skus(): void
    {
        $request = new ProductRequest;
        $valid   = Validator::make([
            '_product_form_complete' => '1',
            'brand_id'               => null,
            'descriptions'           => ['en' => ['name' => 'Valid product']],
            'skus'                   => [[
                'sku'          => 'ONE',
                'price'        => 10,
                'origin_price' => null,
                'cost_price'   => null,
                'quantity'     => null,
                'weight'       => null,
            ]],
        ], $request->rules());

        self::assertFalse($valid->fails(), json_encode($valid->errors()->toArray()));

        $duplicate = Validator::make([
            '_product_form_complete' => '1',
            'descriptions'           => ['en' => ['name' => 'Valid product']],
            'skus'                   => [
                ['sku' => 'Same-SKU', 'price' => 10],
                ['sku' => 'same-sku', 'price' => 12],
            ],
        ], $request->rules());

        self::assertTrue($duplicate->fails());
        self::assertArrayHasKey('skus.0.sku', $duplicate->errors()->toArray());
    }

    public function test_product_form_uses_one_native_submit_pipeline(): void
    {
        $source = file_get_contents(base_path('resources/beike/admin/views/pages/products/form/form.blade.php'));

        self::assertStringContainsString("@section('submit-form', 'app')", $source);
        self::assertStringContainsString('@submit="prepareNativeSubmit"', $source);
        self::assertStringContainsString('form.requestSubmit()', $source);
        self::assertStringContainsString('window.tinymce.triggerSave()', $source);
        self::assertStringContainsString('event.currentTarget.checkValidity()', $source);
        self::assertStringContainsString('name="_product_form_complete"', $source);
        self::assertStringContainsString("$('.submit-form-edit').on('click.product-form'", $source);
        self::assertStringNotContainsString("$('.submit-form-edit, .submit-form').on", $source);
        self::assertStringContainsString("[id]'\" :value=\"sku.id", $source);
        self::assertStringNotContainsString('submitBeforeFormat()', $source);

        $validationSource = file_get_contents(base_path('resources/beike/admin/js/bootstrap-validation.js'));
        self::assertStringContainsString('event.isDefaultPrevented()', $validationSource);
    }

    public function test_product_form_state_repairs_legacy_and_nullable_json_shapes(): void
    {
        $state = ProductFormState::make(
            null,
            json_encode([[
                'name'   => 'Color',
                'values' => [['name' => 'Red', 'image' => null]],
            ]]),
            [[
                'product_sku_id' => 42,
                'variants'       => null,
                'images'         => '/catalog/red.jpg',
                'model'          => null,
                'sku'            => 'LEGACY-RED',
                'price'          => '12.50',
                'active'         => true,
            ]],
        );

        self::assertSame([], $state['images']);
        self::assertSame(['en' => 'Color'], $state['variables'][0]['name']);
        self::assertSame(['en' => 'Red'], $state['variables'][0]['values'][0]['name']);
        self::assertSame(42, $state['skus'][0]['id']);
        self::assertSame([], $state['skus'][0]['variants']);
        self::assertSame(['/catalog/red.jpg'], $state['skus'][0]['images']);
        self::assertSame('', $state['skus'][0]['model']);
    }

    public function test_product_request_rejects_a_truncated_form(): void
    {
        $request   = new ProductRequest;
        $validator = Validator::make([
            'descriptions' => ['en' => ['name' => 'Valid product']],
            'skus'         => [['sku' => 'ONE', 'price' => 10]],
        ], $request->rules(), $request->messages());

        self::assertTrue($validator->fails());
        self::assertArrayHasKey('_product_form_complete', $validator->errors()->toArray());
    }

    private function payload(array $sku): array
    {
        return [
            'brand_id'     => null,
            'images'       => [],
            'video'        => '',
            'position'     => 0,
            'active'       => true,
            'variables'    => [],
            'tax_class_id' => 0,
            'weight'       => 0,
            'weight_class' => 'kg',
            'shipping'     => true,
            'descriptions' => [
                'en' => [
                    'name' => 'Edited manually',
                ],
            ],
            'attributes' => [],
            'skus'       => [$sku],
            'categories' => [],
            'relations'  => [],
        ];
    }
}
