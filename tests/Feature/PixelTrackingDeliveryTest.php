<?php

namespace Tests\Feature;

use Beike\Models\Order;
use Beike\Models\OrderProduct;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Plugin\PixelTracking\Jobs\SendTrackingEvent;
use Plugin\PixelTracking\Middleware\Shop\CaptureAttribution;
use Plugin\PixelTracking\Services\PixelProfiles;
use Plugin\PixelTracking\Services\ServerTrackingService;
use Plugin\PixelTracking\Services\TrackingHealth;

class PixelTrackingDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        Config::set('cache.default', 'array');
        Cache::flush();
        Config::set('bk.plugin.pixel_tracking.server_tracking', true);
        Config::set('bk.plugin.pixel_tracking.advanced_matching', false);
        Config::set('bk.plugin.pixel_tracking.meta_pixels', json_encode([[
            'pixel_id'     => '123456789',
            'access_token' => 'meta-secret',
            'api_version'  => 'v22.0',
        ]]));
        Config::set('bk.plugin.pixel_tracking.tiktok_pixels', json_encode([[
            'pixel_code'   => 'C123456789',
            'access_token' => 'tiktok-secret',
        ]]));
        Config::set('bk.plugin.pixel_tracking.snapchat_pixels', json_encode([[
            'pixel_id'     => '6abc82ca-4a3a-4391-98ba-0317a8471234',
            'access_token' => 'snapchat-secret',
        ]]));
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_meta_tiktok_and_snapchat_receive_current_deduplicated_payloads(): void
    {
        Http::fake([
            'graph.facebook.com/*'       => Http::response(['events_received' => 1], 200),
            'business-api.tiktok.com/*'  => Http::response(['code' => 0, 'message' => 'OK'], 200),
            'tr.snapchat.com/*'          => Http::response(['status' => 'VALID', 'reason' => 'Events have been processed successfully.'], 200),
        ]);

        $prepared = $this->preparedEvent();
        (new ServerTrackingService)->trackPreparedBrowserEvent($prepared);

        Http::assertSent(function (Request $request) use ($prepared): bool {
            return str_contains($request->url(), 'graph.facebook.com/v22.0/123456789/events')
                && $request['data'][0]['event_id']   === 'evt_shared_1'
                && $request['data'][0]['event_name'] === 'AddToCart'
                && $request['data'][0]['event_time'] === $prepared['event_time'];
        });
        Http::assertSent(function (Request $request) use ($prepared): bool {
            return str_contains($request->url(), 'business-api.tiktok.com/open_api/v1.3/event/track/')
                && $request->hasHeader('Access-Token', 'tiktok-secret')
                && $request['event_source']                                       === 'web'
                && $request['event_source_id']                                    === 'C123456789'
                && $request['data'][0]['event_id']                                === 'evt_shared_1'
                && $request['data'][0]['event_time']                              === $prepared['event_time']
                && $request['data'][0]['user']['ip']                              === '203.0.113.10'
                && $request['data'][0]['properties']['content_ids']               === ['SKU-1']
                && $request['data'][0]['properties']['contents'][0]['content_id'] === 'SKU-1';
        });
        Http::assertSent(function (Request $request) use ($prepared): bool {
            return str_contains($request->url(), 'tr.snapchat.com/v3/6abc82ca-4a3a-4391-98ba-0317a8471234/events')
                && str_contains($request->url(), 'access_token=snapchat-secret')
                && $request['data'][0]['event_name']                             === 'ADD_CART'
                && $request['data'][0]['event_id']                               === 'evt_shared_1'
                && $request['data'][0]['event_time']                             === $prepared['event_time']
                && $request['data'][0]['action_source']                          === 'WEB'
                && $request['data'][0]['user_data']['client_ip_address']         === '203.0.113.10'
                && $request['data'][0]['user_data']['sc_click_id']               === 'snap-click-123'
                && $request['data'][0]['user_data']['sc_cookie1']                === 'snap-cookie'
                && $request['data'][0]['custom_data']['content_ids']             === ['SKU-1']
                && $request['data'][0]['custom_data']['contents'][0]['id']       === 'SKU-1'
                && $request['data'][0]['custom_data']['contents'][0]['quantity'] === 2;
        });

        $health = TrackingHealth::forProfiles(PixelProfiles::meta(), PixelProfiles::tiktok(), PixelProfiles::snapchat());
        self::assertTrue($health['meta:123456789']['success']);
        self::assertTrue($health['tiktok:C123456789']['success']);
        self::assertTrue($health['snapchat:6abc82ca-4a3a-4391-98ba-0317a8471234']['success']);
        self::assertSame(1, $health['meta:123456789']['success_count']);
        self::assertSame(1, $health['tiktok:C123456789']['success_count']);
        self::assertSame(1, $health['snapchat:6abc82ca-4a3a-4391-98ba-0317a8471234']['success_count']);
    }

    public function test_successful_server_delivery_is_suppressed_for_the_same_event_id(): void
    {
        Http::fake([
            'graph.facebook.com/*'       => Http::response(['events_received' => 1], 200),
            'business-api.tiktok.com/*'  => Http::response(['code' => 0, 'message' => 'OK'], 200),
            'tr.snapchat.com/*'          => Http::response(['status' => 'VALID'], 200),
        ]);

        $service  = new ServerTrackingService;
        $prepared = $this->preparedEvent();
        $service->trackPreparedBrowserEvent($prepared);
        $service->trackPreparedBrowserEvent($prepared);

        Http::assertSentCount(3);
    }

    public function test_snapchat_purchase_uses_v3_order_deduplication_and_hashed_matching(): void
    {
        Config::set('bk.plugin.pixel_tracking.meta_pixels', '[]');
        Config::set('bk.plugin.pixel_tracking.tiktok_pixels', '[]');
        Config::set('bk.plugin.pixel_tracking.advanced_matching', true);
        Http::fake([
            'tr.snapchat.com/*' => Http::response(['status' => 'SUCCESS'], 200),
        ]);
        $prepared                           = $this->preparedEvent();
        $prepared['event_name']             = 'Purchase';
        $prepared['event_id']               = 'purchase_1001';
        $prepared['properties']['order_id'] = 'ORDER-1001';
        $prepared['context']['email']       = 'Buyer@Example.test';
        $prepared['context']['phone']       = '+1 (555) 0100';
        $prepared['context']['external_id'] = 'customer-42';

        (new ServerTrackingService)->trackPreparedBrowserEvent($prepared);

        Http::assertSent(function (Request $request): bool {
            return $request['data'][0]['event_name']                    === 'PURCHASE'
                && $request['data'][0]['event_id']                      === 'purchase_1001'
                && $request['data'][0]['custom_data']['order_id']       === 'ORDER-1001'
                && $request['data'][0]['custom_data']['value']          === 19.99
                && $request['data'][0]['custom_data']['currency']       === 'USD'
                && $request['data'][0]['user_data']['em']               === hash('sha256', 'buyer@example.test')
                && $request['data'][0]['user_data']['ph']               === hash('sha256', '15550100')
                && $request['data'][0]['user_data']['external_id']      === hash('sha256', 'customer-42');
        });
    }

    public function test_queued_event_encrypts_request_and_customer_context_at_rest(): void
    {
        $prepared                     = $this->preparedEvent();
        $prepared['context']['email'] = 'private-buyer@example.test';
        $prepared['context']['phone'] = '+1 555 0100';

        $serialized = serialize(new SendTrackingEvent($prepared));

        self::assertStringNotContainsString('private-buyer@example.test', $serialized);
        self::assertStringNotContainsString('+1 555 0100', $serialized);
        self::assertStringNotContainsString('203.0.113.10', $serialized);
        self::assertStringContainsString('encryptedPayload', $serialized);
    }

    public function test_health_history_counts_failures_and_resets_failure_streak_after_success(): void
    {
        TrackingHealth::record('meta', 'counter-profile', 'PageView', 'event-1', false, 500, 'temporary failure');
        TrackingHealth::record('meta', 'counter-profile', 'PageView', 'event-2', false, 503, 'temporary failure');
        TrackingHealth::record('meta', 'counter-profile', 'PageView', 'event-3', true, 200);

        $health = TrackingHealth::forProfiles([['pixel_id' => 'counter-profile']], []);
        self::assertSame(1, $health['meta:counter-profile']['success_count']);
        self::assertSame(2, $health['meta:counter-profile']['failure_count']);
        self::assertSame(0, $health['meta:counter-profile']['consecutive_failures']);
        self::assertNotNull($health['meta:counter-profile']['last_success_at']);
        self::assertNotNull($health['meta:counter-profile']['last_failure_at']);
    }

    public function test_consent_mode_does_not_store_campaign_or_browser_ids_before_opt_in(): void
    {
        Config::set('session.driver', 'array');
        Config::set('bk.plugin.pixel_tracking.require_consent', true);
        Config::set('bk.plugin.pixel_tracking.consent_cookie', 'tracking_consent');
        $session = app('session')->driver();
        $session->start();
        $session->put('pixel_tracking.attribution', ['utm_source' => 'stale']);
        $session->put('pixel_tracking.browser_identifiers', ['fbp' => 'stale']);

        $middleware = new CaptureAttribution;
        $request    = HttpRequest::create(
            'https://shop.example.test/products/1?utm_source=private-campaign&fbclid=private-click',
            'GET',
            cookies: ['_fbp' => 'fb.1.private'],
        );
        $middleware->handle($request, static fn () => response('ok'));

        self::assertFalse((bool) $session->get('pixel_tracking.consent'));
        self::assertNull($session->get('pixel_tracking.attribution'));
        self::assertNull($session->get('pixel_tracking.browser_identifiers'));

        $consentedRequest = HttpRequest::create(
            'https://shop.example.test/products/1?utm_source=accepted-campaign&ttclid=accepted-click&ScCid=snap-click',
            'GET',
            cookies: ['tracking_consent' => '1', '_ttp' => 'ttp-cookie', '_scid' => 'snap-cookie'],
        );
        $middleware->handle($consentedRequest, static fn () => response('ok'));

        self::assertTrue((bool) $session->get('pixel_tracking.consent'));
        self::assertSame('accepted-campaign', $session->get('pixel_tracking.attribution.utm_source'));
        self::assertSame('accepted-click', $session->get('pixel_tracking.attribution.ttclid'));
        self::assertSame('snap-click', $session->get('pixel_tracking.attribution.sc_click_id'));
        self::assertSame('ttp-cookie', $session->get('pixel_tracking.browser_identifiers.ttp'));
        self::assertSame('snap-cookie', $session->get('pixel_tracking.browser_identifiers.scid'));
    }

    public function test_server_preparation_requires_consent_and_recovers_attribution_after_dynamic_opt_in(): void
    {
        Config::set('session.driver', 'array');
        Config::set('bk.plugin.pixel_tracking.require_consent', true);
        $session = app('session')->driver();
        $session->start();
        $service = new ServerTrackingService;
        $event   = [
            'event_name' => 'PageView',
            'event_id'   => 'dynamic_consent_pageview',
            'url'        => 'https://shop.example.test/products/1?utm_source=accepted&utm_campaign=launch&ttclid=click-1&ScCid=snap-click-1',
            'properties' => [],
        ];

        $withoutConsent = HttpRequest::create($event['url'], 'POST');
        self::assertNull($service->prepareBrowserEvent($event, $withoutConsent));
        self::assertNull($session->get('pixel_tracking.attribution'));

        $withConsent = HttpRequest::create($event['url'], 'POST', cookies: ['tracking_consent' => '1']);
        $prepared    = $service->prepareBrowserEvent($event, $withConsent);
        self::assertNotNull($prepared);
        self::assertSame('accepted', $prepared['context']['attribution']['utm_source']);
        self::assertSame('launch', $prepared['context']['attribution']['utm_campaign']);
        self::assertSame('click-1', $prepared['context']['attribution']['ttclid']);
        self::assertSame('snap-click-1', $prepared['context']['attribution']['sc_click_id']);
    }

    public function test_upgrade_migration_encrypts_existing_tokens_once_per_tenant(): void
    {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge();
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('space');
            $table->string('name');
            $table->text('value')->nullable();
            $table->boolean('json')->default(false);
            $table->timestamps();
        });
        DB::table('settings')->insert([
            'type'       => 'plugin',
            'space'      => 'pixel_tracking',
            'name'       => 'meta_pixels',
            'value'      => json_encode([['pixel_id' => '123456789', 'access_token' => 'legacy-secret', 'api_version' => 'v22.0']]),
            'json'       => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require dirname(__DIR__, 2) . '/plugins/PixelTracking/Migrations/2026_10_12_000000_encrypt_pixel_tracking_tokens.php';
        $migration->up();
        $encrypted = (string) DB::table('settings')->value('value');
        self::assertStringContainsString('enc:v1:', $encrypted);
        self::assertStringNotContainsString('legacy-secret', $encrypted);

        $migration->up();
        self::assertSame($encrypted, DB::table('settings')->value('value'));
        Config::set('bk.plugin.pixel_tracking.meta_pixels', $encrypted);
        self::assertSame('legacy-secret', PixelProfiles::meta()[0]['access_token']);
    }

    public function test_http_200_with_tiktok_provider_error_is_recorded_as_failure(): void
    {
        Config::set('bk.plugin.pixel_tracking.meta_pixels', '[]');
        Http::fake([
            'business-api.tiktok.com/*' => Http::response(['code' => 40002, 'message' => 'Invalid event payload'], 200),
        ]);

        (new ServerTrackingService)->trackPreparedBrowserEvent($this->preparedEvent());

        $health = TrackingHealth::forProfiles([], PixelProfiles::tiktok());
        self::assertFalse($health['tiktok:C123456789']['success']);
        self::assertSame(200, $health['tiktok:C123456789']['http_status']);
        self::assertSame('Invalid event payload', $health['tiktok:C123456789']['message']);
    }

    public function test_http_200_with_snapchat_provider_error_is_recorded_as_failure(): void
    {
        Config::set('bk.plugin.pixel_tracking.meta_pixels', '[]');
        Config::set('bk.plugin.pixel_tracking.tiktok_pixels', '[]');
        Http::fake([
            'tr.snapchat.com/*' => Http::response(['status' => 'INVALID', 'reason' => 'Invalid event payload'], 200),
        ]);

        (new ServerTrackingService)->trackPreparedBrowserEvent($this->preparedEvent());

        $health = TrackingHealth::forProfiles([], [], PixelProfiles::snapchat());
        self::assertFalse($health['snapchat:6abc82ca-4a3a-4391-98ba-0317a8471234']['success']);
        self::assertSame(200, $health['snapchat:6abc82ca-4a3a-4391-98ba-0317a8471234']['http_status']);
        self::assertSame('Invalid event payload', $health['snapchat:6abc82ca-4a3a-4391-98ba-0317a8471234']['message']);
    }

    public function test_provider_http_error_keeps_real_status_for_admin_diagnostics(): void
    {
        Config::set('bk.plugin.pixel_tracking.tiktok_pixels', '[]');
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid access token']], 401),
        ]);

        (new ServerTrackingService)->trackPreparedBrowserEvent($this->preparedEvent());

        $health = TrackingHealth::forProfiles(PixelProfiles::meta(), []);
        self::assertFalse($health['meta:123456789']['success']);
        self::assertSame(401, $health['meta:123456789']['http_status']);
        self::assertSame('Invalid access token', $health['meta:123456789']['message']);
    }

    public function test_delayed_payment_uses_persisted_consent_attribution_and_order_currency(): void
    {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('session.driver', 'array');
        Config::set('bk.plugin.pixel_tracking.require_consent', true);
        DB::purge();
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
        });
        $migration = require dirname(__DIR__, 2) . '/plugins/PixelTracking/Migrations/2026_10_08_000000_create_pixel_tracking_order_contexts.php';
        $migration->up();
        DB::table('orders')->insert(['id' => 1001]);

        $session = app('session')->driver();
        $session->start();
        $session->put('pixel_tracking.attribution', ['fbclid' => 'fb-click', 'ttclid' => 'tt-click', 'sc_click_id' => 'snap-click', 'fbclid_time' => 1791430000]);
        $session->put('pixel_tracking.browser_identifiers', ['fbp' => 'fb.1.123.456']);

        $request = HttpRequest::create(
            'https://shop.example.test/checkout',
            'POST',
            cookies: ['tracking_consent' => '1', '_ttp' => 'ttp-cookie', '_scid' => 'snap-cookie'],
        );
        $order = new Order([
            'number'         => 'ORDER-1001',
            'currency_code'  => 'CNY',
            'currency_value' => 7.2,
            'total'          => 10,
            'email'          => 'buyer@example.test',
            'ip'             => '203.0.113.20',
            'user_agent'     => 'LONGOS delayed payment test',
        ]);
        $order->id = 1001;
        $order->setRelation('orderProducts', collect([new OrderProduct([
            'product_id' => 88,
            'quantity'   => 2,
            'price'      => 5,
        ])]));

        $service = new ServerTrackingService;
        $service->rememberOrderContext($order, $request);
        $storedContext = DB::table('pixel_tracking_order_contexts')->where('order_id', 1001)->first();
        self::assertStringStartsWith('enc:v1:', $storedContext->attribution);
        self::assertStringStartsWith('enc:v1:', $storedContext->browser_identifiers);
        $session->flush();

        $prepared = $service->prepareOrderPurchase($order);

        self::assertNotNull($prepared);
        self::assertSame('tt-click', $prepared['context']['attribution']['ttclid']);
        self::assertSame('snap-click', $prepared['context']['attribution']['sc_click_id']);
        self::assertSame('ttp-cookie', $prepared['context']['ttp']);
        self::assertSame('snap-cookie', $prepared['context']['scid']);
        self::assertSame('CNY', $prepared['properties']['currency']);
        self::assertSame(36.0, $prepared['properties']['contents'][0]['item_price']);
        self::assertSame(72.0, $prepared['properties']['value']);

        $migration->down();
        self::assertFalse(Schema::hasTable('pixel_tracking_order_contexts'));
    }

    private function preparedEvent(): array
    {
        return [
            'event_name' => 'AddToCart',
            'event_id'   => 'evt_shared_1',
            'event_time' => time() - 60,
            'properties' => [
                'value'        => 19.99,
                'currency'     => 'USD',
                'content_ids'  => ['SKU-1'],
                'contents'     => [['id' => 'SKU-1', 'quantity' => 2, 'item_price' => 9.995]],
                'content_type' => 'product',
            ],
            'context' => [
                'ip'          => '203.0.113.10',
                'user_agent'  => 'LONGOS test browser',
                'url'         => 'https://shop.example.test/products/1',
                'referrer'    => 'https://shop.example.test/',
                'attribution' => ['ttclid' => 'click-123', 'sc_click_id' => 'snap-click-123'],
                'fbp'         => 'fb.1.123.456',
                'fbc'         => 'fb.1.123.click',
                'scid'        => 'snap-cookie',
                'customer'    => [],
            ],
        ];
    }
}
