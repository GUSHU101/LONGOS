<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SystemUpdaterContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_updater_is_control_root_only_and_maintenance_status_is_reachable(): void
    {
        $controller = $this->read('beike/Admin/Http/Controllers/SystemUpdateController.php');
        $routes     = $this->read('beike/Admin/Routes/admin.php');
        $middleware = $this->read('app/Http/Middleware/PreventRequestsDuringMaintenance.php');

        self::assertStringContainsString('$user?->is_root', $controller);
        self::assertStringContainsString("data_get(\$site->meta, 'control_site', false)", $controller);
        self::assertStringContainsString('isControlHost(request()->getHost())', $controller);
        self::assertStringContainsString("'throttle:3,1'", $routes);
        self::assertStringContainsString("'*/system-updates/status'", $middleware);
        self::assertStringContainsString('system-updates/recover', $routes);
        self::assertStringContainsString('public function recover(', $controller);
    }

    public function test_release_client_requires_github_digest_and_never_uses_branch_zipballs(): void
    {
        $client = $this->read('app/Updates/GitHubReleaseClient.php');

        self::assertStringContainsString('/^sha256:([a-f0-9]{64})$/', $client);
        self::assertStringContainsString("'Accept' => 'application/octet-stream'", $client);
        self::assertStringContainsString('hash_equals(strtolower($expectedAssetUrl), strtolower($assetUrl))', $client);
        self::assertStringContainsString('hash_equals(strtolower($expected), strtolower($url))', $client);
        self::assertStringContainsString('validRepository(string $repository)', $client);
        self::assertStringNotContainsString('zipball_url', $client);
        self::assertStringNotContainsString('tarball_url', $client);
    }

    public function test_release_package_protects_runtime_data_and_host_controls(): void
    {
        $package = $this->read('app/Updates/ReleasePackage.php');
        $builder = $this->read('scripts/build-update-package.php');

        foreach (['.env', 'storage', 'public/cache', 'public/plugin', 'public/upload', 'deploy', 'docker', 'scripts', 'install.sh', 'docker-compose.yml'] as $path) {
            self::assertStringContainsString("'{$path}'", $package);
        }
        self::assertStringContainsString('writeLedger($backup, $ledger)', $package);
        self::assertStringContainsString('readLedger(string $backup)', $package);
        self::assertStringNotContainsString("'install.sh',\n    'LICENSE'", $builder);
        self::assertStringContainsString("'deploy/', 'dist/', 'docker/'", $builder);
        self::assertStringContainsString("getFromName('longos-release.json')", $builder);
        self::assertStringContainsString("'delete' => \$deletes", $builder);
        self::assertStringContainsString('is_link($absolute)', $builder);
    }

    public function test_database_passwords_are_passed_via_process_environment_not_arguments(): void
    {
        $backup = $this->read('app/Updates/DatabaseBackupService.php');

        self::assertStringContainsString("['MYSQL_PWD' =>", $backup);
        self::assertStringContainsString("['PGPASSWORD' =>", $backup);
        self::assertStringNotContainsString('--password=', $backup);
        self::assertStringContainsString("unset(\$entry['connection']['password'])", $backup);
        self::assertStringContainsString('--execute=SHOW FULL TABLES', $backup);
        self::assertStringContainsString('dropUnexpectedMysqlObjects', $backup);
        self::assertStringContainsString('DROP TABLE IF EXISTS ', $backup);
        self::assertStringContainsString('$written !== strlen($buffer)', $backup);
        self::assertStringContainsString('! $closed', $backup);
        self::assertStringContainsString('dropUnexpectedPostgresObjects', $backup);
        self::assertStringContainsString("'--set=ON_ERROR_STOP=1'", $backup);
        self::assertStringContainsString('VACUUM INTO {$quoted}', $backup);
        self::assertStringContainsString("\$database . '-wal'", $backup);
    }

    public function test_recovery_metadata_and_live_files_are_flushed_before_replacement(): void
    {
        foreach (['app/Updates/UpdateStateStore.php', 'app/Updates/UpdateSettingsStore.php', 'app/Updates/ReleasePackage.php', 'app/Updates/DatabaseBackupService.php'] as $path) {
            $contents = $this->read($path);
            self::assertStringContainsString("function_exists('fsync')", $contents, $path);
        }
    }

    public function test_database_backup_cannot_be_disabled_for_web_updates(): void
    {
        $manager = $this->read('app/Updates/UpdateManager.php');
        $config  = $this->read('config/longos.php');
        $env     = $this->read('.env.example');

        self::assertStringContainsString('$databasePlan = $this->databases->backup($databasePath);', $manager);
        self::assertStringNotContainsString("config('longos.updater.backup_required'", $manager);
        self::assertStringNotContainsString('backup_required', $config);
        self::assertStringNotContainsString('LONGOS_UPDATE_BACKUP_REQUIRED', $env);
    }

    public function test_coolify_keeps_application_updates_out_of_the_immutable_runtime(): void
    {
        $installer = $this->read('install.sh');
        $manager   = $this->read('app/Updates/UpdateManager.php');
        $launcher  = $this->read('app/Updates/UpdateLauncher.php');

        self::assertStringContainsString('LONGOS no longer uses a host installer', $installer);
        self::assertStringContainsString('docs/COOLIFY.md', $installer);
        self::assertStringNotContainsString('docker compose up', $installer);
        self::assertStringNotContainsString('! is_writable(base_path())', $manager);
        self::assertStringContainsString('is_writable($parent)', $manager);
        self::assertStringContainsString("(\$manifest['delete'] ?? [])", $manager);
        self::assertStringContainsString('$php = $this->launcher->phpCli();', $manager);
        self::assertStringNotContainsString('array_merge([PHP_BINARY', $manager);
        self::assertStringContainsString('public function phpCli(): ?string', $launcher);
    }

    public function test_release_workflow_tests_before_stripping_development_dependencies(): void
    {
        $workflow = $this->read('.github/workflows/longos-release.yml');
        $testAt   = strpos($workflow, 'run: npm test');
        $stripAt  = strpos($workflow, 'composer install --no-dev');
        $buildAt  = strpos($workflow, 'php scripts/build-update-package.php');

        self::assertIsInt($testAt);
        self::assertIsInt($stripAt);
        self::assertIsInt($buildAt);
        self::assertStringContainsString('composer validate --strict --no-check-publish', $workflow);
        self::assertStringNotContainsString('longos-deployer-baota', $workflow);
        self::assertStringContainsString('Download previous verified LONGOS release', $workflow);
        self::assertStringContainsString('BASE_DIGEST=', $workflow);
        self::assertStringContainsString('Refuse to overwrite an existing release', $workflow);
        self::assertStringNotContainsString('--clobber', $workflow);
        self::assertStringContainsString('scripts/build-updater-bootstrap.php', $workflow);
        self::assertLessThan($stripAt, $testAt);
        self::assertLessThan($buildAt, $stripAt);
    }

    public function test_first_updater_bootstrap_preserves_runtime_data_and_real_version(): void
    {
        $builder = $this->read('scripts/build-updater-bootstrap.php');

        self::assertStringContainsString("'purpose'    => 'web-updater-bootstrap'", $builder);
        self::assertStringContainsString("'composer.json'", $builder);
        self::assertStringNotContainsString('deploy/baota', $builder);
        self::assertStringNotContainsString("'beike/Config/beike.php'", $builder);
        $filesEnd = strpos($builder, '];', strpos($builder, '$applicationFiles = ['));
        self::assertIsInt($filesEnd);
        $applicationFiles = substr($builder, 0, (int) $filesEnd);
        self::assertStringNotContainsString("'.env'", $applicationFiles);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents);

        return $contents;
    }
}
