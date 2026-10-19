<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackStorefrontVisit;
use Beike\Services\CloudflareIpRangeService;
use Beike\Services\StorefrontVisitService;
use Beike\Services\TrafficSourceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class StorefrontAnalyticsTrackingTest extends TestCase
{
    private string $databasePath;

    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->previousConnection = (string) Config::get('database.default');
        $this->databasePath       = tempnam(sys_get_temp_dir(), 'longos-analytics-');
        Config::set('database.connections.storefront_analytics_test', [
            'driver'                  => 'sqlite',
            'database'                => $this->databasePath,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', 'storefront_analytics_test');
        DB::purge('storefront_analytics_test');
        DB::setDefaultConnection('storefront_analytics_test');

        Schema::create('storefront_visits', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('bucket_at');
            $table->char('visitor_key', 64);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('source_key', 32)->default('direct');
            $table->string('source_detail')->nullable();
            $table->string('referer', 1000)->nullable();
            $table->string('landing_path', 1000)->nullable();
            $table->unsignedInteger('page_views')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('human_confirmed_at')->nullable();
            $table->string('confirmation_method', 16)->nullable();
            $table->timestamps();
            $table->unique(['bucket_at', 'visitor_key']);
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('storefront_analytics_test');
        DB::setDefaultConnection($this->previousConnection);
        Config::set('database.default', $this->previousConnection);
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_source_resolution_prioritizes_campaign_ids_and_removes_sensitive_query_data(): void
    {
        $request = Request::create(
            'https://shop.example.test/products/1?utm_source=newsletter&utm_medium=email&gclid=click-1',
            'GET',
            [],
            [],
            [],
            ['HTTP_REFERER' => 'https://search.example.test/path?email=buyer%40example.test#secret'],
        );

        $source = (new TrafficSourceService)->resolve($request);

        self::assertSame('google', $source['key']);
        self::assertSame('newsletter', $source['detail']);
        self::assertSame('https://search.example.test/path', $source['referer']);
    }

    public function test_source_resolution_rejects_empty_click_ids_and_ambiguous_alias_substrings(): void
    {
        $emptyClickId = Request::create(
            'https://shop.example.test/?gclid=&utm_source=newsletter&utm_medium=email',
        );
        $ambiguousAlias = Request::create(
            'https://shop.example.test/?utm_source=original&utm_medium=paid_social',
        );
        $explicitAlias = Request::create(
            'https://shop.example.test/?utm_source=ig&utm_medium=paid_social',
        );

        $resolver = new TrafficSourceService;

        self::assertSame('email', $resolver->resolve($emptyClickId)['key']);
        self::assertSame('other', $resolver->resolve($ambiguousAlias)['key']);
        self::assertSame('instagram', $resolver->resolve($explicitAlias)['key']);
    }

    public function test_html_requests_are_atomically_counted_and_keep_first_touch_attribution(): void
    {
        $middleware = new TrackStorefrontVisit;
        $request    = $this->request('https://shop.example.test/?utm_source=facebook', 'session-one');
        $request->attributes->set(CloudflareIpRangeService::REQUEST_ATTRIBUTE, true);
        $request->headers->set('CF-IPCountry', 'US');
        app()->instance('request', $request);

        $middleware->handle($request, static fn () => response('<html>Store</html>', 200, ['Content-Type' => 'text/html']));
        self::assertSame(0, DB::table('storefront_visits')->count());
        self::assertTrue($this->confirm($request, 'US', 'pointer'));

        $second = $this->request('https://shop.example.test/products/1?utm_source=tiktok', 'session-one');
        $second->setLaravelSession($request->session());
        $second->attributes->set(CloudflareIpRangeService::REQUEST_ATTRIBUTE, true);
        $second->headers->set('CF-IPCountry', 'CA');
        app()->instance('request', $second);
        $middleware->handle($second, static fn () => response('<html>Product</html>', 200, ['Content-Type' => 'text/html']));
        self::assertTrue($this->confirm($second, 'CA', 'scroll'));

        $visit = DB::table('storefront_visits')->sole();
        self::assertSame(2, (int) $visit->page_views);
        self::assertSame('facebook', $visit->source_key);
        self::assertSame('CA', $visit->country_code);
        self::assertSame('/', $visit->landing_path);
        self::assertSame('scroll', $visit->confirmation_method);
        self::assertNotNull($visit->human_confirmed_at);
    }

    public function test_bots_and_non_html_responses_are_not_counted(): void
    {
        $middleware = new TrackStorefrontVisit;
        $bot        = $this->request('https://shop.example.test/', 'bot-session', 'Googlebot/2.1');
        app()->instance('request', $bot);
        $middleware->handle($bot, static fn () => response('<html>Store</html>', 200, ['Content-Type' => 'text/html']));

        $json = $this->request('https://shop.example.test/products/autocomplete', 'json-session');
        app()->instance('request', $json);
        $middleware->handle($json, static fn () => response()->json(['ok' => true]));

        $scanner = $this->request('https://shop.example.test/', 'scanner-session', 'Mozilla/5.0 zgrab/0.x');
        app()->instance('request', $scanner);
        $middleware->handle($scanner, static fn () => response('<html>Store</html>', 200, ['Content-Type' => 'text/html']));

        self::assertSame(0, DB::table('storefront_visits')->count());
        self::assertFalse($bot->session()->has(StorefrontVisitService::PENDING_SESSION_KEY));
        self::assertFalse($json->session()->has(StorefrontVisitService::PENDING_SESSION_KEY));
        self::assertFalse($scanner->session()->has(StorefrontVisitService::PENDING_SESSION_KEY));
    }

    public function test_unconfirmed_page_load_and_replayed_confirmation_are_not_counted(): void
    {
        $middleware = new TrackStorefrontVisit;
        $request    = $this->request('https://shop.example.test/', 'quiet-session');
        app()->instance('request', $request);

        $middleware->handle($request, static fn () => response('<html>Store</html>', 200, ['Content-Type' => 'text/html']));

        self::assertSame(0, DB::table('storefront_visits')->count());
        self::assertTrue($this->confirm($request, 'DE', 'engaged'));
        self::assertFalse($this->confirm($request, 'DE', 'engaged'));
        self::assertSame(1, DB::table('storefront_visits')->count());
    }

    private function confirm(Request $pageRequest, string $country, string $activity): bool
    {
        $request = Request::create(
            'https://shop.example.test/_longos/analytics/visit',
            'POST',
            ['activity' => $activity],
            [],
            [],
            ['HTTP_USER_AGENT' => (string) $pageRequest->userAgent()],
        );
        $request->setLaravelSession($pageRequest->session());
        $request->attributes->set(CloudflareIpRangeService::REQUEST_ATTRIBUTE, true);
        $request->headers->set('CF-IPCountry', $country);
        app()->instance('request', $request);

        return app(StorefrontVisitService::class)->confirm($request, $activity);
    }

    private function request(string $uri, string $sessionId, string $userAgent = 'Mozilla/5.0'): Request
    {
        $request = Request::create($uri, 'GET', [], [], [], ['HTTP_USER_AGENT' => $userAgent]);
        $session = new Store($sessionId, new ArraySessionHandler(480));
        $session->setId($sessionId);
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }
}
