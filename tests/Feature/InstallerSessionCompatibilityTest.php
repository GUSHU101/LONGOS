<?php

namespace Tests\Feature;

use App\Http\Middleware\PrepareInstallerSession;
use App\Saas\TenantFinder\DomainTenantFinder;
use Beike\Installer\Controllers\DatabaseController;
use Beike\Installer\Controllers\FinalController;
use Beike\Installer\Helpers\DatabaseManager;
use Beike\Installer\Helpers\EnvironmentManager;
use Beike\Installer\Helpers\FinalInstallManager;
use Beike\Installer\Helpers\InstallationStateManager;
use Beike\Installer\Helpers\InstalledFileManager;
use Beike\Installer\Helpers\InstallerSetupHandoff;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class InstallerSessionCompatibilityTest extends TestCase
{
    private string $originalStoragePath;

    private string $temporaryStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->originalStoragePath  = $app->storagePath();
        $this->temporaryStoragePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-installer-session-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryStoragePath, 0775, true);
        $app->useStoragePath($this->temporaryStoragePath);
    }

    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStoragePath);
        DB::disconnect();
        Facade::clearResolvedInstances();
        Mockery::close();
        foreach ((array) glob($this->temporaryStoragePath . DIRECTORY_SEPARATOR . '*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->temporaryStoragePath);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_resumed_installer_reads_boolean_flags_from_laravel_session_store(): void
    {
        self::assertFalse(method_exists(Store::class, 'boolean'));

        $signature = str_repeat('a', 64);
        $database  = $this->temporaryStoragePath . DIRECTORY_SEPARATOR . 'installer.sqlite';
        touch($database);
        config([
            'database.default'                     => 'sqlite',
            'database.connections.sqlite.driver'   => 'sqlite',
            'database.connections.sqlite.database' => $database,
        ]);
        DB::purge('sqlite');
        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('locale')->default('zh_cn');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $session   = new Store('installer-test', new ArraySessionHandler(120));
        $session->put([
            'installer.admin'           => [
                'admin_email'    => 'admin@example.com',
                'admin_password' => 'StrongPassword123!',
            ],
            'installer.migrated'        => $signature,
        ]);

        $request = Request::create('/installer/database', 'GET');
        $request->setLaravelSession($session);
        app()->instance('request', $request);

        Artisan::shouldReceive('call')->once()->with('saas:install', [
            '--domain' => (string) config('app.url'),
            '--code'   => 'main',
            '--name'   => 'LONGOS Control Site',
        ])->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('config:clear')->andReturn(0);

        $stage = 'database_migrated';
        $state = Mockery::mock(InstallationStateManager::class, function (MockInterface $mock) use ($signature, &$stage): void {
            $ranks = [
                'schema_migrated'   => 1,
                'database_migrated' => 2,
                'admin_created'     => 3,
                'saas_registered'   => 4,
                'completed'         => 5,
            ];
            $mock->shouldReceive('currentDatabaseSignature')->times(3)->andReturn($signature);
            $mock->shouldReceive('hasReached')->times(12)->andReturnUsing(
                static function (string $actualSignature, string $expectedStage) use ($signature, $ranks, &$stage): bool {
                    return $actualSignature === $signature && $ranks[$stage] >= $ranks[$expectedStage];
                },
            );
            $mock->shouldReceive('write')->times(3)->andReturnUsing(
                static function (string $actualSignature, string $newStage, array $completion = []) use ($signature, &$stage): bool {
                    if ($actualSignature !== $signature) {
                        return false;
                    }
                    if ($newStage === 'completed'
                        && (($completion['admin_email'] ?? null) !== 'admin@example.com'
                            || ($completion['saas'] ?? null)     !== true)) {
                        return false;
                    }
                    $stage = $newStage;

                    return true;
                },
            );
        });
        $environment = Mockery::mock(EnvironmentManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('setValues')->once()->with([
                'SAAS_ENABLED'        => 'true',
                'CACHE_DRIVER'        => 'database',
                'CACHE_DB_CONNECTION' => 'saas',
            ])->andReturn(true);
        });

        $controller = new DatabaseController(
            Mockery::mock(DatabaseManager::class),
            $environment,
            $state,
            new InstallerSetupHandoff,
        );
        $adminResponse = $controller->index();
        self::assertSame(route('installer.database'), $adminResponse->getTargetUrl());
        self::assertTrue((bool) $session->get('installer.admin_created'));
        self::assertTrue(DB::table('admin_users')->where('email', 'admin@example.com')->exists());

        $saasResponse = $controller->index();
        self::assertSame(route('installer.database'), $saasResponse->getTargetUrl());
        self::assertTrue((bool) $session->get('installer.saas_registered'));

        $response = $controller->index();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(route('installer.final'), $response->getTargetUrl());
        self::assertSame('admin@example.com', $session->get('installer.completed.admin_email'));
    }

    public function test_database_stage_persists_progress_before_redirecting(): void
    {
        $signature = str_repeat('b', 64);
        config(['database.default' => 'mysql']);

        $session = new Store('installer-migration-test', new ArraySessionHandler(120));
        $setup   = [
            'admin_email'    => 'owner@example.com',
            'admin_password' => 'StrongPassword123!',
        ];
        $request = Request::create('/installer/database', 'GET');
        $request->setLaravelSession($session);
        $handoff = new InstallerSetupHandoff;
        $handoff->queue($request, $setup, $signature);
        $queued = collect(Cookie::getQueuedCookies())->first(
            static fn ($cookie): bool => $cookie->getName() === InstallerSetupHandoff::COOKIE,
        );
        self::assertNotNull($queued);
        $request->cookies->set(InstallerSetupHandoff::COOKIE, $queued->getValue());
        self::assertNull($handoff->recover($request, str_repeat('c', 64)));
        app()->instance('request', $request);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('migrate')->once()->andReturn([
            'status'  => 'success',
            'message' => 'ok',
        ]);
        $database->shouldReceive('seed')->once()->andReturn([
            'status'  => 'success',
            'message' => 'ok',
        ]);
        $stage = 'database_validated';
        $state = Mockery::mock(InstallationStateManager::class);
        $state->shouldReceive('currentDatabaseSignature')->twice()->andReturn($signature);
        $state->shouldReceive('hasReached')->times(8)->andReturnUsing(
            static function (string $actualSignature, string $expectedStage) use ($signature, &$stage): bool {
                $ranks = [
                    'database_validated' => 0,
                    'schema_migrated'    => 1,
                    'database_migrated'  => 2,
                    'admin_created'      => 3,
                    'saas_registered'    => 4,
                ];

                return $actualSignature === $signature && $ranks[$stage] >= $ranks[$expectedStage];
            },
        );
        $state->shouldReceive('write')->twice()->andReturnUsing(
            static function (string $actualSignature, string $newStage) use ($signature, &$stage): bool {
                if ($actualSignature !== $signature) {
                    return false;
                }
                $stage = $newStage;

                return true;
            },
        );

        $controller = new DatabaseController(
            $database,
            Mockery::mock(EnvironmentManager::class),
            $state,
            $handoff,
        );
        $response = $controller->index();

        self::assertSame(route('installer.database'), $response->getTargetUrl());
        self::assertSame($signature, $session->get('installer.schema_migrated'));

        $response = $controller->index();

        self::assertSame(route('installer.database'), $response->getTargetUrl());
        self::assertSame($signature, $session->get('installer.migrated'));
        self::assertSame('owner@example.com', $session->get('installer.admin.admin_email'));
    }

    public function test_progress_page_renders_before_running_the_next_installation_phase(): void
    {
        $signature = str_repeat('d', 64);
        config(['database.default' => 'mysql']);
        app('view')->addNamespace('installer', base_path('beike/Installer/Views'));
        app('translator')->addNamespace('installer', base_path('beike/Installer/Lang'));

        $session = new Store('installer-progress-test', new ArraySessionHandler(120));
        $session->put('installer.admin', [
            'admin_email'    => 'owner@example.com',
            'admin_password' => 'StrongPassword123!',
        ]);
        $request = Request::create('/installer/database', 'GET');
        $request->setLaravelSession($session);
        app()->instance('request', $request);

        $state = Mockery::mock(InstallationStateManager::class);
        $state->shouldReceive('currentDatabaseSignature')->once()->andReturn($signature);
        $state->shouldReceive('matches')->once()->with($signature)->andReturn(false);

        $controller = new DatabaseController(
            Mockery::mock(DatabaseManager::class),
            Mockery::mock(EnvironmentManager::class),
            $state,
            new InstallerSetupHandoff,
        );

        $html = $controller->progress()->render();

        self::assertStringContainsString(route('installer.database.run'), $html);
        self::assertStringContainsString('installation-step-form', $html);
        self::assertStringContainsString('aria-valuenow="8"', $html);
    }

    public function test_final_page_renders_before_marking_installation_complete(): void
    {
        config(['beike.admin_name' => 'admin']);
        app('view')->addNamespace('installer', base_path('beike/Installer/Views'));
        app('translator')->addNamespace('installer', base_path('beike/Installer/Lang'));
        $session = new Store('installer-final-test', new ArraySessionHandler(120));
        $session->put('installer.completed', [
            'admin_email' => 'admin@example.com',
            'saas'        => true,
            'base_domain' => 'shops.example.com',
            'dns_targets' => ['192.0.2.10'],
        ]);
        $request = Request::create('/installer/final', 'GET');
        $request->setLaravelSession($session);
        app()->instance('request', $request);

        $finalInstall = Mockery::mock(FinalInstallManager::class);
        $finalInstall->shouldReceive('runFinal')->once()->andReturn('');
        $fileManager = Mockery::mock(InstalledFileManager::class);
        $fileManager->shouldReceive('update')->once()->andReturn('installed');
        $state = Mockery::mock(InstallationStateManager::class);
        $state->shouldReceive('clear')->once();

        $response = (new FinalController)->index($fileManager, $finalInstall, $state);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('admin@example.com', $response->getContent());
        self::assertStringContainsString('*.shops.example.com', $response->getContent());
        self::assertNull($session->get('installer.completed'));
    }

    public function test_installer_session_is_stable_even_when_application_session_configuration_changes(): void
    {
        config([
            'session.driver' => 'database',
            'session.cookie' => 'longos_installer_session_tenant_hash',
            'session.path'   => '/',
            'session.domain' => '.example.com',
            'session.secure' => false,
        ]);

        $request = Request::create('https://shop.example.com/installer/environment', 'GET', [], [], [], [
            'HTTPS' => 'on',
        ]);

        $response = (new PrepareInstallerSession)->handle($request, static function (): Response {
            self::assertSame('file', config('session.driver'));
            self::assertSame('longos_installer_session', config('session.cookie'));
            self::assertSame('/installer', config('session.path'));
            self::assertNull(config('session.domain'));
            self::assertTrue(config('session.secure'));
            self::assertDirectoryExists(storage_path('framework/sessions'));

            return response('ok');
        });

        self::assertSame('ok', $response->getContent());
    }

    public function test_tenant_finder_never_activates_a_tenant_during_an_incomplete_installation(): void
    {
        config(['saas.enabled' => true]);
        $request = Request::create('https://nybgbar.site/installer/environment', 'GET');

        $tenant = (new DomainTenantFinder)->findForRequest($request);

        self::assertNull($tenant);
        self::assertTrue((bool) $request->attributes->get(DomainTenantFinder::REQUEST_ATTRIBUTE));
    }

    public function test_http_kernel_keeps_installer_session_across_configuration_changes(): void
    {
        $sessionDirectory = storage_path('framework/sessions');
        app('files')->ensureDirectoryExists($sessionDirectory, 0775);

        Route::middleware('installer')->get('/installer/__session/write', static function (Request $request): Response {
            $request->session()->put('installer.kernel_probe', 'persisted');

            return response('written');
        });
        Route::middleware('installer')->get('/installer/__session/read', static function (Request $request): Response {
            return response((string) $request->session()->get('installer.kernel_probe', 'missing'));
        });

        config([
            'session.driver' => 'array',
            'session.cookie' => 'longos_installer_session_old_tenant',
            'session.path'   => '/',
            'session.domain' => '.nybgbar.site',
        ]);

        $kernel       = app(HttpKernel::class);
        $writeRequest = Request::create('https://nybgbar.site/installer/__session/write', 'GET', [], [], [], [
            'HTTP_HOST'       => 'nybgbar.site',
            'HTTPS'           => 'on',
            'SERVER_NAME'     => 'nybgbar.site',
            'SERVER_PORT'     => 443,
            'REQUEST_SCHEME'  => 'https',
        ]);
        $writeResponse = $kernel->handle($writeRequest);
        $kernel->terminate($writeRequest, $writeResponse);

        $sessionCookie = collect($writeResponse->headers->getCookies())->first(
            static fn ($cookie): bool => $cookie->getName() === 'longos_installer_session',
        );
        self::assertNotNull($sessionCookie);
        self::assertSame('/installer', $sessionCookie->getPath());
        self::assertNull($sessionCookie->getDomain());

        // This is the transition that used to happen after .env was replaced:
        // the global application session changed between two HTTP requests.
        config([
            'session.driver' => 'database',
            'session.cookie' => 'longos_session',
            'session.path'   => '/',
            'session.domain' => null,
        ]);

        $readRequest = Request::create(
            'https://nybgbar.site/installer/__session/read',
            'GET',
            [],
            ['longos_installer_session' => $sessionCookie->getValue()],
            [],
            [
                'HTTP_HOST'      => 'nybgbar.site',
                'HTTPS'          => 'on',
                'SERVER_NAME'    => 'nybgbar.site',
                'SERVER_PORT'    => 443,
                'REQUEST_SCHEME' => 'https',
            ],
        );
        $readResponse = $kernel->handle($readRequest);
        $kernel->terminate($readRequest, $readResponse);

        self::assertSame(200, $readResponse->getStatusCode());
        self::assertSame('persisted', $readResponse->getContent());
    }
}
