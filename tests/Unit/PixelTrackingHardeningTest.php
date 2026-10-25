<?php

namespace Tests\Unit;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\TestCase;
use Plugin\PixelTracking\Services\PixelProfiles;

class PixelTrackingHardeningTest extends TestCase
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

    public function test_profile_validation_rejects_malformed_and_duplicate_pixels(): void
    {
        self::assertNull(PixelProfiles::validationError('[{"pixel_id":"123456789","api_version":"v22.0"}]', 'meta'));
        self::assertNotNull(PixelProfiles::validationError('[{"pixel_id":"not-a-number"}]', 'meta'));
        self::assertNotNull(PixelProfiles::validationError('[{"pixel_id":"123456"},{"pixel_id":"123456"}]', 'meta'));
        self::assertNotNull(PixelProfiles::validationError('[{"pixel_code":"C123456","access_token":"x"},{"pixel_code":"C123456"}]', 'tiktok'));
        self::assertNotNull(PixelProfiles::validationError('[{"pixel_code":"bad code"}]', 'tiktok'));
        self::assertNull(PixelProfiles::validationError('[{"pixel_id":"6abc82ca-4a3a-4391-98ba-0317a8471234"}]', 'snapchat'));
        self::assertNotNull(PixelProfiles::validationError('[{"pixel_id":"not-a-snap-pixel"}]', 'snapchat'));
        self::assertNotNull(PixelProfiles::validationError('[{"pixel_id":"6abc82ca-4a3a-4391-98ba-0317a8471234"},{"pixel_id":"6ABC82CA-4A3A-4391-98BA-0317A8471234"}]', 'snapchat'));
        self::assertNotNull(PixelProfiles::validationError('{"pixel_id":"123456789"}', 'meta'));
        self::assertNotNull(PixelProfiles::validationError('[{"pixel_id":"123456789","access_token":"enc:v1:broken"}]', 'meta'));
        $tooManyProfiles = array_map(
            static fn (int $number): array => ['pixel_id' => (string) (123456780 + $number)],
            range(1, 11),
        );
        self::assertSame(
            '每个渠道最多配置 10 个像素。',
            PixelProfiles::validationError($tooManyProfiles, 'meta'),
        );
    }

    public function test_profile_storage_drops_ui_and_unknown_fields(): void
    {
        $json = PixelProfiles::normalizeForStorage([[
            'pixel_id'        => ' 123456789 ',
            'access_token'    => ' token ',
            'api_version'     => 'v22.0',
            'test_event_code' => ' TEST1 ',
            'has_saved_token' => true,
            'arbitrary'       => ['must' => 'not persist'],
        ]], 'meta');

        $stored = json_decode($json, true);
        self::assertSame('123456789', $stored[0]['pixel_id']);
        self::assertStringStartsWith('enc:v1:', $stored[0]['access_token']);
        self::assertNotSame('token', $stored[0]['access_token']);
        self::assertSame([[
            'pixel_id'        => '123456789',
            'access_token'    => $stored[0]['access_token'],
            'api_version'     => 'v22.0',
            'test_event_code' => 'TEST1',
        ]], $stored);

        Config::set('bk.plugin.pixel_tracking.meta_pixels', $json);
        self::assertSame('token', PixelProfiles::meta()[0]['access_token']);
        self::assertNull(PixelProfiles::validationError($json, 'meta'));

        $snapchatJson = PixelProfiles::normalizeForStorage([[
            'pixel_id'     => '6abc82ca-4a3a-4391-98ba-0317a8471234',
            'access_token' => 'snap-token',
            'unknown'      => 'drop-me',
        ]], 'snapchat');
        Config::set('bk.plugin.pixel_tracking.snapchat_pixels', $snapchatJson);
        self::assertSame('snap-token', PixelProfiles::snapchat()[0]['access_token']);
        self::assertArrayNotHasKey('unknown', json_decode($snapchatJson, true)[0]);

        $configView = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/PixelTracking/Views/admin/config.blade.php');
        self::assertStringContainsString("\$profile['_saved_identifier']", $configView);
        self::assertStringNotContainsString('value="EA', $configView);
    }

    public function test_browser_pixels_are_addressed_individually_and_share_deduplication_ids(): void
    {
        $root     = dirname(__DIR__, 2);
        $tracker  = (string) file_get_contents($root . '/plugins/PixelTracking/Views/shop/tracker.blade.php');
        $purchase = (string) file_get_contents($root . '/plugins/PixelTracking/Views/shop/purchase_event.blade.php');

        self::assertStringContainsString("window.fbq('trackSingle', pixelId", $tracker);
        self::assertStringNotContainsString("window.fbq('track', name", $tracker);
        self::assertStringContainsString('window.ttq.instance(pixelId)', $tracker);
        self::assertStringContainsString("window.snaptr('track', pixelId, snapchatEvent", $tracker);
        self::assertStringContainsString("window.snaptr('init', pixelId)", $tracker);
        self::assertStringContainsString('https://sc-static.net/scevent.min.js', $tracker);
        self::assertStringContainsString('client_dedup_id: eventId', $tracker);
        self::assertStringContainsString('result.transaction_id = String(properties.order_id)', $tracker);
        self::assertStringContainsString('{event_id: eventId}', $tracker);
        self::assertStringContainsString('const sentEvents = new Set()', $tracker);
        self::assertStringContainsString('result.content_ids = result.content_ids.map(String)', $tracker);
        self::assertStringNotContainsString('delete result.content_ids', $tracker);
        self::assertStringNotContainsString('ajaxComplete', $tracker);
        self::assertStringContainsString("window.addEventListener('longos:cart-added'", $tracker);
        self::assertStringContainsString("window.addEventListener('longos:tracking-consent-granted', start)", $tracker);
        self::assertStringContainsString('if (!hasConsent()) return eventId', $tracker);
        self::assertStringContainsString("window.fbq('consent', 'revoke')", $tracker);
        self::assertStringContainsString('window.ttq.disableCookie()', $tracker);
        self::assertStringContainsString('window.LongosPixelTracking = Object.freeze', $tracker);
        self::assertStringContainsString('&& $hasServerProfiles', $tracker);
        self::assertStringContainsString('tracking.content_id', $tracker);
        self::assertStringContainsString('itemPrice * quantity', $tracker);
        self::assertStringContainsString("config.serverTracking && name !== 'Purchase'", $tracker);
        self::assertStringContainsString("emit('InitiateCheckout')", $tracker);
        self::assertStringContainsString("emit('Search'", $tracker);
        self::assertStringContainsString("'purchase_' . \$order->id", $purchase);
        self::assertStringContainsString('contents: @json($trackingContents)', $purchase);
    }

    public function test_server_tracking_uses_current_tiktok_envelope_and_provider_level_success_checks(): void
    {
        $root       = dirname(__DIR__, 2);
        $service    = (string) file_get_contents($root . '/plugins/PixelTracking/Services/ServerTrackingService.php');
        $controller = (string) file_get_contents($root . '/plugins/PixelTracking/Controllers/TrackingController.php');

        self::assertStringContainsString("'event_source'    => 'web'", $service);
        self::assertStringContainsString("'event_source_id' => \$profile['pixel_code']", $service);
        self::assertStringContainsString("'data'            => [[", $service);
        self::assertStringContainsString('(int) $providerCode === 0', $service);
        self::assertStringContainsString("'PageView'             => 'PAGE_VIEW'", $service);
        self::assertStringContainsString("'Purchase'             => 'PURCHASE'", $service);
        self::assertStringContainsString("'action_source'    => 'WEB'", $service);
        self::assertStringContainsString('https://tr.snapchat.com/v3/', $service);
        self::assertStringContainsString("'sc_click_id'", $service);
        self::assertStringContainsString("'sc_cookie1'", $service);
        self::assertStringContainsString("'content_ids'", $service);
        self::assertStringContainsString("'search_string'", $service);
        self::assertStringContainsString('beginDelivery(', $service);
        self::assertStringContainsString('DELIVERY_DEDUPLICATION_HOURS', $service);
        self::assertStringContainsString("\$response->json('error.message')", $service);
        self::assertStringContainsString('TrackingHealth::record(', $service);
        self::assertStringContainsString('PixelEventDispatcher::dispatch(', $controller);
        self::assertStringContainsString('], 202)', $controller);
        self::assertStringNotContainsString('Search,CompleteRegistration', $controller);

        $profiles = (string) file_get_contents($root . '/plugins/PixelTracking/Services/PixelProfiles.php');
        self::assertStringContainsString("ENCRYPTED_TOKEN_PREFIX = 'enc:v1:'", $profiles);
        self::assertStringContainsString('Crypt::encryptString', $profiles);
        self::assertStringContainsString('Crypt::decryptString', $profiles);

        $routes = (string) file_get_contents($root . '/plugins/PixelTracking/Routes/shop.php');
        self::assertStringContainsString("middleware('throttle:60,1')", $routes);

        $bootstrap = (string) file_get_contents($root . '/plugins/PixelTracking/Bootstrap.php');
        self::assertStringContainsString("add_hook_action('service.account.register.after'", $bootstrap);
        self::assertStringContainsString("add_hook_filter('repository.order.create.after'", $bootstrap);
        self::assertStringContainsString('rememberOrderContext($order, request())', $bootstrap);
        self::assertStringContainsString('PixelEventDispatcher::dispatch(', $bootstrap);
        self::assertStringContainsString("app('router')->has('shop.pixel_tracking.events.store')", $bootstrap);

        $job = (string) file_get_contents($root . '/plugins/PixelTracking/Jobs/SendTrackingEvent.php');
        self::assertStringContainsString('implements ShouldQueue', $job);
        self::assertStringContainsString('Crypt::encryptString', $job);
        self::assertStringContainsString('Crypt::decryptString', $job);
        self::assertStringContainsString('trackPreparedBrowserEvent($prepared, true)', $job);
        self::assertStringNotContainsString("DesignService::isAppEmbedEnabled('pixel_tracking')\n            &&", $service);

        $migration = (string) file_get_contents($root . '/plugins/PixelTracking/Migrations/2026_10_08_000000_create_pixel_tracking_order_contexts.php');
        self::assertStringContainsString("Schema::create('pixel_tracking_order_contexts'", $migration);

        $tokenMigration = (string) file_get_contents($root . '/plugins/PixelTracking/Migrations/2026_10_12_000000_encrypt_pixel_tracking_tokens.php');
        self::assertStringContainsString("'meta_pixels' => 'meta'", $tokenMigration);
        self::assertStringContainsString("'snapchat_pixels' => 'snapchat'", $tokenMigration);
        self::assertStringContainsString('PixelProfiles::normalizeForStorage', $tokenMigration);
    }
}
