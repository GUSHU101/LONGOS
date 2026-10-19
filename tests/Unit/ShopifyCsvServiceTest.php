<?php

namespace Tests\Unit;

use Beike\Admin\Services\RemoteImageImporter;
use PHPUnit\Framework\TestCase;
use Plugin\ShopifyProductTransfer\Controllers\ShopifyProductController;
use Plugin\ShopifyProductTransfer\Services\ShopifyCsvService;
use ReflectionClass;
use ReflectionMethod;

class ShopifyCsvServiceTest extends TestCase
{
    public function test_delimiter_detection_uses_known_headers_instead_of_raw_punctuation(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "Handle;Title;\"Body (HTML)\";\"Variant SKU\"\n");
        rewind($stream);

        self::assertSame(';', $this->invoke('detectDelimiter', $stream));
        self::assertSame(0, ftell($stream), 'Delimiter inspection must rewind the import stream.');
        fclose($stream);
    }

    public function test_imported_html_drops_executable_and_secondary_url_attributes(): void
    {
        $html  = '<p style="color:red">Safe</p><img src="https://cdn.example.test/a.jpg" srcset="javascript:alert(1)" onerror="alert(2)"><a href="javascript:alert(3)">Bad</a><a href="/pages/size-guide">Guide</a><a href="mailto:help@example.com">Email</a>';
        $clean = $this->invoke('cleanHtml', $html);

        self::assertStringContainsString('https://cdn.example.test/a.jpg', $clean);
        self::assertStringNotContainsString('javascript:', $clean);
        self::assertStringNotContainsString('onerror', $clean);
        self::assertStringNotContainsString('srcset', $clean);
        self::assertStringNotContainsString('style=', $clean);
        self::assertStringContainsString('href="/pages/size-guide"', $clean);
        self::assertStringContainsString('href="mailto:help@example.com"', $clean);
    }

    public function test_export_prefixes_formula_like_text_but_keeps_negative_numbers(): void
    {
        $stream = fopen('php://temp', 'w+b');
        $this->invoke('writeCsvRow', $stream, ['=HYPERLINK("https://evil.test")', '@SUM(1,1)', '-42.5']);
        rewind($stream);
        $row = fgetcsv($stream, 0, ',', '"', '');
        fclose($stream);

        self::assertSame("'=HYPERLINK(\"https://evil.test\")", $row[0]);
        self::assertSame("'@SUM(1,1)", $row[1]);
        self::assertSame('-42.5', $row[2]);
    }

    public function test_non_latin_titles_receive_stable_non_empty_handles(): void
    {
        $handle = $this->invoke('canonicalHandle', '', '测试商品');

        self::assertMatchesRegularExpression('/^shopify-[a-f0-9]{24}$/', $handle);
        self::assertSame($handle, $this->invoke('canonicalHandle', '', '测试商品'));
    }

    public function test_current_and_legacy_shopify_headers_share_one_canonical_schema(): void
    {
        $pairs = [
            ['URL handle', 'Handle'],
            ['Description', 'Body (HTML)'],
            ['SKU', 'Variant SKU'],
            ['Price', 'Variant Price'],
            ['Inventory quantity', 'Variant Inventory Qty'],
            ['Product image URL', 'Image Src'],
            ['Variant image URL', 'Variant Image'],
            ['Compare-at price', 'Variant Compare-at Price'],
            ['Barcodes', 'Variant Barcode'],
            ['Variant Barcodes', 'Variant Barcode'],
            ['Option1 LinkedTo', 'Option1 Linked To'],
        ];

        foreach ($pairs as [$current, $legacy]) {
            self::assertSame($this->invoke('headerKey', $legacy), $this->invoke('headerKey', $current));
        }

        $values = $this->invoke('exportRowValues', [
            'Handle'        => 'current-handle',
            'Title'         => 'Current product',
            'Variant SKU'   => 'CURRENT-1',
            'Variant Price' => '19.90',
        ]);
        self::assertContains('current-handle', $values);
        self::assertContains('Current product', $values);
        self::assertContains('CURRENT-1', $values);
        self::assertContains('19.90', $values);

        $headers = (new ReflectionClass(ShopifyCsvService::class))->getConstant('HEADERS');
        self::assertContains('Barcodes', $headers);
        self::assertContains('Option1 LinkedTo', $headers);
        self::assertContains('Packed product dimension unit', $headers);
        self::assertContains('Google Shopping / MPN', $headers);
        self::assertNotContains('Variant Barcodes', $headers);
        self::assertNotContains('Option1 Linked To', $headers);
        self::assertNotContains('Unit price total measure', $headers);
    }

    public function test_external_urls_reject_credentials_and_local_network_targets(): void
    {
        self::assertSame('https://cdn.example.com/image.jpg', $this->invoke('externalUrl', 'https://cdn.example.com/image.jpg'));
        self::assertNull($this->invoke('externalUrl', 'https://user:secret@cdn.example.com/image.jpg'));
        self::assertNull($this->invoke('externalUrl', 'http://127.0.0.1/image.jpg'));
        self::assertNull($this->invoke('externalUrl', 'http://store.local/image.jpg'));
    }

    public function test_product_variant_and_description_images_are_mapped_to_local_paths(): void
    {
        $importer = new class extends RemoteImageImporter
        {
            public function __construct() {}

            public function importInto(array $sources, string $directory, string $type = 'image'): array
            {
                TestCase::assertSame('shopify-products', $directory);

                $paths = [
                    'https://cdn.example.test/product.jpg' => 'upload/shopify-products/product.jpg',
                    'https://cdn.example.test/variant.jpg' => 'upload/shopify-products/variant.jpg',
                    'https://cdn.example.test/body.jpg'    => 'upload/shopify-products/body.jpg',
                ];

                return array_values(array_map(
                    static fn (string $source): array => [
                        'origin' => $source,
                        'path'   => $paths[$source],
                        'url'    => '/' . $paths[$source],
                    ],
                    array_values(array_filter($sources, static fn (string $source): bool => isset($paths[$source]))),
                ));
            }
        };
        $service = new ShopifyCsvService($importer);
        $sources = $this->invokeOn($service, 'remoteImageSources', [
            ['image src' => 'https://cdn.example.test/product.jpg', 'variant image' => 'https://cdn.example.test/variant.jpg'],
        ], '<p>Copy</p><img data-src="//cdn.example.test/body.jpg"><img src="https://cdn.example.test/missing.jpg">');
        $mapped = $this->invokeOn($service, 'localizeRemoteImages', $sources);
        $html   = $this->invokeOn(
            $service,
            'localizeHtmlImages',
            $this->invokeOn($service, 'cleanHtml', '<img data-src="//cdn.example.test/body.jpg"><img src="https://cdn.example.test/missing.jpg">'),
            $mapped
        );

        self::assertCount(4, $sources);
        self::assertSame('upload/shopify-products/product.jpg', $mapped['https://cdn.example.test/product.jpg']);
        self::assertContains('https://cdn.example.test/body.jpg', $sources);
        self::assertStringContainsString('src="/upload/shopify-products/body.jpg"', $html);
        self::assertStringNotContainsString('missing.jpg', $html);
    }

    public function test_transient_image_downloads_are_retried_once_and_normalized(): void
    {
        $importer = new class extends RemoteImageImporter
        {
            public int $calls = 0;

            public function __construct() {}

            public function importInto(array $sources, string $directory, string $type = 'image'): array
            {
                $this->calls++;
                if ($this->calls === 1) {
                    return [[
                        'origin' => $sources[0],
                        'path'   => 'upload/shopify-products/first.jpg',
                        'url'    => '/upload/shopify-products/first.jpg',
                    ]];
                }

                return [[
                    'origin' => $sources[0],
                    'path'   => 'upload/shopify-products/retried.jpg',
                    'url'    => '/upload/shopify-products/retried.jpg',
                ]];
            }
        };
        $service   = new ShopifyCsvService($importer);
        $localized = $this->invokeOn($service, 'localizeRemoteImages', [
            '//cdn.example.test/first.jpg',
            'https://cdn.example.test/retry.jpg',
        ]);

        self::assertSame(2, $importer->calls);
        self::assertSame('upload/shopify-products/first.jpg', $localized['https://cdn.example.test/first.jpg']);
        self::assertSame('upload/shopify-products/retried.jpg', $localized['https://cdn.example.test/retry.jpg']);
    }

    public function test_localized_image_markup_cannot_retain_a_second_remote_source(): void
    {
        $service = new ShopifyCsvService;
        $html    = $this->invokeOn(
            $service,
            'cleanHtml',
            '<img src="https://cdn.example.test/product.jpg" data-src="https://tracker.example.test/pixel.jpg" alt="Product" onerror="alert(1)">'
        );
        $localized = $this->invokeOn($service, 'localizeHtmlImages', $html, [
            'https://cdn.example.test/product.jpg' => 'upload/shopify-products/product.jpg',
        ]);

        self::assertSame('<img src="/upload/shopify-products/product.jpg" alt="Product" loading="lazy">', $localized);
        self::assertStringNotContainsString('tracker.example.test', $localized);
        self::assertStringNotContainsString('onerror', $localized);
    }

    public function test_duplicate_variant_combinations_and_invalid_prices_fail_closed(): void
    {
        $variables = [[
            'source' => 1,
            'name'   => ['en' => 'Color'],
            'values' => [['name' => ['en' => 'Red'], 'image' => '']],
        ]];
        $duplicateRows = [
            ['option1 value' => 'Red', 'variant sku' => 'RED-1', 'variant price' => '10'],
            ['option1 value' => 'Red', 'variant sku' => 'RED-2', 'variant price' => '11'],
        ];

        try {
            $this->invoke('buildSkus', $duplicateRows, $variables, 'shirt', 'kg', 'en', []);
            self::fail('Duplicate combinations must not be silently discarded.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Duplicate option combination', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Variant Price must be numeric');
        $this->invoke('buildSkus', [['variant sku' => 'BAD', 'variant price' => 'free']], [], 'shirt', 'kg', 'en', []);
    }

    public function test_numeric_inventory_boolean_and_image_position_values_fail_closed(): void
    {
        foreach ([
            [['variant sku' => 'NEG', 'variant price' => '-1'], 'Variant Price cannot be negative'],
            [['variant sku' => 'FRACTION', 'variant price' => '10', 'variant inventory qty' => '1.5'], 'Variant Inventory Qty must be a whole number'],
            [['variant sku' => 'MALFORMED', 'variant price' => '1,2,3'], 'Variant Price must be numeric'],
        ] as [$row, $message]) {
            try {
                $this->invoke('buildSkus', [$row], [], 'shirt', 'kg', 'en', []);
                self::fail($message);
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }

        try {
            $this->invoke('boolValue', 'sometimes', true);
            self::fail('Unknown boolean text must not silently become false.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Boolean value', $exception->getMessage());
        }

        $ordered = $this->invoke('images', [
            ['image src' => 'https://cdn.test/third.jpg', 'image position' => '3'],
            ['image src' => 'https://cdn.test/first.jpg', 'image position' => '1'],
            ['image src' => 'https://cdn.test/second.jpg', 'image position' => '2'],
        ], [
            'https://cdn.test/first.jpg'  => 'upload/shopify-products/first.jpg',
            'https://cdn.test/second.jpg' => 'upload/shopify-products/second.jpg',
            'https://cdn.test/third.jpg'  => 'upload/shopify-products/third.jpg',
        ]);
        self::assertSame([
            'upload/shopify-products/first.jpg',
            'upload/shopify-products/second.jpg',
            'upload/shopify-products/third.jpg',
        ], $ordered);
    }

    public function test_large_csv_index_keeps_offsets_instead_of_full_rows(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/ShopifyProductTransfer/Services/ShopifyCsvService.php');

        self::assertStringContainsString("['offsets'][]", $source);
        self::assertStringNotContainsString("['rows'][]", $source);
        self::assertStringContainsString('rowsAtOffsets', $source);
    }

    public function test_selected_export_uses_a_bounded_csrf_protected_post_request(): void
    {
        $controller = new ShopifyProductController;
        $method     = new ReflectionMethod($controller, 'exportIds');
        $ids        = $method->invoke($controller, '3,2,3,-1,invalid,0,4');
        $root       = dirname(__DIR__, 2);
        $routes     = (string) file_get_contents($root . '/plugins/ShopifyProductTransfer/Routes/admin.php');
        $actions    = (string) file_get_contents($root . '/plugins/ShopifyProductTransfer/Views/admin/actions.blade.php');

        self::assertSame([3, 2, 4], $ids);
        self::assertStringContainsString("Route::post('shopify-products/export-selected'", $routes);
        self::assertStringContainsString("Route::middleware('can:products_index')", $routes);
        self::assertStringContainsString("->middleware('can:products_create')", $routes);
        self::assertStringContainsString("form.method = 'post'", $actions);
        self::assertStringContainsString("token.name = '_token'", $actions);
        self::assertStringContainsString('ids.slice(0, 2000)', $actions);
    }

    public function test_import_result_translation_receives_only_scalar_summary_values(): void
    {
        $controller = new ShopifyProductController;
        $method     = new ReflectionMethod($controller, 'summaryReplacements');
        $summary    = $method->invoke($controller, [
            'created'  => 2,
            'updated'  => '3',
            'skipped'  => -1,
            'failed'   => 1,
            'warnings' => 4,
            'errors'   => ['This array must never reach Translator replacements.'],
        ]);

        self::assertSame([
            'created'       => 2,
            'updated'       => 3,
            'skipped'       => 0,
            'failed'        => 1,
            'warnings'      => 4,
            'adjusted_skus' => 0,
        ], $summary);
        self::assertNotContains('errors', array_keys($summary));

        $root   = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/plugins/ShopifyProductTransfer/Routes/admin.php');
        $view   = (string) file_get_contents($root . '/plugins/ShopifyProductTransfer/Views/admin/index.blade.php');
        self::assertStringContainsString("Route::get('shopify-products/import'", $routes);
        self::assertStringContainsString("Route::post('shopify-products/import'", $routes);
        self::assertStringContainsString("Route::get('shopify-products/template'", $routes);
        self::assertStringContainsString("Route::post('shopify-products/imports/{importId}/process'", $routes);
        self::assertStringContainsString('data-maximum-bytes', $view);
        self::assertStringContainsString("window.addEventListener('pageshow'", $view);
        self::assertStringContainsString('button.disabled = formUnavailable', $view);
        self::assertStringContainsString('data-import-recovery-form', $view);
        self::assertStringContainsString('fetch(recoveryForm.action', $view);
        self::assertStringContainsString('shopify_product_transfer.template', $view);
        $controller = (string) file_get_contents($root . '/plugins/ShopifyProductTransfer/Controllers/ShopifyProductController.php');
        self::assertStringContainsString('TenantDeferred::terminating', $controller);
        self::assertStringContainsString('ignore_user_abort(true)', $controller);
    }

    public function test_plugin_translations_keep_matching_keys(): void
    {
        $root = dirname(__DIR__, 2) . '/plugins/ShopifyProductTransfer/Lang';
        $en   = require $root . '/en/transfer.php';
        $zh   = require $root . '/zh_cn/transfer.php';

        self::assertSame(array_keys($en), array_keys($zh));
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return $this->invokeOn(new ShopifyCsvService, $method, ...$arguments);
    }

    private function invokeOn(ShopifyCsvService $service, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($service, $method);

        return $reflection->invoke($service, ...$arguments);
    }
}
