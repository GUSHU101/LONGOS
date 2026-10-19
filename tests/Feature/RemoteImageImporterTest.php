<?php

namespace Tests\Feature;

use Beike\Admin\Services\RemoteImageImporter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\TestCase;

class RemoteImageImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_remote_image_is_verified_and_stored_on_the_tenant_disk(): void
    {
        Storage::fake('upload');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($png);

        $service = $this->serviceWithResponses([
            new Response(200, ['Content-Type' => 'image/png'], $png),
        ]);

        $result = $service->import(['https://images.example.test/catalog/item.jpg']);

        self::assertCount(1, $result);
        self::assertSame('https://images.example.test/catalog/item.jpg', $result[0]['origin']);
        self::assertMatchesRegularExpression('#^upload/image/[A-Za-z0-9]{40}\.png$#', $result[0]['path']);
        Storage::disk('upload')->assertExists(substr($result[0]['path'], strlen('upload/')));
    }

    public function test_owned_import_directory_is_isolated_for_product_migrations(): void
    {
        Storage::fake('upload');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($png);

        $service = $this->serviceWithResponses([new Response(200, ['Content-Type' => 'image/png'], $png)]);
        $result  = $service->importInto(['https://cdn.example.test/product.png'], 'shopify-products');

        self::assertCount(1, $result);
        self::assertMatchesRegularExpression('#^upload/shopify-products/[A-Za-z0-9]{40}\.png$#', $result[0]['path']);
        Storage::disk('upload')->assertExists(substr($result[0]['path'], strlen('upload/')));
    }

    public function test_private_network_and_non_image_responses_are_not_imported(): void
    {
        Storage::fake('upload');
        $privateHandler = new MockHandler([new Response(200, [], 'unused')]);
        $privateClient  = new Client(['handler' => HandlerStack::create($privateHandler)]);
        $privateService = new RemoteImageImporter($privateClient);

        self::assertSame([], $privateService->import(['http://127.0.0.1/internal.png']));
        self::assertCount(1, $privateHandler);

        $htmlService = $this->serviceWithResponses([
            new Response(200, ['Content-Type' => 'image/png'], '<html>not an image</html>'),
        ]);

        self::assertSame([], $htmlService->import(['https://images.example.test/fake.png']));
        self::assertSame([], Storage::disk('upload')->allFiles());
    }

    public function test_oversized_remote_responses_are_rejected_before_storage(): void
    {
        Storage::fake('upload');
        config(['longos.remote_image_max_bytes' => 1024]);
        $service = $this->serviceWithResponses([
            new Response(200, ['Content-Length' => '2048'], str_repeat('x', 2048)),
        ]);

        self::assertSame([], $service->import(['https://images.example.test/large.png']));
        self::assertSame([], Storage::disk('upload')->allFiles());
    }

    public function test_order_email_access_link_is_signed_expiring_and_tamper_evident(): void
    {
        $url      = order_access_url((object) ['number' => 202610080001]);
        $path     = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
        $request  = Request::create('https://old-shop.example' . $path);
        $newHost  = Request::create('https://new-shop.example' . $path);
        $tampered = Request::create('https://new-shop.example' . str_replace('202610080001', '202610080002', $path));

        self::assertTrue(URL::hasValidSignature($request, absolute: false));
        self::assertTrue(URL::hasValidSignature($newHost, absolute: false));
        self::assertStringContainsString('expires=', $url);
        self::assertFalse(URL::hasValidSignature($tampered, absolute: false));
    }

    /**
     * @param array<int, Response> $responses
     */
    private function serviceWithResponses(array $responses): RemoteImageImporter
    {
        $handler = new MockHandler($responses);
        $client  = new Client(['handler' => HandlerStack::create($handler)]);

        return new class($client) extends RemoteImageImporter
        {
            protected function resolvePublicAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
    }
}
