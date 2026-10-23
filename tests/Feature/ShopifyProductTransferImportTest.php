<?php

namespace Tests\Feature;

use Beike\Admin\Services\RemoteImageImporter;
use Beike\Models\CategoryPath;
use Beike\Models\Product;
use Beike\Repositories\ProductRepo;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;
use Plugin\ShopifyProductTransfer\Jobs\ProcessShopifyProductImport;
use Plugin\ShopifyProductTransfer\Models\ShopifyProductImport;
use Plugin\ShopifyProductTransfer\Services\ShopifyCsvService;
use Plugin\ShopifyProductTransfer\Services\ShopifyTransferReadiness;
use ReflectionMethod;

class ShopifyProductTransferImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
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
        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));

        foreach (glob(base_path('plugins/ShopifyProductTransfer/Migrations/*.php')) ?: [] as $migrationFile) {
            $migration = require $migrationFile;
            $migration->up();
        }
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_csv_product_images_are_persisted_as_tenant_local_paths(): void
    {
        Storage::fake('upload');
        $importer = new class extends RemoteImageImporter
        {
            public function __construct() {}

            public function importInto(array $sources, string $directory, string $type = 'image'): array
            {
                return array_map(static function (string $source): array {
                    $relative = 'shopify-products/' . substr(hash('sha256', $source), 0, 20) . '.jpg';
                    Storage::disk('upload')->put($relative, 'verified-image');

                    return [
                        'origin' => $source,
                        'path'   => 'upload/' . $relative,
                        'url'    => '/upload/' . $relative,
                    ];
                }, $sources);
            }
        };
        // Use Shopify's current (2025+) product CSV column names, including
        // continuation variant and image-only rows from a real export.
        $csv = $this->csv([
            'Title', 'URL handle', 'Description', 'Type', 'Option1 name', 'Option1 value',
            'SKU', 'Price', 'Inventory quantity', 'Product image URL', 'Variant image URL', 'Status',
        ], [
            [
                'Local Shirt', 'local-shirt', '<p>Details</p><img src="https://cdn.example.test/body.jpg">',
                'Apparel > Shirts', 'Color', 'Red', 'LOCAL-SHIRT-RED', '29.90', '8',
                'https://cdn.example.test/product.jpg', 'https://cdn.example.test/variant-red.jpg', 'active',
            ],
            [
                '', 'local-shirt', '', '', 'Color', 'Blue', 'LOCAL-SHIRT-BLUE', '31.50', '5',
                '', 'https://cdn.example.test/variant-blue.jpg', 'active',
            ],
            [
                '', 'local-shirt', '', '', '', '', '', '', '',
                'https://cdn.example.test/gallery.jpg', '', '',
            ],
        ]);
        $service = new ShopifyCsvService($importer);
        $file    = UploadedFile::fake()->createWithContent('shopify.csv', $csv);
        $result  = $service->import($file, false);
        $product = Product::query()->with(['skus', 'descriptions'])->firstOrFail();

        self::assertSame(1, $result['created']);
        self::assertSame(0, $result['warnings']);
        self::assertCount(2, $product->images);
        self::assertStringStartsWith('upload/shopify-products/', $product->images[0]);
        self::assertCount(2, $product->skus);
        self::assertSame(['LOCAL-SHIRT-RED', 'LOCAL-SHIRT-BLUE'], $product->skus->sortBy('position')->pluck('sku')->values()->all());
        foreach ($product->skus as $sku) {
            self::assertStringStartsWith('upload/shopify-products/', $sku->images[0]);
        }
        self::assertStringContainsString('src="/upload/shopify-products/', $product->descriptions->first()->content);
        self::assertStringNotContainsString('cdn.example.test', json_encode([
            $product->images,
            $product->skus->pluck('images')->all(),
            $product->descriptions->first()->content,
        ], JSON_THROW_ON_ERROR));
        $leafCategoryId = $product->categories->first()->id;
        self::assertSame(2, CategoryPath::query()->where('category_id', $leafCategoryId)->count());
        $oldContent = $product->descriptions->first()->content;
        $oldFiles   = Storage::disk('upload')->allFiles('shopify-products');
        self::assertCount(5, $oldFiles);

        // Repair compatibility for categories imported by versions that did
        // not create category_paths rows.
        CategoryPath::query()->where('category_id', $leafCategoryId)->delete();
        self::assertSame(0, CategoryPath::query()->where('category_id', $leafCategoryId)->count());

        $replacement = str_replace('cdn.example.test', 'images.example.test', $csv);
        $updated     = $service->import(
            UploadedFile::fake()->createWithContent('shopify-replacement.csv', $replacement),
            true
        );
        self::assertSame(1, $updated['updated']);
        $newFiles = Storage::disk('upload')->allFiles('shopify-products');
        self::assertCount(5, $newFiles);
        self::assertSame([], array_values(array_intersect($oldFiles, $newFiles)));
        $product->refresh()->load(['skus', 'descriptions', 'categories']);
        self::assertSame(2, CategoryPath::query()->where('category_id', $leafCategoryId)->count());
        self::assertSame([], array_values(array_intersect($oldFiles, array_map(
            static fn (string $path): string => str_replace('upload/', '', $path),
            array_merge($product->images, ...$product->skus->pluck('images')->all())
        ))));
        self::assertNotSame($oldContent, $product->descriptions->first()->content);
        self::assertStringContainsString('src="/upload/shopify-products/', $product->descriptions->first()->content);

        ob_start();
        $service->export([$product->id])->sendContent();
        $exported = (string) ob_get_clean();
        self::assertStringStartsWith("\xEF\xBB\xBF", $exported);

        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $exported);
        rewind($stream);
        $headers      = fgetcsv($stream, 0, ',', '"', '');
        $headers[0]   = str_replace("\xEF\xBB\xBF", '', $headers[0]);
        $values       = fgetcsv($stream, 0, ',', '"', '');
        $secondValues = fgetcsv($stream, 0, ',', '"', '');
        $imageValues  = fgetcsv($stream, 0, ',', '"', '');
        fclose($stream);
        $row       = array_combine($headers, $values);
        $secondRow = array_combine($headers, $secondValues);
        $imageRow  = array_combine($headers, $imageValues);

        self::assertSame('Local Shirt', $row['Title']);
        self::assertSame('local-shirt', $row['URL handle']);
        self::assertSame('', $row['Product category']);
        self::assertSame('Apparel > Shirts', $row['Type']);
        self::assertSame('LOCAL-SHIRT-RED', $row['SKU']);
        self::assertSame('29.9', $row['Price']);
        self::assertSame('8', $row['Inventory quantity']);
        self::assertSame('Color', $row['Option1 name']);
        self::assertSame('Red', $row['Option1 value']);
        self::assertMatchesRegularExpression('#^https?://[^/]+/upload/shopify-products/#', $row['Product image URL']);
        self::assertMatchesRegularExpression('#^https?://[^/]+/upload/shopify-products/#', $row['Variant image URL']);
        self::assertStringContainsString('src="http', $row['Description']);
        self::assertStringNotContainsString('src="/upload/', $row['Description']);
        self::assertSame('LOCAL-SHIRT-BLUE', $secondRow['SKU']);
        self::assertSame('Blue', $secondRow['Option1 value']);
        self::assertSame('31.5', $secondRow['Price']);
        self::assertSame('', $imageRow['Title']);
        self::assertSame('2', $imageRow['Image position']);
        self::assertMatchesRegularExpression('#^https?://[^/]+/upload/shopify-products/#', $imageRow['Product image URL']);

        ob_start();
        $service->template()->sendContent();
        $template = (string) ob_get_clean();
        self::assertStringStartsWith("\xEF\xBB\xBF", $template);
        $templateStream = fopen('php://temp', 'w+b');
        fwrite($templateStream, $template);
        rewind($templateStream);
        $templateHeaders    = fgetcsv($templateStream, 0, ',', '"', '');
        $templateHeaders[0] = str_replace("\xEF\xBB\xBF", '', $templateHeaders[0]);
        fclose($templateStream);
        self::assertSame($headers, $templateHeaders);

        $roundTrip = $service->import(
            UploadedFile::fake()->createWithContent('shopify-round-trip.csv', $exported),
            true
        );
        self::assertSame(1, $roundTrip['updated']);
        self::assertSame(0, $roundTrip['failed']);
        self::assertSame(0, $roundTrip['warnings']);
    }

    public function test_failed_product_rolls_back_database_and_downloaded_files(): void
    {
        Storage::fake('upload');
        $importer = new class extends RemoteImageImporter
        {
            public function __construct() {}

            public function importInto(array $sources, string $directory, string $type = 'image'): array
            {
                Storage::disk('upload')->put($directory . '/invalid.jpg', 'verified-image');

                return [[
                    'origin' => $sources[0],
                    'path'   => 'upload/' . $directory . '/invalid.jpg',
                    'url'    => '/upload/' . $directory . '/invalid.jpg',
                ]];
            }
        };
        $csv = implode("\n", [
            'Handle,Title,Variant SKU,Variant Price,Image Src',
            'invalid-price,Invalid Price,INVALID,free,https://cdn.example.test/invalid.jpg',
        ]);

        $result = (new ShopifyCsvService($importer))->import(
            UploadedFile::fake()->createWithContent('invalid.csv', $csv),
            false
        );

        self::assertSame(1, $result['failed']);
        self::assertSame(0, Product::query()->count());
        self::assertSame([], Storage::disk('upload')->allFiles('shopify-products'));
        self::assertStringContainsString('Variant Price must be numeric', $result['errors'][0]);
    }

    public function test_replacement_with_an_incomplete_image_download_preserves_the_existing_product(): void
    {
        Storage::fake('upload');
        app('translator')->addNamespace(
            'ShopifyProductTransfer',
            base_path('plugins/ShopifyProductTransfer/Lang'),
        );
        app()->setLocale('en');
        $completeImporter = new class extends RemoteImageImporter
        {
            public function __construct() {}

            public function importInto(array $sources, string $directory, string $type = 'image'): array
            {
                return array_map(static function (string $source) use ($directory): array {
                    $relative = $directory . '/' . substr(hash('sha256', $source), 0, 20) . '.jpg';
                    Storage::disk('upload')->put($relative, 'original-image');

                    return ['origin' => $source, 'path' => 'upload/' . $relative, 'url' => '/upload/' . $relative];
                }, $sources);
            }
        };
        $original = $this->csv([
            'Title', 'URL handle', 'Description', 'SKU', 'Price', 'Product image URL', 'Status',
        ], [[
            'Original title', 'protected-product', '<p>Original description</p>', 'PROTECTED-1', '19.90',
            'https://cdn.example.test/original.jpg', 'active',
        ]]);
        $created = (new ShopifyCsvService($completeImporter))->import(
            UploadedFile::fake()->createWithContent('original.csv', $original),
            false,
        );
        self::assertSame(1, $created['created']);

        $product        = Product::query()->with(['descriptions', 'skus'])->firstOrFail();
        $originalImages = $product->images;
        $originalName   = $product->descriptions->firstOrFail()->name;
        $originalBody   = $product->descriptions->firstOrFail()->content;
        $originalPrice  = (float) $product->skus->firstOrFail()->price;
        $originalFiles  = Storage::disk('upload')->allFiles('shopify-products');

        $partialImporter = new class extends RemoteImageImporter
        {
            public function __construct() {}

            public function importInto(array $sources, string $directory, string $type = 'image'): array
            {
                $source = collect($sources)->first(
                    static fn (string $candidate): bool => str_ends_with($candidate, '/new-one.jpg')
                );
                if (! is_string($source)) {
                    return [];
                }
                $relative = $directory . '/partial-new.jpg';
                Storage::disk('upload')->put($relative, 'partial-image');

                return [[
                    'origin' => $source,
                    'path'   => 'upload/' . $relative,
                    'url'    => '/upload/' . $relative,
                ]];
            }
        };
        $replacement = $this->csv([
            'Title', 'URL handle', 'Description', 'SKU', 'Price', 'Product image URL', 'Status',
        ], [
            ['Changed title', 'protected-product', '<p>Changed description</p>', 'PROTECTED-1', '99.00', 'https://cdn.example.test/new-one.jpg', 'active'],
            ['', 'protected-product', '', '', '', 'https://cdn.example.test/new-two.jpg', ''],
        ]);
        $result = (new ShopifyCsvService($partialImporter))->import(
            UploadedFile::fake()->createWithContent('partial-replacement.csv', $replacement),
            true,
        );

        self::assertSame(0, $result['updated']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('left unchanged', $result['errors'][0]);
        $product->refresh()->load(['descriptions', 'skus']);
        self::assertSame($originalImages, $product->images);
        self::assertSame($originalName, $product->descriptions->firstOrFail()->name);
        self::assertSame($originalBody, $product->descriptions->firstOrFail()->content);
        self::assertSame($originalPrice, (float) $product->skus->firstOrFail()->price);
        self::assertSame($originalFiles, Storage::disk('upload')->allFiles('shopify-products'));
        Storage::disk('upload')->assertMissing('shopify-products/partial-new.jpg');
    }

    public function test_legacy_shopify_columns_remain_importable_after_current_format_upgrade(): void
    {
        $csv = $this->csv([
            'Handle', 'Title', 'Body (HTML)', 'Type', 'Option1 Name', 'Option1 Value',
            'Variant SKU', 'Variant Price', 'Variant Inventory Qty', 'Published',
        ], [
            ['legacy-shirt', 'Legacy Shirt', '<p>Legacy</p>', 'Shirts', 'Size', 'S', 'LEGACY-S', '20.00', '4', 'TRUE'],
            ['legacy-shirt', '', '', '', '', 'M', 'LEGACY-M', '22.00', '6', 'TRUE'],
        ]);

        $result  = (new ShopifyCsvService)->import(UploadedFile::fake()->createWithContent('legacy.csv', $csv), false);
        $product = Product::query()->with('skus')->firstOrFail();

        self::assertSame(1, $result['created']);
        self::assertSame(0, $result['failed']);
        self::assertTrue($product->active);
        self::assertSame(['LEGACY-S', 'LEGACY-M'], $product->skus->sortBy('position')->pluck('sku')->values()->all());
        self::assertSame([20.0, 22.0], $product->skus->sortBy('position')->pluck('price')->map(fn ($price): float => (float) $price)->values()->all());
    }

    public function test_vendor_and_negative_shopify_inventory_import_without_schema_failures(): void
    {
        $csv = $this->csv([
            'Title', 'URL handle', 'Vendor', 'SKU', 'Price', 'Inventory quantity', 'Status',
        ], [[
            'Backordered product', 'backordered-product', 'Source vendor', 'BACKORDERED-1', '19.00', '-4', 'unlisted',
        ]]);

        $result  = (new ShopifyCsvService)->import(UploadedFile::fake()->createWithContent('backorder.csv', $csv), false);
        $product = Product::query()->with(['brand', 'skus'])->firstOrFail();

        self::assertSame(1, $result['created']);
        self::assertSame(0, $result['failed']);
        self::assertSame('Source vendor', $product->brand->name);
        self::assertSame('', $product->brand->logo);
        self::assertFalse($product->active);
        self::assertSame(0, $product->skus->firstOrFail()->quantity);
    }

    public function test_a_reused_sku_with_a_different_handle_creates_a_separate_safe_product(): void
    {
        $service = new ShopifyCsvService;
        $first   = $this->csv(
            ['Title', 'URL handle', 'SKU', 'Price'],
            [['Original', 'original-product', 'Case-Sensitive-SKU', '10.00']],
        );
        $second = $this->csv(
            ['Title', 'URL handle', 'SKU', 'Price'],
            [['Duplicate', 'different-handle', 'case-sensitive-sku', '20.00']],
        );

        self::assertSame(1, $service->import(UploadedFile::fake()->createWithContent('first.csv', $first), false)['created']);
        $result = $service->import(UploadedFile::fake()->createWithContent('second.csv', $second), false);

        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['adjusted_skus']);
        self::assertSame(2, Product::query()->count());
        self::assertSame(
            ['Duplicate', 'Original'],
            Product::query()->with('descriptions')->get()->map(fn (Product $product): string => $product->descriptions->firstOrFail()->name)->sort()->values()->all(),
        );
        self::assertCount(2, Product::query()->with('skus')->get()->pluck('skus')->flatten()->pluck('sku')->map('strtolower')->unique());
    }

    public function test_background_import_persists_progress_and_removes_private_csv(): void
    {
        Storage::fake('local');
        Storage::fake('upload');
        $csv = $this->csv(
            ['Title', 'URL handle', 'SKU', 'Price', 'Inventory quantity', 'Status'],
            [['Queued Product', 'queued-product', 'QUEUED-1', '12.50', '3', 'active']],
        );
        $id   = '00000000-0000-4000-8000-000000000001';
        $path = 'shopify-imports/' . $id . '.csv';
        Storage::disk('local')->put($path, $csv);
        $run = ShopifyProductImport::query()->create([
            'id'               => $id,
            'admin_user_id'    => null,
            'original_name'    => 'queued.csv',
            'file_path'        => $path,
            'replace_existing' => false,
            'status'           => ShopifyProductImport::STATUS_PENDING,
        ]);

        (new ProcessShopifyProductImport($run->id))->handle(new ShopifyCsvService);

        $run->refresh();
        self::assertSame(ShopifyProductImport::STATUS_COMPLETED, $run->status);
        self::assertSame(1, $run->processed);
        self::assertSame(1, $run->total);
        self::assertSame(1, $run->result['created']);
        self::assertNotNull($run->started_at);
        self::assertNotNull($run->finished_at);
        Storage::disk('local')->assertMissing($path);
        self::assertSame('QUEUED-1', Product::query()->firstOrFail()->skus()->firstOrFail()->sku);
    }

    public function test_pending_import_can_self_recover_without_a_queue_worker(): void
    {
        Storage::fake('local');
        Storage::fake('upload');
        $id   = '00000000-0000-4000-8000-000000000096';
        $path = 'shopify-imports/' . $id . '.csv';
        Storage::disk('local')->put($path, $this->csv(
            ['Title', 'URL handle', 'SKU', 'Price', 'Inventory quantity', 'Status'],
            [['Recovered Product', 'recovered-product', 'RECOVERED-1', '18.50', '7', 'active']],
        ));
        ShopifyProductImport::query()->create([
            'id'               => $id,
            'admin_user_id'    => null,
            'original_name'    => 'recovered.csv',
            'file_path'        => $path,
            'replace_existing' => false,
            'status'           => ShopifyProductImport::STATUS_PENDING,
        ]);
        $request = \Illuminate\Http\Request::create(
            '/admin/shopify-products/imports/' . $id . '/process',
            'POST',
            server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $response = (new \Plugin\ShopifyProductTransfer\Controllers\ShopifyProductController)->processPending(
            $request,
            $id,
            new ShopifyCsvService,
            new ShopifyTransferReadiness,
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertTrue((bool) $response->getData(true)['accepted']);
        self::assertSame(ShopifyProductImport::STATUS_PENDING, ShopifyProductImport::query()->findOrFail($id)->status);
        // Laravel invokes the registered tenant-captured callback only after
        // the HTTP response. Execute its job body directly in this isolated
        // test rather than terminating the whole application (which would
        // also run unrelated deployment callbacks).
        (new ProcessShopifyProductImport($id))->handle(new ShopifyCsvService);
        self::assertSame(ShopifyProductImport::STATUS_COMPLETED, ShopifyProductImport::query()->findOrFail($id)->status);
        self::assertSame('RECOVERED-1', Product::query()->firstOrFail()->skus()->firstOrFail()->sku);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_stale_running_import_can_recover_after_its_worker_disappears(): void
    {
        Storage::fake('local');
        Storage::fake('upload');
        $id   = '00000000-0000-4000-8000-000000000095';
        $path = 'shopify-imports/' . $id . '.csv';
        Storage::disk('local')->put($path, $this->csv(
            ['Title', 'URL handle', 'SKU', 'Price'],
            [['Lease Recovery', 'lease-recovery', 'LEASE-1', '21.00']],
        ));
        ShopifyProductImport::query()->create([
            'id'               => $id,
            'admin_user_id'    => null,
            'original_name'    => 'lease.csv',
            'file_path'        => $path,
            'replace_existing' => false,
            'status'           => ShopifyProductImport::STATUS_RUNNING,
            'processed'        => 5,
            'total'            => 10,
            'result'           => ['created' => 5],
            'started_at'       => now()->subMinutes(80),
        ]);
        DB::table('shopify_product_imports')->where('id', $id)->update(['updated_at' => now()->subMinutes(80)]);
        $request = \Illuminate\Http\Request::create(
            '/admin/shopify-products/imports/' . $id . '/process',
            'POST',
            server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $response = (new \Plugin\ShopifyProductTransfer\Controllers\ShopifyProductController)->processPending(
            $request,
            $id,
            new ShopifyCsvService,
            new ShopifyTransferReadiness,
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(ShopifyProductImport::STATUS_PENDING, ShopifyProductImport::query()->findOrFail($id)->status);
        (new ProcessShopifyProductImport($id))->handle(new ShopifyCsvService);
        $run = ShopifyProductImport::query()->findOrFail($id);
        self::assertSame(ShopifyProductImport::STATUS_COMPLETED, $run->status);
        self::assertSame(1, $run->processed);
        self::assertSame(1, $run->total);
        self::assertSame('LEASE-1', Product::query()->firstOrFail()->skus()->firstOrFail()->sku);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_recovery_waits_beyond_the_configured_queue_reservation(): void
    {
        config([
            'queue.default'                          => 'database',
            'queue.connections.database.retry_after' => 7200,
        ]);
        $run = ShopifyProductImport::query()->create([
            'id'               => '00000000-0000-4000-8000-000000000068',
            'admin_user_id'    => null,
            'original_name'    => 'still-leased.csv',
            'file_path'        => 'shopify-imports/00000000-0000-4000-8000-000000000068.csv',
            'replace_existing' => false,
            'status'           => ShopifyProductImport::STATUS_RUNNING,
            'started_at'       => now()->subMinutes(80),
        ]);
        DB::table('shopify_product_imports')->where('id', $run->id)->update(['updated_at' => now()->subMinutes(80)]);
        $run->refresh();

        $controller  = new \Plugin\ShopifyProductTransfer\Controllers\ShopifyProductController;
        $lease       = new ReflectionMethod($controller, 'recoveryLeaseSeconds');
        $recoverable = new ReflectionMethod($controller, 'isRecoverableImport');

        self::assertSame(7500, $lease->invoke($controller));
        self::assertFalse($recoverable->invoke($controller, $run));

        DB::table('shopify_product_imports')->where('id', $run->id)->update(['updated_at' => now()->subMinutes(130)]);
        $run->refresh();
        self::assertTrue($recoverable->invoke($controller, $run));
    }

    public function test_orphaned_background_job_removes_its_private_csv(): void
    {
        Storage::fake('local');
        $id   = '00000000-0000-4000-8000-000000000099';
        $path = 'shopify-imports/' . $id . '.csv';
        Storage::disk('local')->put($path, 'Title,URL handle');

        (new ProcessShopifyProductImport($id))->handle(new ShopifyCsvService);

        Storage::disk('local')->assertMissing($path);
    }

    public function test_duplicate_worker_does_not_reprocess_or_delete_a_running_import(): void
    {
        Storage::fake('local');
        Storage::fake('upload');
        $id   = '00000000-0000-4000-8000-000000000097';
        $path = 'shopify-imports/' . $id . '.csv';
        Storage::disk('local')->put($path, $this->csv(
            ['Title', 'URL handle', 'SKU', 'Price'],
            [['Must not run twice', 'duplicate-worker', 'DUPLICATE-1', '10.00']],
        ));
        $run = ShopifyProductImport::query()->create([
            'id'               => $id,
            'admin_user_id'    => null,
            'original_name'    => 'duplicate.csv',
            'file_path'        => $path,
            'replace_existing' => false,
            'status'           => ShopifyProductImport::STATUS_RUNNING,
            'started_at'       => now(),
        ]);

        (new ProcessShopifyProductImport($id))->handle(new ShopifyCsvService);

        self::assertSame(ShopifyProductImport::STATUS_RUNNING, $run->refresh()->status);
        self::assertSame(0, Product::query()->count());
        Storage::disk('local')->assertExists($path);
    }

    public function test_failed_orphaned_background_job_removes_its_private_csv(): void
    {
        Storage::fake('local');
        $id   = '00000000-0000-4000-8000-000000000098';
        $path = 'shopify-imports/' . $id . '.csv';
        Storage::disk('local')->put($path, 'Title,URL handle');

        (new ProcessShopifyProductImport($id))->failed(new \RuntimeException('worker stopped'));

        Storage::disk('local')->assertMissing($path);
    }

    public function test_shopify_duplicate_skus_are_namespaced_without_losing_the_source_value(): void
    {
        $csv = $this->csv([
            'Title', 'URL handle', 'SKU', 'Price',
        ], [
            ['First product', 'first-product', 'SHARED-SKU', '10.00'],
            ['Second product', 'second-product', 'SHARED-SKU', '12.00'],
        ]);

        $service = new ShopifyCsvService;
        $result  = $service->import(UploadedFile::fake()->createWithContent('duplicate-sku.csv', $csv), false);

        self::assertSame(2, $result['created']);
        self::assertSame(0, $result['failed']);
        self::assertSame(2, $result['adjusted_skus']);
        self::assertSame(2, Product::query()->count());
        self::assertCount(2, Product::query()->with('skus')->get()->pluck('skus')->flatten()->pluck('sku')->unique());
        self::assertSame(
            ['SHARED-SKU', 'SHARED-SKU'],
            DB::table('shopify_product_variant_skus')->orderBy('id')->pluck('source_sku')->all(),
        );

        ob_start();
        $service->export()->sendContent();
        $exported = (string) ob_get_clean();
        self::assertSame(2, substr_count($exported, 'SHARED-SKU'));
    }

    public function test_duplicate_skus_inside_one_multi_variant_product_import_and_replace_safely(): void
    {
        $service = new ShopifyCsvService;
        $csv     = $this->csv([
            'Title', 'URL handle', 'Option1 name', 'Option1 value', 'SKU', 'Price',
        ], [
            ['Shared variants', 'shared-variants', 'Device', 'Phone A', 'SAME-SKU', '10.00'],
            ['', 'shared-variants', 'Device', 'Phone B', 'SAME-SKU', '12.00'],
        ]);

        $created = $service->import(UploadedFile::fake()->createWithContent('shared-variants.csv', $csv), false);
        self::assertSame(1, $created['created']);
        self::assertSame(2, $created['adjusted_skus']);
        $product = Product::query()->with('skus')->firstOrFail();
        self::assertCount(2, $product->skus);
        self::assertCount(2, $product->skus->pluck('sku')->unique());
        self::assertSame(['SAME-SKU', 'SAME-SKU'], DB::table('shopify_product_variant_skus')->pluck('source_sku')->all());

        $replacement = str_replace('Shared variants', 'Updated shared variants', $csv);
        $updated     = $service->import(
            UploadedFile::fake()->createWithContent('shared-variants-updated.csv', $replacement),
            true,
        );
        self::assertSame(1, $updated['updated']);
        self::assertSame(2, $updated['adjusted_skus']);
        self::assertSame(1, Product::query()->count());
        self::assertSame('Updated shared variants', Product::query()->firstOrFail()->descriptions()->firstOrFail()->name);
        self::assertCount(2, Product::query()->firstOrFail()->skus()->pluck('sku')->unique());
    }

    public function test_reimport_restores_a_soft_deleted_multi_variant_product_without_sku_collisions(): void
    {
        $service = new ShopifyCsvService;
        $csv     = $this->csv([
            'Title', 'URL handle', 'Option1 name', 'Option1 value', 'SKU', 'Price',
        ], [
            ['Recoverable case', 'recoverable-case', 'Device', 'Phone A', 'REUSED-SKU', '10.00'],
            ['', 'recoverable-case', 'Device', 'Phone B', 'REUSED-SKU', '12.00'],
            ['', 'recoverable-case', 'Device', 'Phone C', 'REUSED-SKU', '14.00'],
        ]);

        $created = $service->import(UploadedFile::fake()->createWithContent('recoverable.csv', $csv), false);
        self::assertSame(1, $created['created']);
        self::assertSame(3, $created['adjusted_skus']);

        $original   = Product::query()->firstOrFail();
        $originalId = (int) $original->id;
        $original->delete();
        self::assertSame(0, Product::query()->count());
        self::assertSame(1, Product::withTrashed()->count());

        $restored = $service->import(UploadedFile::fake()->createWithContent('recoverable-again.csv', $csv), false);

        self::assertSame(0, $restored['created']);
        self::assertSame(1, $restored['updated']);
        self::assertSame(0, $restored['skipped']);
        self::assertSame(0, $restored['failed']);
        self::assertSame($originalId, (int) Product::query()->firstOrFail()->id);
        self::assertSame(1, Product::withTrashed()->count());
        self::assertCount(3, Product::query()->firstOrFail()->skus()->pluck('sku')->unique());
        self::assertSame(3, DB::table('shopify_product_variant_skus')->count());
    }

    public function test_reimport_repairs_identity_mappings_left_by_a_historical_hard_delete(): void
    {
        $service = new ShopifyCsvService;
        $csv     = $this->csv(['Title', 'URL handle', 'SKU', 'Price'], [[
            'Recreated case', 'recreated-case', 'RECREATED-SKU', '20.00',
        ]]);

        self::assertSame(1, $service->import(
            UploadedFile::fake()->createWithContent('recreated.csv', $csv),
            false,
        )['created']);
        Product::query()->firstOrFail()->delete();
        ProductRepo::forceDeleteTrashed();
        self::assertSame(0, Product::withTrashed()->count());
        self::assertSame(1, DB::table('shopify_product_variant_skus')->count());

        $recreated = $service->import(
            UploadedFile::fake()->createWithContent('recreated-again.csv', $csv),
            false,
        );

        self::assertSame(1, $recreated['created']);
        self::assertSame(0, $recreated['failed']);
        self::assertSame(1, Product::query()->count());
        self::assertSame(1, DB::table('shopify_product_variant_skus')->count());
        self::assertSame(
            (int) Product::query()->firstOrFail()->id,
            (int) DB::table('shopify_product_variant_skus')->value('product_id'),
        );
    }

    public function test_permanent_product_deletion_removes_shopify_identity_mappings(): void
    {
        (new \Plugin\ShopifyProductTransfer\Bootstrap)->boot();
        $csv = $this->csv(['Title', 'URL handle', 'SKU', 'Price'], [[
            'Disposable case', 'disposable-case', 'DISPOSABLE-SKU', '20.00',
        ]]);

        self::assertSame(1, (new ShopifyCsvService)->import(
            UploadedFile::fake()->createWithContent('disposable.csv', $csv),
            false,
        )['created']);
        Product::query()->firstOrFail()->delete();
        ProductRepo::forceDeleteTrashed();

        self::assertSame(0, Product::withTrashed()->count());
        self::assertSame(0, DB::table('shopify_product_handles')->count());
        self::assertSame(0, DB::table('shopify_product_variant_skus')->count());
    }

    public function test_blank_replacement_inventory_preserves_stock_but_explicit_zero_clears_it(): void
    {
        $service = new ShopifyCsvService;
        $headers = [
            'Title', 'URL handle', 'Option1 name', 'Option1 value', 'SKU', 'Price', 'Inventory quantity',
        ];
        $initial = $this->csv($headers, [
            ['Inventory product', 'inventory-product', 'Color', 'Red', 'INV-RED', '10.00', '8'],
            ['', 'inventory-product', 'Color', 'Blue', 'INV-BLUE', '12.00', '5'],
        ]);

        self::assertSame(1, $service->import(
            UploadedFile::fake()->createWithContent('inventory-initial.csv', $initial),
            false,
        )['created']);

        $blankReplacement = $this->csv($headers, [
            ['Inventory product updated', 'inventory-product', 'Color', 'Red', 'INV-RED', '11.00', ''],
            ['', 'inventory-product', 'Color', 'Blue', 'INV-BLUE', '13.00', ''],
        ]);
        self::assertSame(1, $service->import(
            UploadedFile::fake()->createWithContent('inventory-blank.csv', $blankReplacement),
            true,
        )['updated']);
        self::assertSame(
            ['INV-BLUE' => 5, 'INV-RED' => 8],
            Product::query()->firstOrFail()->skus()->orderBy('sku')->pluck('quantity', 'sku')->map(fn ($value) => (int) $value)->all(),
        );

        // Merchants often rename a SKU while editing the same variant. The
        // option combination is the stable fallback identity for preserving
        // inventory when the replacement cell remains blank.
        $renamedReplacement = $this->csv($headers, [
            ['Inventory product renamed', 'inventory-product', 'Color', 'Red', 'INV-RED-V2', '11.00', ''],
            ['', 'inventory-product', 'Color', 'Blue', 'INV-BLUE', '13.00', ''],
        ]);
        self::assertSame(1, $service->import(
            UploadedFile::fake()->createWithContent('inventory-renamed.csv', $renamedReplacement),
            true,
        )['updated']);
        self::assertSame(
            ['INV-BLUE' => 5, 'INV-RED-V2' => 8],
            Product::query()->firstOrFail()->skus()->orderBy('sku')->pluck('quantity', 'sku')->map(fn ($value) => (int) $value)->all(),
        );

        $zeroReplacement = $this->csv($headers, [
            ['Inventory product updated again', 'inventory-product', 'Color', 'Red', 'INV-RED-V2', '11.00', '0'],
            ['', 'inventory-product', 'Color', 'Blue', 'INV-BLUE', '13.00', ''],
        ]);
        self::assertSame(1, $service->import(
            UploadedFile::fake()->createWithContent('inventory-zero.csv', $zeroReplacement),
            true,
        )['updated']);
        self::assertSame(
            ['INV-BLUE' => 5, 'INV-RED-V2' => 0],
            Product::query()->firstOrFail()->skus()->orderBy('sku')->pluck('quantity', 'sku')->map(fn ($value) => (int) $value)->all(),
        );
    }

    public function test_replacement_uses_handle_when_two_products_share_a_shopify_sku(): void
    {
        $service = new ShopifyCsvService;
        $csv     = $this->csv(['Title', 'URL handle', 'SKU', 'Price'], [
            ['First product', 'first-product', 'SHARED-SKU', '10.00'],
            ['Second product', 'second-product', 'SHARED-SKU', '12.00'],
        ]);
        self::assertSame(2, $service->import(UploadedFile::fake()->createWithContent('both.csv', $csv), false)['created']);

        $replacement = $this->csv(['Title', 'URL handle', 'SKU', 'Price'], [
            ['First product updated', 'first-product', 'SHARED-SKU', '18.00'],
        ]);
        $result = $service->import(UploadedFile::fake()->createWithContent('first-only.csv', $replacement), true);

        self::assertSame(1, $result['updated']);
        self::assertSame(0, $result['failed']);
        self::assertSame(2, Product::query()->count());
        $products = Product::query()->with(['descriptions', 'skus'])->get()->keyBy(
            fn (Product $product): string => $product->descriptions->firstOrFail()->name,
        );
        self::assertSame(18.0, (float) $products['First product updated']->skus->firstOrFail()->price);
        self::assertSame(12.0, (float) $products['Second product']->skus->firstOrFail()->price);
    }

    public function test_long_shopify_sku_is_cart_safe_and_restored_on_export(): void
    {
        $service   = new ShopifyCsvService;
        $sourceSku = '14:193#Black;10:529#For Samsung S24Ultra';
        $csv       = $this->csv(['Title', 'URL handle', 'SKU', 'Price'], [[
            'Long source SKU', 'long-source-sku', $sourceSku, '20.00',
        ]]);

        $result    = $service->import(UploadedFile::fake()->createWithContent('long-source.csv', $csv), false);
        $storedSku = Product::query()->firstOrFail()->skus()->firstOrFail()->sku;

        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['adjusted_skus']);
        self::assertLessThanOrEqual(32, mb_strlen($storedSku));
        self::assertNotSame($sourceSku, $storedSku);
        self::assertSame($sourceSku, DB::table('shopify_product_variant_skus')->value('source_sku'));

        ob_start();
        $service->export()->sendContent();
        $exported = (string) ob_get_clean();
        self::assertStringContainsString($sourceSku, $exported);
    }

    public function test_malformed_headers_and_overlong_skus_fail_with_actionable_errors(): void
    {
        try {
            (new ShopifyCsvService)->import(
                UploadedFile::fake()->createWithContent('invalid-header.csv', "Title,,SKU\nBroken,,BROKEN"),
                false,
            );
            self::fail('An empty column header must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('empty column header', $exception->getMessage());
        }

        $result = (new ShopifyCsvService)->import(
            UploadedFile::fake()->createWithContent('long-sku.csv', $this->csv(
                ['Title', 'URL handle', 'SKU', 'Price'],
                [['Long SKU', 'long-sku', str_repeat('S', 256), '10.00']],
            )),
            false,
        );
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('Variant SKU cannot exceed 255 characters', $result['errors'][0]);
        self::assertSame(0, Product::query()->count());
    }

    public function test_readiness_service_repairs_missing_plugin_tables_without_manual_commands(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('shopify_product_variant_skus');
        \Illuminate\Support\Facades\Schema::dropIfExists('shopify_product_imports');
        \Illuminate\Support\Facades\Schema::dropIfExists('shopify_product_handles');

        (new ShopifyTransferReadiness)->ensureReady();

        self::assertTrue(\Illuminate\Support\Facades\Schema::hasTable('shopify_product_handles'));
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasTable('shopify_product_variant_skus'));
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasTable('shopify_product_imports'));
    }

    public function test_admin_import_page_compiles_with_runtime_upload_limit_message(): void
    {
        $source = file_get_contents(base_path('plugins/ShopifyProductTransfer/Views/admin/index.blade.php'));
        self::assertIsString($source);

        $compiled = app('blade.compiler')->compileString($source);
        $parser   = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();

        self::assertNotNull($parser->parse($compiled));
        self::assertStringContainsString('json_encode($fileTooLargeMessage', $compiled);
    }

    public function test_every_plugin_blade_view_compiles_to_valid_php(): void
    {
        $directory = new \RecursiveDirectoryIterator(
            base_path('plugins/ShopifyProductTransfer/Views'),
            \FilesystemIterator::SKIP_DOTS,
        );
        $files  = new \RecursiveIteratorIterator($directory);
        $parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
        $count  = 0;

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, $file->getPathname());
            self::assertNotNull(
                $parser->parse(app('blade.compiler')->compileString($source)),
                $file->getPathname(),
            );
            $count++;
        }

        self::assertGreaterThanOrEqual(2, $count);
    }

    public function test_readiness_service_upgrades_a_partial_legacy_import_table(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('shopify_product_imports');
        \Illuminate\Support\Facades\Schema::create('shopify_product_imports', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('total')->default(0);
        });

        (new ShopifyTransferReadiness)->ensureReady();

        self::assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('shopify_product_imports', [
            'id', 'admin_user_id', 'original_name', 'file_path', 'replace_existing',
            'status', 'processed', 'total', 'result', 'error', 'started_at',
            'finished_at', 'created_at', 'updated_at',
        ]));
        foreach ([
            ['admin_user_id', 'created_at'],
            ['status', 'updated_at'],
            ['status', 'finished_at'],
        ] as $columns) {
            self::assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('shopify_product_imports', $columns));
        }
    }

    public function test_plugin_support_tables_remain_isolated_by_tenant_prefix(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'longos-shopify-');
        self::assertIsString($database);

        try {
            foreach (['tenant_a_', 'tenant_b_'] as $prefix) {
                config([
                    'database.connections.saas.database' => $database,
                    'database.connections.saas.prefix'   => $prefix,
                ]);
                DB::purge('saas');
                DB::setDefaultConnection('saas');

                (new ShopifyTransferReadiness)->ensureReady();
                self::assertTrue(\Illuminate\Support\Facades\Schema::hasTable('shopify_product_handles'));
                self::assertTrue(\Illuminate\Support\Facades\Schema::hasTable('shopify_product_variant_skus'));
                self::assertTrue(\Illuminate\Support\Facades\Schema::hasTable('shopify_product_imports'));
            }

            $tables = collect(DB::select("select name from sqlite_master where type = 'table'"))
                ->pluck('name')
                ->all();
            foreach (['tenant_a_', 'tenant_b_'] as $prefix) {
                self::assertContains($prefix . 'shopify_product_handles', $tables);
                self::assertContains($prefix . 'shopify_product_variant_skus', $tables);
                self::assertContains($prefix . 'shopify_product_imports', $tables);
            }
        } finally {
            DB::purge('saas');
            @unlink($database);
        }
    }

    public function test_background_job_rejects_an_invalid_private_file_path(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('unrelated.csv', 'private-data');
        $run = ShopifyProductImport::query()->create([
            'id'               => '00000000-0000-4000-8000-000000000088',
            'admin_user_id'    => null,
            'original_name'    => 'unsafe.csv',
            'file_path'        => '../unrelated.csv',
            'replace_existing' => false,
            'status'           => ShopifyProductImport::STATUS_PENDING,
        ]);

        (new ProcessShopifyProductImport($run->id))->handle(new ShopifyCsvService);

        $run->refresh();
        self::assertSame(ShopifyProductImport::STATUS_FAILED, $run->status);
        Storage::disk('local')->assertExists('unrelated.csv');
    }

    public function test_expired_import_history_removes_its_private_csv_before_the_record(): void
    {
        Storage::fake('local');
        $id   = '00000000-0000-4000-8000-000000000077';
        $path = 'shopify-imports/' . $id . '.csv';
        Storage::disk('local')->put($path, 'expired-data');
        ShopifyProductImport::query()->create([
            'id'               => $id,
            'admin_user_id'    => null,
            'original_name'    => 'expired.csv',
            'file_path'        => $path,
            'replace_existing' => false,
            'status'           => ShopifyProductImport::STATUS_COMPLETED,
            'finished_at'      => now()->subDays(31),
        ]);

        $method = new ReflectionMethod(new \Plugin\ShopifyProductTransfer\Controllers\ShopifyProductController, 'recentImports');
        $method->invoke(new \Plugin\ShopifyProductTransfer\Controllers\ShopifyProductController);

        Storage::disk('local')->assertMissing($path);
        self::assertFalse(ShopifyProductImport::query()->whereKey($id)->exists());
    }

    public function test_header_only_csv_is_not_reported_as_a_successful_import(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not contain any product rows');

        (new ShopifyCsvService)->import(
            UploadedFile::fake()->createWithContent('empty.csv', "Title,URL handle,SKU,Price\n"),
            false,
        );
    }

    /** @param array<int, string> $headers @param array<int, array<int, string>> $rows */
    private function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        fputcsv($stream, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return (string) $csv;
    }
}
