<?php

namespace Tests\Unit;

use App\Support\DeploymentCacheInvalidator;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class DeploymentCacheInvalidatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-cache-test-' . bin2hex(random_bytes(8));
        foreach (['bootstrap/cache', 'storage/framework/views', 'beike/Shop/Routes', 'beike/Services', 'beike/Shop/Services', 'config'] as $directory) {
            mkdir($this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory), 0777, true);
        }
        file_put_contents($this->root . '/beike/Shop/Routes/shop.php', '<?php // v1');
        file_put_contents($this->root . '/beike/Services/CurrencyService.php', '<?php // v1');
        file_put_contents($this->root . '/beike/Shop/Services/AddressAutocompleteService.php', '<?php // v1');
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_route_cache_is_invalidated_only_when_release_inputs_change(): void
    {
        $routeCache = $this->root . '/bootstrap/cache/routes-v7.php';
        $viewCache  = $this->root . '/storage/framework/views/page.php';
        file_put_contents($routeCache, 'stale');
        file_put_contents($viewCache, 'stale');

        DeploymentCacheInvalidator::refresh($this->root, true);
        self::assertFileDoesNotExist($routeCache);
        self::assertFileDoesNotExist($viewCache);

        file_put_contents($routeCache, 'current');
        file_put_contents($viewCache, 'current');
        DeploymentCacheInvalidator::refresh($this->root, true);
        self::assertFileExists($routeCache);
        self::assertFileExists($viewCache);

        file_put_contents($this->root . '/beike/Shop/Routes/shop.php', '<?php // a changed storefront route');
        DeploymentCacheInvalidator::refresh($this->root, true);
        self::assertFileDoesNotExist($routeCache);
        self::assertFileDoesNotExist($viewCache);
    }

    public function test_fingerprint_contract_includes_all_route_and_config_sources(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Support/DeploymentCacheInvalidator.php');

        self::assertStringContainsString("['routes', 'config', 'database/migrations']", $source);
        self::assertStringContainsString("['beike', 'plugins']", $source);
        self::assertStringContainsString("DIRECTORY_SEPARATOR . 'Routes'", $source);
        self::assertStringContainsString("DIRECTORY_SEPARATOR . 'Kernel.php'", $source);
        self::assertStringContainsString("'beike/Services'", $source);
        self::assertStringContainsString("'beike/Shop/Services'", $source);
        self::assertStringContainsString('opcache_reset', $source);
    }

    public function test_runtime_service_change_invalidates_generated_caches(): void
    {
        DeploymentCacheInvalidator::refresh($this->root, true);
        $routeCache = $this->root . '/bootstrap/cache/routes-v7.php';
        file_put_contents($routeCache, 'current');

        file_put_contents($this->root . '/beike/Services/CurrencyService.php', '<?php // changed runtime service');
        DeploymentCacheInvalidator::refresh($this->root, true);

        self::assertFileDoesNotExist($routeCache);

        DeploymentCacheInvalidator::refresh($this->root, true);
        file_put_contents($routeCache, 'current');

        file_put_contents($this->root . '/beike/Shop/Services/AddressAutocompleteService.php', '<?php // changed address service');
        DeploymentCacheInvalidator::refresh($this->root, true);

        self::assertFileDoesNotExist($routeCache);
    }

    public function test_fingerprint_scan_is_throttled_for_normal_web_requests(): void
    {
        DeploymentCacheInvalidator::refresh($this->root);
        $routeCache = $this->root . '/bootstrap/cache/routes-v7.php';
        file_put_contents($routeCache, 'current');
        file_put_contents($this->root . '/beike/Services/CurrencyService.php', '<?php // pending deployment');

        DeploymentCacheInvalidator::refresh($this->root);
        self::assertFileExists($routeCache);

        touch($this->root . '/bootstrap/cache/longos-bootstrap.checked', time() - 20);
        DeploymentCacheInvalidator::refresh($this->root);
        self::assertFileDoesNotExist($routeCache);
    }
}
