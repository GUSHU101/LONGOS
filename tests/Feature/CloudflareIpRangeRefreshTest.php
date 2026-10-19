<?php

namespace Tests\Feature;

use Beike\Console\Commands\RefreshCloudflareIpRanges;
use Beike\Services\CloudflareIpRangeService;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CloudflareIpRangeRefreshTest extends TestCase
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

    public function test_required_mode_fails_when_cloudflare_publishes_an_unbundled_range(): void
    {
        config(['longos.cloudflare.mode' => 'required']);
        app()->instance(CloudflareIpRangeService::class, new class extends CloudflareIpRangeService
        {
            public function refresh(): bool
            {
                return true;
            }

            public function ranges(): array
            {
                return [...self::bundledRanges(), '203.0.113.0/24'];
            }
        });

        $command = app(RefreshCloudflareIpRanges::class);
        $command->setLaravel(app());
        $tester  = new CommandTester($command);

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('203.0.113.0/24', $tester->getDisplay());
        self::assertStringContainsString('Rebuild LONGOS', $tester->getDisplay());
    }
}
