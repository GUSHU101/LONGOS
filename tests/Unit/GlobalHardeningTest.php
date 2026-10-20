<?php

namespace Tests\Unit;

use App\Tools\Contracts\RepositoryInterface;
use App\Tools\Process\Installer;
use App\Tools\Process\Runner;
use App\Tools\Process\Updater;
use Beike\Admin\Services\DownloadService;
use Beike\Libraries\ToolCache;
use Beike\Plugin\Manager;
use Beike\Repositories\VerifyCodeRepo;
use Beike\Services\QueueService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Plugin\BeikeShopTestSandbox\Middleware\Shop\ForceSandboxCheckout;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class GlobalHardeningTest extends TestCase
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

    public function test_test_sandbox_checkout_takeover_is_explicitly_opt_in(): void
    {
        $root       = dirname(__DIR__, 2);
        $config     = (string) file_get_contents($root . '/plugins/BeikeShopTestSandbox/Config/beikeshop_test.php');
        $bootstrap  = (string) file_get_contents($root . '/plugins/BeikeShopTestSandbox/Bootstrap.php');
        $middleware = new ForceSandboxCheckout;

        self::assertStringContainsString("env('BEIKESHOP_TEST_CHECKOUT_TAKEOVER', false)", $config);
        self::assertStringContainsString("config('beikeshop_test.checkout_takeover.enabled', false)", $bootstrap);

        config(['beikeshop_test.checkout_takeover.enabled' => false]);
        $response = $middleware->handle(
            Request::create('/checkout'),
            static fn (): Response => new Response('core-checkout'),
        );
        self::assertSame('core-checkout', $response->getContent());

        config(['beikeshop_test.checkout_takeover.enabled' => true]);
        $response = $middleware->handle(
            Request::create('/checkout'),
            static fn (): Response => new Response('must-not-run'),
        );
        self::assertTrue($response->isRedirect());
        self::assertMatchesRegularExpression('#/checkout/\d{12}$#', (string) $response->headers->get('Location'));
    }

    public function test_dependency_runner_rejects_shell_command_strings(): void
    {
        $runner = new Runner($this->createMock(RepositoryInterface::class));

        $this->expectException(InvalidArgumentException::class);
        $runner->run('composer require "vendor/package:$(malicious)"');
    }

    public function test_module_installer_uses_argument_vectors_and_is_wired_to_its_repository(): void
    {
        $installer  = new Installer('vendor/package', '^1.2', 'composer');
        $process    = $installer->getProcess();
        $source     = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Tools/Process/Installer.php');
        $repository = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Tools/FileRepository.php');

        self::assertStringContainsString('composer', $process->getCommandLine());
        self::assertStringContainsString('vendor/package:', $process->getCommandLine());
        self::assertStringContainsString('1.2', $process->getCommandLine());
        self::assertStringNotContainsString('&&', $process->getCommandLine());
        self::assertStringNotContainsString('fromShellCommandline', $source);
        self::assertStringContainsString('->setRepository($this)', $repository);

        $this->expectException(InvalidArgumentException::class);
        (new Installer('vendor/$(malicious)', '^1.2', 'composer'))->getProcess();
    }

    public function test_updater_validates_composer_package_constraints(): void
    {
        $updater = new Updater($this->createMock(RepositoryInterface::class));
        $method  = new ReflectionMethod($updater, 'packageConstraint');

        self::assertSame('vendor/package:^1.2', $method->invoke($updater, 'vendor/package', '^1.2'));

        $this->expectException(InvalidArgumentException::class);
        $method->invoke($updater, 'vendor/$(malicious)', '^1.2');
    }

    public function test_plugin_zip_paths_and_symbolic_links_are_rejected(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-zip-' . bin2hex(random_bytes(6));
        $zipPath   = $directory . DIRECTORY_SEPARATOR . 'plugin.zip';
        mkdir($directory, 0700, true);

        try {
            $manager = new Manager;
            $path    = new ReflectionMethod($manager, 'isValidZipPath');

            self::assertTrue($path->invoke($manager, 'Example/Bootstrap.php', $directory));
            self::assertFalse($path->invoke($manager, '../outside.php', $directory));
            self::assertFalse($path->invoke($manager, 'Example/../outside.php', $directory));
            self::assertFalse($path->invoke($manager, 'C:/outside.php', $directory));
            self::assertFalse($path->invoke($manager, 'Example\\outside.php', $directory));

            $zip = new ZipArchive;
            self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            self::assertTrue($zip->addFromString('Example/link', '../outside.php'));
            self::assertTrue($zip->setExternalAttributesName('Example/link', ZipArchive::OPSYS_UNIX, 0120777 << 16));
            $zip->close();

            self::assertTrue($zip->open($zipPath));
            $validate = new ReflectionMethod($manager, 'validateZipContents');

            try {
                $validate->invoke($manager, $zip, $directory);
                self::fail('A symbolic-link ZIP entry must be rejected.');
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                self::assertStringContainsString('Symbolic links are not allowed', $exception->getMessage());
            } finally {
                $zip->close();
            }
        } finally {
            if (is_file($zipPath)) {
                unlink($zipPath);
            }
            rmdir($directory);
        }
    }

    public function test_malformed_local_plugin_manifest_is_isolated_instead_of_crashing_the_application(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-bad-plugin-' . bin2hex(random_bytes(6));
        $plugin    = $directory . DIRECTORY_SEPARATOR . 'BrokenPackage';
        mkdir($plugin, 0700, true);
        file_put_contents($plugin . DIRECTORY_SEPARATOR . 'config.json', '{"code":');

        $original = config('plugins.directory');
        config(['plugins.directory' => $directory]);

        try {
            $manager = new Manager;

            self::assertCount(0, $manager->getPlugins());
            self::assertArrayHasKey('BrokenPackage', $manager->getPackageIssues());
            self::assertStringContainsString('清单解析', $manager->getPackageIssues()['BrokenPackage'][0]);
        } finally {
            config(['plugins.directory' => $original]);
            unlink($plugin . DIRECTORY_SEPARATOR . 'config.json');
            rmdir($plugin);
            rmdir($directory);
        }
    }

    public function test_authentication_endpoints_are_rate_limited_and_csrf_exemption_is_exact(): void
    {
        $root       = dirname(__DIR__, 2);
        $provider   = (string) file_get_contents($root . '/app/Providers/RouteServiceProvider.php');
        $admin      = (string) file_get_contents($root . '/beike/Admin/Routes/admin.php');
        $shop       = (string) file_get_contents($root . '/beike/Shop/Routes/shop.php');
        $csrf       = (string) file_get_contents($root . '/app/Http/Middleware/VerifyCsrfToken.php');

        self::assertStringContainsString("RateLimiter::for('auth-login'", $provider);
        self::assertStringContainsString("RateLimiter::for('auth-recovery'", $provider);
        self::assertStringContainsString("RateLimiter::for('auth-register'", $provider);
        self::assertStringContainsString("hash('sha256'", $provider);
        self::assertStringContainsString("middleware('throttle:auth-login')", $admin);
        self::assertStringContainsString("middleware('throttle:auth-recovery')", $admin);
        self::assertStringContainsString("middleware('throttle:auth-login')", $shop);
        self::assertStringContainsString("middleware('throttle:auth-register')", $shop);
        self::assertStringContainsString("'callback/stripe'", $csrf);
        self::assertStringNotContainsString("'callback/*'", $csrf);
    }

    public function test_password_recovery_codes_are_keyed_and_not_logged(): void
    {
        $root     = dirname(__DIR__, 2);
        $admin    = (string) file_get_contents($root . '/beike/Admin/Services/UserService.php');
        $shop     = (string) file_get_contents($root . '/beike/Shop/Services/AccountService.php');
        $account  = 'Customer@Example.com';
        $code     = '012345';
        $encoded  = VerifyCodeRepo::encode($account, $code);

        self::assertSame(16, strlen($encoded));
        self::assertNotSame($code, $encoded);
        self::assertTrue(VerifyCodeRepo::matches($encoded, strtolower($account), $code));
        self::assertFalse(VerifyCodeRepo::matches($encoded, $account, '654321'));
        self::assertStringContainsString('random_int(0, 999999)', $admin);
        self::assertStringContainsString('random_int(0, 999999)', $shop);
        self::assertStringNotContainsString('找回密码验证码', $admin);
        self::assertStringNotContainsString('找回密码验证码', $shop);
    }

    public function test_unipush_requires_verified_public_https(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/beike/Admin/Http/Controllers/AppPushController.php');
        $service    = (string) file_get_contents(dirname(__DIR__, 2) . '/beike/Admin/Services/UniPushService.php');

        self::assertStringNotContainsString("'verify' => false", $service);
        self::assertStringContainsString("'verify' => config('beike.http_verify_ssl', true)", $service);
        self::assertStringContainsString('FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE', $service);
        self::assertStringContainsString('CURLOPT_RESOLVE', $service);
        self::assertStringContainsString('random_int(16, 32)', $service);
        self::assertStringContainsString("'link.type'", $controller);
        self::assertStringContainsString("'required', 'in:product,category,page,order,custom'", $controller);
        self::assertStringContainsString('SensitiveValueRedactor::redact', $service);
    }

    public function test_marketplace_downloads_reject_private_urls_and_unsafe_archives(): void
    {
        $service = new DownloadService;
        $url     = new ReflectionMethod($service, 'validateDownloadUrl');

        foreach (['http://example.com/plugin.zip', 'https://127.0.0.1/plugin.zip', 'https://user:secret@example.com/plugin.zip'] as $unsafeUrl) {
            try {
                $url->invoke($service, $unsafeUrl);
                self::fail("Unsafe plugin URL was accepted: {$unsafeUrl}");
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable) {
                $this->addToAssertionCount(1);
            }
        }

        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-download-zip-' . bin2hex(random_bytes(6));
        $zipPath   = $directory . DIRECTORY_SEPARATOR . 'plugin.zip';
        mkdir($directory, 0700, true);

        try {
            $zip = new ZipArchive;
            self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            self::assertTrue($zip->addFromString('Plugin/Example/link', '../outside.php'));
            self::assertTrue($zip->setExternalAttributesName('Plugin/Example/link', ZipArchive::OPSYS_UNIX, 0120777 << 16));
            $zip->close();

            $scan = new ReflectionMethod($service, 'scanZipForMaliciousFiles');

            try {
                $scan->invoke($service, $zipPath, true);
                self::fail('A marketplace plugin symbolic link must be rejected.');
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable) {
                $this->addToAssertionCount(1);
            }
        } finally {
            if (is_file($zipPath)) {
                unlink($zipPath);
            }
            rmdir($directory);
        }
    }

    public function test_queue_worker_arguments_do_not_use_a_shell(): void
    {
        $command   = new ReflectionMethod(QueueService::class, 'workerCommand');
        $arguments = $command->invoke(null, [
            'php_path'        => '/usr/bin/php',
            'queue'           => 'default,emails',
            'tries'           => 3,
            'memory'          => 256,
            'sleep'           => 1,
            'stop_when_empty' => true,
        ]);

        self::assertSame('/usr/bin/php', $arguments[0]);
        self::assertContains('--queue=default,emails', $arguments);
        self::assertNotContains('nohup', $arguments);

        $this->expectException(\InvalidArgumentException::class);
        $command->invoke(null, [
            'php_path' => '/usr/bin/php',
            'queue'    => 'default; malicious',
        ]);
    }

    public function test_queue_worker_detection_is_scoped_to_this_project_and_queue(): void
    {
        $matches = new ReflectionMethod(QueueService::class, 'workerMatchesProject');
        $root    = str_replace('\\', '/', base_path());
        $artisan = $root . '/artisan';

        self::assertTrue($matches->invoke(null, "/usr/bin/php {$artisan} queue:work --queue=default,emails", null, 'default'));
        self::assertTrue($matches->invoke(null, 'php artisan queue:work --queue default,emails', base_path(), 'default'));
        self::assertFalse($matches->invoke(null, '/usr/bin/php /srv/other/artisan queue:work --queue=default', '/srv/other', 'default'));
        self::assertFalse($matches->invoke(null, "/usr/bin/php {$artisan} queue:work --queue=emails", null, 'default'));
        self::assertFalse($matches->invoke(null, 'pgrep -af artisan queue:work', base_path(), 'default'));
    }

    public function test_queue_worker_heartbeat_crosses_process_and_container_boundaries(): void
    {
        $originalStorage = app()->storagePath();
        $temporary       = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-queue-heartbeat-' . bin2hex(random_bytes(6));
        mkdir($temporary, 0700, true);
        app()->useStoragePath($temporary);
        $queue = 'test-' . bin2hex(random_bytes(4));

        try {
            self::assertFalse(QueueService::hasRecentWorkerHeartbeat($queue));
            QueueService::recordWorkerHeartbeat($queue . ',emails');
            self::assertTrue(QueueService::hasRecentWorkerHeartbeat($queue));
            self::assertTrue(QueueService::hasRecentWorkerHeartbeat('emails'));
            self::assertFalse(QueueService::hasRecentWorkerHeartbeat('../unsafe'));

            QueueService::recordWorkerHeartbeat($queue, 120, true);
            $files = glob($temporary . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'queue-heartbeats' . DIRECTORY_SEPARATOR . '*.heartbeat');
            self::assertIsArray($files);
            $queueFile = collect($files)->first(function (string $path) use ($queue): bool {
                $decoded = json_decode((string) file_get_contents($path), true);

                return is_array($decoded) && str_contains(basename($path), hash('sha256', $queue));
            });
            self::assertIsString($queueFile);
            file_put_contents($queueFile, json_encode([
                'seen_at'    => time() - 30,
                'busy_until' => time() + 30,
            ], JSON_THROW_ON_ERROR));
            self::assertTrue(QueueService::hasRecentWorkerHeartbeat($queue, 3), 'An active job lease must keep a busy worker visible.');
            file_put_contents($queueFile, json_encode([
                'seen_at'    => time() - 30,
                'busy_until' => 0,
            ], JSON_THROW_ON_ERROR));
            self::assertFalse(QueueService::hasRecentWorkerHeartbeat($queue, 3));

            $provider = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Providers/AppServiceProvider.php');
            self::assertStringContainsString('Queue::looping', $provider);
            self::assertStringContainsString('QueueService::recordWorkerHeartbeat', $provider);
            $job = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/ShopifyProductTransfer/Jobs/ProcessShopifyProductImport.php');
            self::assertStringContainsString('pulseWorkerLease', $job);
            self::assertStringContainsString('releaseWorkerLease', $job);
        } finally {
            app()->useStoragePath($originalStorage);
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($temporary);
        }
    }

    public function test_api_token_file_cache_is_private_and_lock_safe(): void
    {
        $originalStorage = app()->storagePath();
        $temporary       = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-tool-cache-' . bin2hex(random_bytes(6));
        mkdir($temporary, 0700, true);
        app()->useStoragePath($temporary);

        try {
            self::assertTrue(ToolCache::put('tenant:access_token', 'secret-value', 60));
            self::assertSame('secret-value', ToolCache::get('tenant:access_token'));
            self::assertTrue(ToolCache::has('tenant:access_token'));
            self::assertFalse(ToolCache::add('tenant:access_token', 'must-not-replace', 60));
            self::assertSame('secret-value', ToolCache::get('tenant:access_token'));
            self::assertFalse(ToolCache::forgetIfValue('tenant:access_token', 'wrong-owner'));
            self::assertSame('secret-value', ToolCache::get('tenant:access_token'));
            self::assertTrue(ToolCache::forgetIfValue('tenant:access_token', 'secret-value'));
            self::assertFalse(ToolCache::has('tenant:access_token'));
            self::assertTrue(ToolCache::add('tenant:access_token', 'new-owner', 60));

            $path = new ReflectionMethod(ToolCache::class, 'filePath');
            $file = $path->invoke(null, 'tenant:access_token');
            self::assertFileExists($file);
            self::assertSame(64, strlen(pathinfo($file, PATHINFO_FILENAME)));

            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/beike/Libraries/ToolCache.php');
            self::assertStringContainsString('@mkdir($directory, 0700, true)', $source);
            self::assertStringContainsString('@chmod($file, 0600)', $source);
            self::assertStringContainsString('flock($handle, LOCK_SH)', $source);
        } finally {
            ToolCache::forget('tenant:access_token');
            app()->useStoragePath($originalStorage);
            @rmdir($temporary . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'tool_cache');
            @rmdir($temporary . DIRECTORY_SEPARATOR . 'app');
            @rmdir($temporary);
        }
    }
}
