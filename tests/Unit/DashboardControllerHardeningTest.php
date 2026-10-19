<?php

namespace Tests\Unit;

use Beike\Admin\Http\Controllers\DashboardController;
use Beike\Admin\Services\DashboardService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class DashboardControllerHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'app.debug'     => false,
            'cache.default' => 'array',
        ]);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_dashboard_runtime_errors_never_reach_the_json_response(): void
    {
        $service = new class extends DashboardService
        {
            public function getBasicStats(string $timeRange = 'today'): array
            {
                throw new RuntimeException('password=must-not-leak /var/www/private/database.sqlite');
            }
        };
        $response = (new DashboardController($service))->getStats(
            Request::create('/admin/dashboard/stats', 'GET', ['time_range' => 'today'])
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('must-not-leak', (string) $response->getContent());
        self::assertStringNotContainsString('/var/www', (string) $response->getContent());
        self::assertStringNotContainsString(':message', (string) $response->getContent());
    }

    public function test_dashboard_exports_are_limited_per_tenant_and_admin(): void
    {
        $controller = new DashboardController(new DashboardService);
        $user       = new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        };
        $key = 'dashboard:export:single:42';
        RateLimiter::clear($key);

        $method = new ReflectionMethod($controller, 'checkExportRateLimit');
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            self::assertTrue($method->invoke($controller, $user));
        }

        self::assertFalse($method->invoke($controller, $user));
        self::assertGreaterThan(0, RateLimiter::availableIn($key));
    }
}
