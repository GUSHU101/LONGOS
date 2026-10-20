<?php

namespace Tests\Unit;

use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SaasWebInstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 2);
    }

    public function test_web_installer_collects_the_required_saas_configuration(): void
    {
        $config = require $this->root . '/beike/Installer/config.php';
        $rules  = $config['environment']['form']['rules'];

        self::assertSame('8.2', $config['core']['minPhpVersion']);
        self::assertArrayHasKey('saas_database_mode', $rules);
        self::assertStringContainsString('required_if:saas_database_mode,database', $rules['saas_tenant_admin_username']);
        self::assertArrayHasKey('saas_base_domain', $rules);
        self::assertArrayHasKey('saas_database_prefix', $rules);
        self::assertArrayHasKey('saas_tenant_admin_username', $rules);

        $form = (string) file_get_contents($this->root . '/beike/Installer/Views/environment-wizard.blade.php');
        self::assertStringContainsString('name="saas_database_mode"', $form);
        self::assertStringContainsString('value="prefix"', $form);
        self::assertStringContainsString('name="saas_base_domain"', $form);
        self::assertStringContainsString('name="saas_tenant_admin_username"', $form);
        self::assertStringContainsString('name="saas_tenant_admin_password"', $form);
        self::assertStringContainsString('saas_prefix_mode_help', $form);

        $controller = (string) file_get_contents($this->root . '/beike/Installer/Controllers/EnvironmentController.php');
        self::assertStringContainsString("input('saas_database_mode', 'prefix') === 'database'", $controller);
        self::assertStringContainsString('An ordinary per-database account is enough', $controller);

        $environment = (string) file_get_contents($this->root . '/beike/Installer/Helpers/EnvironmentManager.php');
        self::assertStringContainsString("'SAAS_DATABASE_MODE'", $environment);
        self::assertStringContainsString("'SAAS_SHARED_DATABASE'", $environment);
        self::assertStringContainsString("'CACHE_DRIVER'                      => 'file'", $environment);
        self::assertStringContainsString("'LONGOS_DEPLOYMENT_PLATFORM'        => 'docker'", $environment);
        self::assertStringContainsString('web_installer_tls_notice', $form);

        $environmentController = (string) file_get_contents($this->root . '/beike/Installer/Controllers/EnvironmentController.php');
        $databaseController    = (string) file_get_contents($this->root . '/beike/Installer/Controllers/DatabaseController.php');
        $handoff               = (string) file_get_contents($this->root . '/beike/Installer/Helpers/InstallerSetupHandoff.php');
        self::assertStringContainsString('setupHandoff->queue', $environmentController);
        self::assertStringContainsString('setupHandoff->recover', $databaseController);
        self::assertStringContainsString('setupHandoff->forget', $databaseController);
        self::assertStringContainsString("'/installer'", $handoff);
        self::assertStringContainsString('Crypt::encryptString', $handoff);
        self::assertStringContainsString('hash_equals', $handoff);

        $kernel = (string) file_get_contents($this->root . '/app/Http/Kernel.php');
        self::assertStringContainsString('PrepareInstallerSession::class', $kernel);
        self::assertMatchesRegularExpression(
            "/'installer'\\s*=>\\s*\\[.*?PrepareInstallerSession::class.*?AddQueuedCookiesToResponse::class.*?VerifyCsrfToken::class/s",
            $kernel,
        );

        $sessionConfig = (string) file_get_contents($this->root . '/config/session.php');
        self::assertStringNotContainsString("\$_SERVER['REQUEST_URI']", $sessionConfig);

        $tenantFinder = (string) file_get_contents($this->root . '/app/Saas/TenantFinder/DomainTenantFinder.php');
        self::assertStringContainsString("str_starts_with(\$path, '/installer/')", $tenantFinder);
        self::assertStringContainsString('! installed()', $tenantFinder);

        $frontController = (string) file_get_contents($this->root . '/public/index.php');
        self::assertStringContainsString("version_compare(PHP_VERSION, '8.2'", $frontController);
        self::assertStringContainsString('$written === false', $frontController);
    }

    public function test_web_install_registers_the_control_site_before_enabling_saas(): void
    {
        $controller = (string) file_get_contents($this->root . '/beike/Installer/Controllers/DatabaseController.php');

        $registerAt = strpos($controller, "Artisan::call('saas:install'");
        $enableAt   = strpos($controller, "'SAAS_ENABLED'        => 'true'");

        self::assertIsInt($registerAt);
        self::assertIsInt($enableAt);
        self::assertLessThan($enableAt, $registerAt);
    }

    public function test_web_installer_is_hardened_for_managed_php_and_resumable_failures(): void
    {
        $config     = require $this->root . '/beike/Installer/config.php';
        $controller = (string) file_get_contents($this->root . '/beike/Installer/Controllers/DatabaseController.php');
        $routes     = (string) file_get_contents($this->root . '/beike/Installer/Routes/installer.php');
        $mutex      = (string) file_get_contents($this->root . '/beike/Installer/Middleware/SerializeInstallerOperations.php');
        $final      = (string) file_get_contents($this->root . '/beike/Installer/Controllers/FinalController.php');

        self::assertSame('512M', $config['runtime']['memory_limit']);
        self::assertGreaterThanOrEqual(256 * 1024 * 1024, $config['runtime']['minimum_memory_bytes']);
        self::assertSame(0, $config['runtime']['max_execution_seconds']);
        self::assertGreaterThanOrEqual(1, $config['runtime']['operation_lock_wait_seconds']);
        self::assertLessThanOrEqual(10, $config['runtime']['operation_lock_wait_seconds']);
        self::assertStringContainsString('prepareInstallerRuntime', $controller);
        self::assertStringContainsString('@set_time_limit', $controller);
        self::assertStringContainsString("DB::purge('saas')", $controller);
        self::assertStringContainsString('LONGOS web installation failed.', $controller);
        self::assertStringContainsString("'stage'", $controller);
        self::assertStringNotContainsString('session()->boolean', $controller);
        self::assertSame(1, substr_count($controller, "Artisan::call('postgreSQL:sequence')"));
        self::assertStringContainsString("DB::table('admin_users')->orderBy('id')->value('email')", $controller);
        self::assertGreaterThanOrEqual(3, substr_count($controller, "return redirect()->route('installer.database')"));
        self::assertGreaterThanOrEqual(3, substr_count($routes, 'SerializeInstallerOperations::class'));
        self::assertStringContainsString("Route::get('database', [DatabaseController::class, 'progress'])", $routes);
        self::assertStringContainsString("Route::post('database/run', [DatabaseController::class, 'index'])", $routes);
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $mutex);
        self::assertStringContainsString('@set_time_limit', $mutex);

        $progress = (string) file_get_contents($this->root . '/beike/Installer/Views/installing.blade.php');
        self::assertStringContainsString("route('installer.database.run')", $progress);
        self::assertStringContainsString('installation-step-form', $progress);

        $renderAt = strpos($final, "view('installer::finished', \$data)->render()");
        $markerAt = strpos($final, '$fileManager->update()');
        self::assertIsInt($renderAt);
        self::assertIsInt($markerAt);
        self::assertLessThan($markerAt, $renderAt);
        self::assertStringNotContainsString('getEnvContent', $final);
        self::assertStringNotContainsString('finalEnvFile', $final);
    }

    public function test_all_session_store_calls_use_real_laravel_methods(): void
    {
        $methods = [];
        foreach (['app', 'beike', 'plugins', 'routes'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $this->root . DIRECTORY_SEPARATOR . $directory,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                preg_match_all(
                    '/(?:request\(\)->)?session\(\)->([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
                    (string) file_get_contents($file->getPathname()),
                    $matches,
                );
                foreach ($matches[1] as $method) {
                    $methods[$method] = true;
                }
            }
        }

        self::assertNotEmpty($methods);
        foreach (array_keys($methods) as $method) {
            self::assertTrue(method_exists(Store::class, $method), "Unknown Laravel Session Store method: {$method}");
        }
    }

    public function test_installer_defers_database_cache_until_migrations_are_complete(): void
    {
        $cacheConfig = (string) file_get_contents($this->root . '/config/cache.php');
        self::assertStringContainsString("\$configuredCacheDriver === 'database'", $cacheConfig);
        self::assertStringContainsString("! is_file(storage_path('installed'))", $cacheConfig);

        $webEnvironment = (string) file_get_contents($this->root . '/beike/Installer/Helpers/EnvironmentManager.php');
        $webFinalizer   = (string) file_get_contents($this->root . '/beike/Installer/Controllers/DatabaseController.php');
        $cliInstaller   = (string) file_get_contents($this->root . '/beike/Console/Commands/BeikeShopInstall.php');

        self::assertStringContainsString("'CACHE_DRIVER'                      => 'file'", $webEnvironment);
        self::assertStringContainsString("'CACHE_DRIVER'        => 'database'", $webFinalizer);
        self::assertStringContainsString("Config::set('cache.default', 'file')", $cliInstaller);
        self::assertStringContainsString("'CACHE_DRIVER'        => 'database'", $cliInstaller);
    }

    public function test_seeders_are_not_wrapped_in_transactions_that_mysql_ddl_can_implicitly_commit(): void
    {
        $webDatabaseManager = (string) file_get_contents($this->root . '/beike/Installer/Helpers/DatabaseManager.php');
        $cliInstaller       = (string) file_get_contents($this->root . '/beike/Console/Commands/BeikeShopInstall.php');
        $tenantProvisioner  = (string) file_get_contents($this->root . '/app/Saas/SiteProvisioner.php');

        self::assertStringContainsString("Artisan::call('db:seed'", $webDatabaseManager);
        self::assertStringNotContainsString('transaction(', $webDatabaseManager);
        self::assertStringContainsString("\$this->callArtisan('db:seed'", $cliInstaller);
        self::assertStringNotContainsString('DB::connection()->transaction', $cliInstaller);

        $seedAt             = strpos($tenantProvisioner, "Artisan::call('db:seed'");
        $configurationTxnAt = strpos($tenantProvisioner, "DB::connection('tenant')->transaction");
        self::assertIsInt($seedAt);
        self::assertIsInt($configurationTxnAt);
        self::assertLessThan($configurationTxnAt, $seedAt);
    }

    public function test_permission_cache_is_initialized_only_after_cache_tables_exist(): void
    {
        $permissionMigration = (string) file_get_contents(
            $this->root . '/database/migrations/2022_08_01_033755_create_permission_tables.php',
        );
        $cacheMigration = (string) file_get_contents(
            $this->root . '/database/migrations/2026_10_03_000500_create_cache_tables.php',
        );

        self::assertStringNotContainsString('forgetCachedPermissions', $permissionMigration);

        $cacheTablesAt = strpos($cacheMigration, "Schema::create('cache_locks'");
        $permissionAt  = strpos($cacheMigration, '$this->forgetPermissionCacheSafely();');
        self::assertIsInt($cacheTablesAt);
        self::assertIsInt($permissionAt);
        self::assertLessThan($permissionAt, $cacheTablesAt);
        self::assertStringContainsString('clearPermissionsCollection', $cacheMigration);
    }

    public function test_all_install_and_tenant_migration_paths_verify_database_readiness(): void
    {
        $readiness   = (string) file_get_contents($this->root . '/app/Saas/Support/DatabaseReadiness.php');
        $web         = (string) file_get_contents($this->root . '/beike/Installer/Helpers/DatabaseManager.php');
        $cli         = (string) file_get_contents($this->root . '/beike/Console/Commands/BeikeShopInstall.php');
        $provisioner = (string) file_get_contents($this->root . '/app/Saas/SiteProvisioner.php');
        $upgrade     = (string) file_get_contents($this->root . '/app/Console/Commands/SaasUpgrade.php');
        $autoUpgrade = (string) file_get_contents($this->root . '/app/Services/DeploymentUpgradeService.php');

        foreach (['permissions', 'roles', 'plugins', 'categories', 'customers', 'carts', 'products', 'orders', 'cache', 'cache_locks'] as $table) {
            self::assertStringContainsString("'{$table}'", $readiness);
        }
        foreach (['theme', 'menu_setting', 'design_setting', 'footer_setting'] as $setting) {
            self::assertStringContainsString("'{$setting}'", $readiness);
        }
        self::assertStringContainsString('DatabaseReadiness::assertMigrated', $web);
        self::assertStringContainsString('DatabaseReadiness::assertSeeded', $web);
        self::assertStringContainsString('DatabaseReadiness::assertMigrated', $cli);
        self::assertStringContainsString('DatabaseReadiness::assertSeeded', $cli);
        self::assertStringContainsString("DatabaseReadiness::assertMigrated('tenant')", $provisioner);
        self::assertStringContainsString("DatabaseReadiness::assertSeeded('tenant'", $provisioner);
        self::assertStringContainsString("DatabaseReadiness::assertMigrated('tenant')", $upgrade);
        self::assertStringContainsString('DatabaseReadiness::assertMigrated', $autoUpgrade);
    }

    public function test_docker_redeployment_is_a_non_interactive_in_place_upgrade(): void
    {
        $nginxStart   = (string) file_get_contents($this->root . '/docker/nginx/start.sh');
        $saasUpgrade  = (string) file_get_contents($this->root . '/app/Console/Commands/SaasUpgrade.php');

        self::assertStringContainsString('[ ! -f storage/installed ]', $nginxStart);
        self::assertStringContainsString('php artisan longos:install', $nginxStart);
        self::assertStringContainsString('saas:upgrade --no-maintenance --if-needed', $nginxStart);
        self::assertStringContainsString('flock -x 9', $nginxStart);
        self::assertStringContainsString('--secrets-stdin', $nginxStart);
        self::assertStringNotContainsString('php artisan optimize:clear', $nginxStart);

        self::assertStringContainsString('{--if-needed', $saasUpgrade);
        self::assertStringContainsString('longos-saas-upgrade.fingerprint', $saasUpgrade);
        self::assertStringContainsString("glob(database_path('migrations/*.php'))", $saasUpgrade);
        self::assertStringContainsString("glob(config_path('*.php'))", $saasUpgrade);
        self::assertStringContainsString("glob(base_path('routes/*.php'))", $saasUpgrade);

        $compose = (string) file_get_contents($this->root . '/docker-compose.yml');
        self::assertStringContainsString('longos-storage:/var/www/html/storage', $compose);
        self::assertStringContainsString('command: ["worker"]', $compose);
        self::assertStringContainsString('command: ["scheduler"]', $compose);
    }

    public function test_provisioning_heartbeats_verify_lease_ownership_instead_of_mysql_changed_rows(): void
    {
        $idempotency = (string) file_get_contents($this->root . '/app/Saas/IdempotencyService.php');
        $provisioner = (string) file_get_contents($this->root . '/app/Saas/SiteProvisioner.php');

        self::assertStringContainsString('$stillOwned = IdempotencyRecord::query()', $idempotency);
        self::assertStringNotContainsString('if ($updated !== 1) {\n            throw new IdempotencyConflict', $idempotency);
        self::assertStringContainsString('$stillOwned = Site::query()', $provisioner);
        self::assertStringContainsString('MySQL can return zero for a successful same-second heartbeat', $provisioner);
    }

    public function test_control_panel_exposes_tenant_site_management(): void
    {
        $routes      = (string) file_get_contents($this->root . '/beike/Admin/Routes/admin.php');
        $view        = (string) file_get_contents($this->root . '/resources/beike/admin/views/pages/saas/sites.blade.php');
        $mailView    = (string) file_get_contents($this->root . '/resources/beike/admin/views/pages/setting/mail.blade.php');
        $api         = (string) file_get_contents($this->root . '/beike/Admin/Http/Controllers/SaasSiteController.php');
        $provisioner = (string) file_get_contents($this->root . '/app/Saas/SiteProvisioner.php');
        $deletion    = (string) file_get_contents($this->root . '/app/Saas/SiteDeletionService.php');

        self::assertStringContainsString("Route::get('manage', [Controllers\\SaasSiteController::class, 'page'])", $routes);
        self::assertStringContainsString("Route::post('sites/batch'", $routes);
        self::assertStringContainsString("Route::delete('sites/{site}'", $routes);
        self::assertStringContainsString("Route::post('sites/{site}/sender-domains'", $routes);
        self::assertStringContainsString("'Idempotency-Key': key", $view);
        self::assertStringContainsString('saas/sites/batch', $view);
        self::assertStringContainsString('.@{{ baseDomain }}', $view);
        self::assertStringContainsString('pendingCreateKey', $view);
        self::assertStringContainsString('pendingCreateSignature', $view);
        self::assertStringContainsString('deleteSite(site)', $view);
        self::assertStringContainsString('confirmation: value', $view);
        self::assertStringContainsString('assignSenderDomain(site)', $view);
        self::assertStringContainsString('mail_sender_domains', $view);
        self::assertStringContainsString('platformSenderDomain', $view);
        self::assertStringContainsString('senderDomainOwners', $view);
        self::assertStringContainsString('主站不占用租户独立发件域', $view);
        self::assertStringContainsString('senderDomainPresets', $mailView);
        self::assertStringContainsString('applySenderDomain(domain)', $mailView);
        self::assertStringContainsString("'Cache-Control', 'no-store, private'", $api);
        self::assertStringContainsString('prepareProvisioningRuntime', $api);
        self::assertStringContainsString('assignSenderDomain(', $api);
        self::assertStringContainsString('MAIL_RELAY_ALLOWED_SENDER_DOMAINS', $api);
        self::assertStringContainsString('gc_collect_cycles();', $provisioner);
        self::assertStringContainsString('Site::STATUS_DELETING', $deletion);
        self::assertStringContainsString('dropPrefixedTenantData', $deletion);
        self::assertStringContainsString('dropOwnedTenantDatabase', $deletion);
    }
}
