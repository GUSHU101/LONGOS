<?php

namespace Beike\Console\Commands;

use App\Saas\Support\DatabaseReadiness;
use App\Saas\Support\DomainName;
use App\Saas\Support\SensitiveValueRedactor;
use Beike\Installer\Helpers\EnvironmentManager;
use Beike\Installer\Helpers\InstallationStateManager;
use Beike\Installer\Helpers\InstalledFileManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Install LONGOS and register its control storefront.
 */
class BeikeShopInstall extends Command
{
    protected $signature = 'longos:install
                            {--domain=localhost : Website domain or complete URL}
                            {--base-domain= : Tenant root domain; inferred from domain when omitted}
                            {--database-mode= : prefix (default) or database}
                            {--db-host=localhost : Database host}
                            {--db-port=3306 : Database port}
                            {--db-name=longos : Database name}
                            {--db-user=longos : Database user}
                            {--db-password= : Database password}
                            {--admin-email= : Admin email}
                            {--admin-password= : Admin password}
                            {--secrets-stdin : Read the database and administrator passwords as two NUL-delimited values from standard input}
                            {--admin-name=admin : Admin name}
                            {--without-saas : Install a legacy single storefront instead of the default SaaS mode}
                            {--fresh : Explicitly drop all existing tables before installation; requires --force}
                            {--force : Confirm non-interactive/destructive installation options}';

    protected $description = 'Install LONGOS Commerce SaaS with a safe, resumable database workflow';

    /** @var string[] */
    protected $aliases = ['beikeshop:install'];

    private string $appUrl = '';

    private string $host = '';

    private string $baseDomain = '';

    private string $databaseMode = 'prefix';

    public function handle(
        EnvironmentManager $environment,
        InstallationStateManager $installationState,
        InstalledFileManager $installedFile,
    ): int {
        $this->info('╔══════════════════════════════════════════╗');
        $this->info('║       LONGOS Installation Command        ║');
        $this->info('╚══════════════════════════════════════════╝');
        $this->newLine();

        try {
            $this->loadSecretsFromStdin();
            $this->validateOptions();

            $this->task('Configuring environment', function () use ($environment): void {
                $this->configureEnvironment($environment);
            });
            $this->task('Testing database connection', fn () => $this->testDatabaseConnection());

            $databaseSignature = $installationState->currentDatabaseSignature();
            $this->guardDatabaseTarget($installationState, $databaseSignature);
            $state = $installationState->matches($databaseSignature) ? $installationState->read() : [];
            if ($state === []) {
                if (! $installationState->write($databaseSignature, 'database_validated')) {
                    throw new RuntimeException('Unable to persist the installation recovery state.');
                }
                $state = $installationState->read();
            }

            if (! $this->stageReached($state, 'database_migrated')) {
                $this->task('Running database migrations', function (): void {
                    $command = $this->option('fresh') ? 'migrate:fresh' : 'migrate';
                    $this->callArtisan($command, ['--force' => true, '--no-interaction' => true]);
                    DatabaseReadiness::assertMigrated((string) config('database.default'));
                });
                $this->task('Seeding database', function (): void {
                    // MySQL TRUNCATE implicitly commits; the seeders are
                    // intentionally retry-safe, so do not wrap db:seed in an
                    // outer transaction that MySQL will terminate underneath.
                    $this->callArtisan('db:seed', ['--force' => true, '--no-interaction' => true]);
                    DatabaseReadiness::assertSeeded((string) config('database.default'));
                });
                if (! $installationState->write($databaseSignature, 'database_migrated')) {
                    throw new RuntimeException('Unable to persist the database migration state.');
                }
            }

            if (! $this->stageReached($installationState->read(), 'admin_created')) {
                $this->task('Creating administrator', fn () => $this->createAdminUser());
                if (! $installationState->write($databaseSignature, 'admin_created')) {
                    throw new RuntimeException('Unable to persist the administrator creation state.');
                }
            }

            if (! $this->option('without-saas')) {
                if (! $this->stageReached($installationState->read(), 'saas_registered')) {
                    $this->task('Registering SaaS control storefront', function (): void {
                        $this->callArtisan('saas:install', [
                            '--domain' => $this->appUrl,
                            '--code'   => 'main',
                            '--name'   => 'LONGOS Control Site',
                        ]);
                    });
                    if (! $installationState->write($databaseSignature, 'saas_registered')) {
                        throw new RuntimeException('Unable to persist the SaaS registration state.');
                    }
                }

                if (! $environment->setValues(['SAAS_ENABLED' => 'true'])) {
                    throw new RuntimeException('Unable to enable SaaS in the environment file.');
                }
                Config::set('saas.enabled', true);
            }

            // cache/cache_locks now exist. Persist the production cache store
            // for the next process while this installer process keeps using
            // the bootstrap-safe file store until it exits.
            if (! $environment->setValues([
                'CACHE_DRIVER'        => 'database',
                'CACHE_DB_CONNECTION' => 'saas',
            ])) {
                throw new RuntimeException('Unable to enable the database cache store.');
            }

            $this->task('Clearing runtime caches', function (): void {
                foreach (['config:clear', 'cache:clear', 'route:clear', 'view:clear'] as $command) {
                    $this->callArtisan($command);
                }
            });

            if (! $installationState->write($databaseSignature, 'completed', [
                'admin_email' => $this->stringOption('admin-email'),
                'saas'        => ! $this->option('without-saas'),
                'base_domain' => $this->baseDomain,
                'dns_targets' => [],
            ])) {
                throw new RuntimeException('Unable to persist the installation completion state.');
            }

            $this->task('Marking installation complete', fn () => $installedFile->update());
            $installationState->clear();

            $this->newLine();
            $this->info('LONGOS installation completed successfully.');
            $this->displayResult();

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $message = SensitiveValueRedactor::redact($exception->getMessage(), [
                $this->stringOption('db-password'),
                $this->stringOption('admin-password'),
            ]);
            $this->newLine();
            $this->components->error($message);
            $this->warn('No installed marker was written. Fix the reported issue and run the same command again to resume safely.');

            return self::FAILURE;
        }
    }

