<?php

namespace Tests\Feature;

use Beike\Services\CloudflareIpRangeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

class CloudflareIpRangeServiceTest extends TestCase
{
    private string $snapshotPath;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $this->snapshotPath = sys_get_temp_dir() . '/longos-cloudflare-ranges-' . bin2hex(random_bytes(8)) . '.json';
        config([
            'cache.default'                    => 'array',
            'longos.cloudflare.ip_ranges_path' => $this->snapshotPath,
        ]);
        File::delete($this->snapshotPath());
    }

    protected function tearDown(): void
    {
        File::delete($this->snapshotPath());
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_refresh_atomically_persists_valid_official_ranges(): void
    {
        $ipv4 = array_values(array_filter(
            CloudflareIpRangeService::bundledRanges(),
            static fn (string $range): bool => ! str_contains($range, ':'),
        ));
        $ipv6 = array_values(array_filter(
            CloudflareIpRangeService::bundledRanges(),
            static fn (string $range): bool => str_contains($range, ':'),
        ));
        Http::fake([
            'https://www.cloudflare.com/ips-v4' => Http::response(implode("\n", $ipv4)),
            'https://www.cloudflare.com/ips-v6' => Http::response(implode("\n", $ipv6)),
        ]);

        $service = new CloudflareIpRangeService;

        self::assertTrue($service->refresh());
        self::assertSame(CloudflareIpRangeService::bundledRanges(), $service->ranges());
        self::assertNotNull($service->cachedSnapshot());
    }

    public function test_failed_refresh_preserves_the_last_known_good_snapshot(): void
    {
        File::ensureDirectoryExists(dirname($this->snapshotPath()));
        File::put($this->snapshotPath(), json_encode([
            'fetched_at' => now()->toIso8601String(),
            'ranges'     => CloudflareIpRangeService::bundledRanges(),
        ], JSON_THROW_ON_ERROR));
        $before = File::get($this->snapshotPath());
        Http::fake([
            'https://www.cloudflare.com/ips-v4' => Http::response('', 503),
            'https://www.cloudflare.com/ips-v6' => Http::response('', 503),
        ]);

        self::assertFalse((new CloudflareIpRangeService)->refresh());
        self::assertSame($before, File::get($this->snapshotPath()));
    }

    private function snapshotPath(): string
    {
        return $this->snapshotPath;
    }
}