    private function validateOptions(): void
    {
        if ($this->isInstalled()) {
            if (! $this->option('fresh') || ! $this->option('force')) {
                throw new RuntimeException('LONGOS is already installed. Use saas:upgrade for upgrades; a destructive reinstall requires both --fresh and --force.');
            }
        }
        if ($this->option('fresh') && ! $this->option('force')) {
            throw new RuntimeException('--fresh drops all existing tables and therefore requires --force.');
        }

        $domain             = DomainName::parse($this->stringOption('domain'), 'http');
        $this->host         = $domain['host'];
        $this->appUrl       = $domain['scheme'] . '://' . $domain['host'];
        $this->baseDomain   = strtolower(rtrim($this->stringOption('base-domain') ?: (string) get_domain($this->host), '.'));
        $this->databaseMode = $this->stringOption('database-mode')
            ?: (string) config('saas.database_mode', 'prefix');

        if (! in_array($this->databaseMode, ['prefix', 'database'], true)) {
            throw new RuntimeException('--database-mode must be prefix or database.');
        }
        if (! $this->option('without-saas')
            && ($this->baseDomain === ''
                || ! str_contains($this->baseDomain, '.')
                || filter_var($this->baseDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            throw new RuntimeException('A valid tenant root domain is required for SaaS. Pass --base-domain=example.com or use --without-saas for local development.');
        }
        if (filter_var($this->stringOption('admin-email'), FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('--admin-email must be a valid email address.');
        }
        if (mb_strlen($this->stringOption('admin-password')) < 8) {
            throw new RuntimeException('--admin-password must contain at least 8 characters.');
        }
        if ($this->stringOption('db-name') === '' || $this->stringOption('db-user') === '') {
            throw new RuntimeException('Database name and user cannot be empty.');
        }
        $port = filter_var($this->stringOption('db-port'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new RuntimeException('--db-port must be between 1 and 65535.');
        }
    }

    private function loadSecretsFromStdin(): void
    {
        if (! $this->option('secrets-stdin')) {
            return;
        }

        if ($this->stringOption('db-password') !== '' || $this->stringOption('admin-password') !== '') {
            throw new RuntimeException('--secrets-stdin cannot be combined with password command-line options.');
        }

        $stream  = fopen('php://stdin', 'rb');
        $payload = $stream === false ? false : stream_get_contents($stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (! is_string($payload)) {
            throw new RuntimeException('Unable to read installation secrets from standard input.');
        }

        $parts = explode("\0", $payload);
        if (count($parts) !== 3 || $parts[2] !== '' || $parts[0] === '' || $parts[1] === '') {
            throw new RuntimeException('--secrets-stdin requires exactly two non-empty NUL-delimited values.');
        }

        $this->input->setOption('db-password', $parts[0]);
        $this->input->setOption('admin-password', $parts[1]);

        // Drop the aggregate payload as soon as the parsed values have been
        // transferred to Symfony's in-memory input. Nothing is written to a
        // temporary file or exposed through the process command line.
        $payload = '';
        $parts   = [];
    }

    private function isInstalled(): bool
    {
        return is_file(storage_path('installed'));
    }

    private function task(string $description, callable $callback): void
    {
        $this->line("→ {$description}...");
        $callback();
        $this->info("✓ {$description}");
    }

    private function configureEnvironment(EnvironmentManager $environment): void
    {
        $secure = str_starts_with($this->appUrl, 'https://');
        $values = [
            'APP_URL'                    => $this->appUrl,
            'FRONTEND_URL'               => $this->appUrl,
            'APP_FORCE_HTTPS'            => $secure ? 'true' : 'false',
            'SESSION_SECURE_COOKIE'      => $secure ? 'true' : 'false',
            'DB_CONNECTION'              => 'mysql',
            'DB_HOST'                    => $this->stringOption('db-host'),
            'DB_PORT'                    => $this->stringOption('db-port'),
            'DB_DATABASE'                => $this->stringOption('db-name'),
            'DB_USERNAME'                => $this->stringOption('db-user'),
            'DB_PASSWORD'                => $this->stringOption('db-password'),
            'SAAS_ENABLED'               => 'false',
            'SAAS_DATABASE_MODE'         => $this->databaseMode,
            'SAAS_SHARED_DATABASE'       => $this->stringOption('db-name'),
            'SAAS_TENANT_DB_CONNECTION'  => 'mysql',
            'SAAS_TENANT_DB_HOST'        => $this->stringOption('db-host'),
            'SAAS_TENANT_DB_PORT'        => $this->stringOption('db-port'),
            'SAAS_TENANT_DB_USERNAME'    => $this->stringOption('db-user'),
            'SAAS_TENANT_DB_PASSWORD'    => $this->stringOption('db-password'),
            'SAAS_BASE_DOMAIN'           => $this->option('without-saas') ? '' : $this->baseDomain,
            'SAAS_CONTROL_DOMAINS'       => $this->host,
            'SAAS_HTTP_MANAGEMENT'       => $this->option('without-saas') ? 'false' : 'true',
            'SAAS_DEFAULT_SCHEME'        => $secure ? 'https' : 'http',
            'TRUST_HOSTS_ENABLED'        => 'true',
            // Use the filesystem while migrations create cache/cache_locks.
            // The completed installation switches to the shared DB store.
            'CACHE_DRIVER'               => 'file',
            'CACHE_DB_CONNECTION'        => 'saas',
            'QUEUE_DB_CONNECTION'        => 'saas',
            'QUEUE_FAILED_DB_CONNECTION' => 'saas',
        ];
        if (! $environment->setValues($values)) {
            throw new RuntimeException('Unable to write the environment file.');
        }

        $mysql = (array) config('database.connections.mysql', []);
        foreach ([
            'host'     => 'db-host',
            'port'     => 'db-port',
            'database' => 'db-name',
            'username' => 'db-user',
            'password' => 'db-password',
        ] as $key => $option) {
            $mysql[$key] = $this->stringOption($option);
        }
        $mysql['url']            = null;
        $tenant                  = $mysql;
        $tenant['database']      = null;
        $tenantAdmin             = (array) config('database.connections.tenant_admin', $mysql);
        $tenantAdmin['host']     = $mysql['host'];
        $tenantAdmin['port']     = $mysql['port'];
        $tenantAdmin['database'] = $tenantAdmin['database'] ?: $mysql['database'];
        $tenantAdmin['url']      = null;

        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql', $mysql);
        Config::set('database.connections.saas', $mysql);
        Config::set('database.connections.tenant', $tenant);
        Config::set('database.connections.tenant_admin', $tenantAdmin);
        Config::set('saas.enabled', false);
        Config::set('saas.database_mode', $this->databaseMode);
        Config::set('saas.shared_database', $this->stringOption('db-name'));
        Config::set('saas.tenant_connection', 'mysql');
        Config::set('saas.base_domain', $this->baseDomain);
        Config::set('saas.control_domains', [$this->host]);
        Config::set('saas.http_management', ! $this->option('without-saas'));
        Config::set('saas.default_scheme', $secure ? 'https' : 'http');
        Config::set('app.url', $this->appUrl);
        Config::set('beike.force_url_https', $secure);
        Config::set('session.secure', $secure);
        Config::set('cache.default', 'file');

        foreach (['mysql', 'saas', 'tenant', 'tenant_admin'] as $connection) {
            DB::purge($connection);
        }

        if (trim((string) config('app.key')) === '') {
            $this->callArtisan('key:generate', ['--force' => true]);
        }
    }

    private function testDatabaseConnection(): void
    {
        DB::connection()->getPdo();
    }

    private function guardDatabaseTarget(InstallationStateManager $state, string $signature): void
    {
        $tables = DB::connection()->getSchemaBuilder()->getTableListing();
        if ($this->option('fresh')) {
            $state->clear();
            if (is_file(storage_path('installed'))) {
                @unlink(storage_path('installed'));
            }

            return;
        }

        if ($tables !== [] && ! $state->matches($signature)) {
            throw new RuntimeException('The target database is not empty and does not belong to this resumable installation. Use an empty database; destructive reinstall requires --fresh --force.');
        }
    }

    private function createAdminUser(): void
    {
        if (! Schema::hasTable('admin_users')) {
            throw new RuntimeException('The admin_users table is missing after migration.');
        }

        DB::table('admin_users')->where('email', $this->stringOption('admin-email'))->delete();
        DB::table('admin_users')->insert([
            'name'       => $this->stringOption('admin-name'),
            'email'      => $this->stringOption('admin-email'),
            'password'   => Hash::make($this->stringOption('admin-password')),
            'locale'     => 'zh_cn',
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DatabaseReadiness::assertAdministrator(
            $this->stringOption('admin-email'),
            (string) config('database.default'),
        );
    }

    /** @param array<string, mixed> $parameters */
    private function callArtisan(string $command, array $parameters = []): void
    {
        $exitCode = Artisan::call($command, $parameters);
        if ($exitCode !== self::SUCCESS) {
            $output = trim(Artisan::output());

            throw new RuntimeException($output !== '' ? $output : "Artisan command {$command} failed with exit code {$exitCode}.");
        }
    }

    /** @param array<string, mixed> $state */
    private function stageReached(array $state, string $expected): bool
    {
        $stages = ['database_validated', 'database_migrated', 'admin_created', 'saas_registered', 'completed'];
        $actual = array_search($state['stage'] ?? '', $stages, true);
        $target = array_search($expected, $stages, true);

        return is_int($actual) && is_int($target) && $actual >= $target;
    }

    private function displayResult(): void
    {
        $adminPath = trim((string) admin_name(), '/');
        $rows      = [
            ['Website URL', $this->appUrl],
            ['Admin URL', $this->appUrl . '/' . $adminPath],
            ['Admin Email', $this->stringOption('admin-email')],
            ['Database Name', $this->stringOption('db-name')],
            ['Database User', $this->stringOption('db-user')],
            ['Deployment Mode', $this->option('without-saas') ? 'Single storefront' : "SaaS ({$this->databaseMode})"],
        ];
        if (! $this->option('without-saas')) {
            $rows[] = ['Tenant Domain', "*.{$this->baseDomain}"];
        }

        $this->table(['Item', 'Value'], $rows);
        $this->warn('Security: the administrator password is intentionally not printed. Store the value supplied to --admin-password securely.');
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_array($value) ? implode('', $value) : trim((string) $value);
    }
}
