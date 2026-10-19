<?php

namespace Beike\Installer\Controllers;

use App\Saas\Support\DatabaseReadiness;
use App\Saas\Support\SensitiveValueRedactor;
use Beike\Admin\Repositories\AdminUserRepo;
use Beike\Installer\Helpers\DatabaseManager;
use Beike\Installer\Helpers\EnvironmentManager;
use Beike\Installer\Helpers\InstallationStateManager;
use Beike\Installer\Helpers\InstallerSetupHandoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class DatabaseController extends BaseController
{
    /**
     * @var DatabaseManager
     */
    private DatabaseManager $databaseManager;

    private EnvironmentManager $environmentManager;

    /**
     * @param DatabaseManager $databaseManager
     */
    public function __construct(
        DatabaseManager $databaseManager,
        EnvironmentManager $environmentManager,
        private readonly InstallationStateManager $installationState,
        private readonly InstallerSetupHandoff $setupHandoff,
    ) {
        $this->databaseManager    = $databaseManager;
        $this->environmentManager = $environmentManager;
    }

    /**
     * Render a lightweight progress screen before every potentially long
     * installation phase. Each phase runs in its own request so reverse
     * proxies receive a response well before their read timeout.
     */
    public function progress()
    {
        $this->checkInstalled();
        $databaseSignature = $this->databaseSignature();
        $setup             = request()->session()->get('installer.admin');
        if (! is_array($setup) || empty($setup['admin_email']) || empty($setup['admin_password'])) {
            $setup = $this->setupHandoff->recover(request(), $databaseSignature);
            if (is_array($setup)) {
                request()->session()->put('installer.admin', $setup);
            }
        }
        if (! is_array($setup) || empty($setup['admin_email']) || empty($setup['admin_password'])) {
            return redirect()->route('installer.environment')->withErrors([
                'error' => trans('installer::installer_messages.environment.installation_session_expired'),
            ]);
        }

        $state = $this->installationState->matches($databaseSignature)
            ? $this->installationState->read()
            : [];
        $stage = (string) ($state['stage'] ?? 'database_validated');
        if ($stage === 'completed') {
            $completion = $this->installationState->completion($databaseSignature);
            if ($completion !== []) {
                request()->session()->put('installer.completed', $completion);
            }

            return redirect()->route('installer.final');
        }

        $stages = [
            'database_validated' => ['progress' => 8, 'next' => 'schema'],
            'schema_migrated'    => ['progress' => 40, 'next' => 'seed'],
            'database_migrated'  => ['progress' => 68, 'next' => 'administrator'],
            'admin_created'      => ['progress' => 82, 'next' => 'saas'],
            'saas_registered'    => ['progress' => 94, 'next' => 'finalize'],
        ];
        $current = $stages[$stage] ?? $stages['database_validated'];
        $steps   = 4;

        return view('installer::installing', [
            'steps'        => $steps,
            'stage'        => $stage,
            'progress'     => $current['progress'],
            'stageMessage' => trans('installer::installer_messages.installing.' . $current['next']),
        ]);
    }

    /**
     * Migrate and seed the database.
     *
     * @return RedirectResponse
     */
    public function index(): RedirectResponse
    {
        try {
            $this->prepareInstallerRuntime();

            return $this->runInstallation();
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return $this->installationFailed($exception, 'unhandled');
        }
    }

    /**
     * Execute the resumable installation after the PHP worker has been made
     * safe for the memory-heavy migrate/seed/provision sequence.
     */
    private function runInstallation(): RedirectResponse
    {
        $this->checkInstalled();
        $databaseSignature = $this->databaseSignature();
        $setup             = request()->session()->get('installer.admin');
        if (! is_array($setup) || empty($setup['admin_email']) || empty($setup['admin_password'])) {
            $setup = $this->setupHandoff->recover(request(), $databaseSignature);
            if (is_array($setup)) {
                request()->session()->put('installer.admin', $setup);
            }
        }
        if (! is_array($setup) || empty($setup['admin_email']) || empty($setup['admin_password'])) {
            return redirect()->route('installer.environment')->withErrors([
                'error' => trans('installer::installer_messages.environment.installation_session_expired'),
            ]);
        }
        if (request()->session()->get('installer.schema_migrated') !== $databaseSignature
            && request()->session()->get('installer.migrated')     !== $databaseSignature) {
            request()->session()->forget([
                'installer.schema_migrated',
                'installer.migrated',
                'installer.admin_created',
                'installer.saas_registered',
            ]);
        }

        // Browser sessions can be lost between long-running migration and
        // provisioning requests. The signed-on-disk recovery state is the
        // authoritative progress marker, so a resumed installer never seeds,
        // creates the root administrator, or registers the control site twice.
        if ($this->installationState->hasReached($databaseSignature, 'schema_migrated')) {
            request()->session()->put('installer.schema_migrated', $databaseSignature);
        }
        if ($this->installationState->hasReached($databaseSignature, 'database_migrated')) {
            request()->session()->put('installer.migrated', $databaseSignature);
        }
        if ($this->installationState->hasReached($databaseSignature, 'admin_created')) {
            request()->session()->put('installer.admin_created', true);
        }
        if ($this->installationState->hasReached($databaseSignature, 'saas_registered')) {
            request()->session()->put('installer.saas_registered', true);
        }

        $response = ['status' => 'success', 'message' => ''];
        if (request()->session()->get('installer.schema_migrated') !== $databaseSignature) {
            try {
                $response = $this->databaseManager->migrate();
                $status   = $response['status']  ?? '';
                $message  = $response['message'] ?? '';
                if ($status !== 'success') {
                    throw new RuntimeException(
                        $message ?: trans('installer::installer_messages.environment.db_connection_failed'),
                    );
                }
                request()->session()->put('installer.schema_migrated', $databaseSignature);
                if (! $this->installationState->write($databaseSignature, 'schema_migrated')) {
                    throw new RuntimeException(trans('installer::installer_messages.environment.install_state_write_failed'));
                }

                return redirect()->route('installer.database');
            } catch (Throwable $e) {
                return $this->installationFailed($e, 'database_migration');
            }
        }

        if (request()->session()->get('installer.migrated') !== $databaseSignature) {
            try {
                $response = $this->databaseManager->seed();
                $status   = $response['status']  ?? '';
                $message  = $response['message'] ?? '';
                if ($status !== 'success') {
                    throw new RuntimeException(
                        $message ?: trans('installer::installer_messages.environment.db_connection_failed'),
                    );
                }
                if (config('database.default') === 'pgsql') {
                    $exitCode = Artisan::call('postgreSQL:sequence');
                    if ($exitCode !== 0) {
                        throw new RuntimeException(trim(Artisan::output()) ?: 'PostgreSQL sequence synchronization failed.');
                    }
                }
                request()->session()->put('installer.migrated', $databaseSignature);
                if (! $this->installationState->write($databaseSignature, 'database_migrated')) {
                    throw new RuntimeException(trans('installer::installer_messages.environment.install_state_write_failed'));
                }

                return redirect()->route('installer.database');
            } catch (Throwable $e) {
                return $this->installationFailed($e, 'database_seeding');
            }
        }

        $email = $setup['admin_email'];
        $data  = [
            'name'     => substr($email, 0, strpos($email, '@')),
            'email'    => $email,
            'password' => $setup['admin_password'],
            'locale'   => $_COOKIE['locale'] ?? 'zh_cn',
            'active'   => true,
        ];

        if (! (bool) request()->session()->get('installer.admin_created', false)) {
            try {
                // A worker can finish the insert and be interrupted before
                // the recovery state is persisted. Reuse that administrator
                // on retry instead of failing on the unique email index.
                $existingEmail = Schema::hasTable('admin_users')
                    ? DB::table('admin_users')->orderBy('id')->value('email')
                    : null;
                if (is_string($existingEmail) && filter_var($existingEmail, FILTER_VALIDATE_EMAIL)) {
                    $email = $existingEmail;
                } else {
                    AdminUserRepo::createAdminUser($data);
                }
                DatabaseReadiness::assertAdministrator($email, (string) config('database.default'));
                request()->session()->put('installer.admin_created', true);
                if (! $this->installationState->write($databaseSignature, 'admin_created')) {
                    throw new RuntimeException(trans('installer::installer_messages.environment.install_state_write_failed'));
                }

                return redirect()->route('installer.database');
            } catch (Throwable $e) {
                return $this->installationFailed($e, 'administrator_creation');
            }
        }

        // On a resumed installation show the account that was actually
        // created, rather than a newly submitted form value that was skipped.
        $persistedEmail = Schema::hasTable('admin_users')
            ? DB::table('admin_users')->orderBy('id')->value('email')
            : null;
        if (is_string($persistedEmail) && filter_var($persistedEmail, FILTER_VALIDATE_EMAIL)) {
            $email = $persistedEmail;
        }

        try {
            if (! (bool) request()->session()->get('installer.saas_registered', false)) {
                $exitCode = Artisan::call('saas:install', [
                    '--domain' => (string) config('app.url'),
                    '--code'   => 'main',
                    '--name'   => 'LONGOS Control Site',
                ]);
                if ($exitCode !== 0) {
                    throw new RuntimeException(trim(Artisan::output()) ?: 'SaaS control-site registration failed.');
                }
                request()->session()->put('installer.saas_registered', true);
                if (! $this->installationState->write($databaseSignature, 'saas_registered')) {
                    throw new RuntimeException(trans('installer::installer_messages.environment.install_state_write_failed'));
                }

                return redirect()->route('installer.database');
            }
            if (! $this->environmentManager->setValues([
                'SAAS_ENABLED'        => 'true',
                'CACHE_DRIVER'        => 'database',
                'CACHE_DB_CONNECTION' => 'saas',
            ])) {
                throw new RuntimeException(trans('installer::installer_messages.environment.env_write_failed'));
            }
            Artisan::call('config:clear');
        } catch (Throwable $e) {
            return $this->installationFailed($e, 'saas_registration');
        }

        $completion = [
            'admin_email' => $email,
            'saas'        => true,
            'base_domain' => (string) config('saas.base_domain'),
            'dns_targets' => (array) config('saas.dns_targets'),
        ];
        if (! $this->installationState->write($databaseSignature, 'completed', $completion)) {
            return redirect()->route('installer.environment')->withErrors([
                'error' => trans('installer::installer_messages.environment.install_state_write_failed'),
            ]);
        }

        request()->session()->forget([
            'installer.admin',
            'installer.schema_migrated',
            'installer.migrated',
            'installer.admin_created',
            'installer.saas_registered',
            'installer.database_signature',
        ]);
        request()->session()->put('installer.completed', $completion);
        $this->setupHandoff->forget();

        return redirect()->route('installer.final')->with(['message' => $response]);
    }

    private function databaseSignature(): string
    {
        return $this->installationState->currentDatabaseSignature();
    }

    /**
     * Some managed PHP runtimes use a 128 MB limit. Laravel must boot
     * the console kernel several times during a web installation, which can
     * exceed that limit even though the storefront itself runs comfortably.
     */
    private function prepareInstallerRuntime(): void
    {
        ignore_user_abort(true);
        @set_time_limit((int) config('installer.runtime.max_execution_seconds', 0));

        $target       = (string) config('installer.runtime.memory_limit', '512M');
        $minimumBytes = (int) config('installer.runtime.minimum_memory_bytes', 268435456);
        $currentBytes = $this->memoryBytes((string) ini_get('memory_limit'));
        $targetBytes  = $this->memoryBytes($target);

        if ($currentBytes >= 0 && $targetBytes > $currentBytes) {
            @ini_set('memory_limit', $target);
            $currentBytes = $this->memoryBytes((string) ini_get('memory_limit'));
        }

        if ($currentBytes >= 0 && $currentBytes < $minimumBytes) {
            throw new RuntimeException(trans('installer::installer_messages.environment.insufficient_memory_limit', [
                'current'  => (string) ini_get('memory_limit'),
                'required' => $this->formatBytes($minimumBytes),
            ]));
        }

        // Do not reuse the connection used by the validation request. The
        // redirected request must use the database values just loaded from
        // the new .env file.
        DB::purge();
        DB::purge('saas');
    }

    private function installationFailed(Throwable $exception, string $stage): RedirectResponse
    {
        $message = trim(SensitiveValueRedactor::redact($exception->getMessage()));
        if ($message === '') {
            $message = trans('installer::installer_messages.environment.installation_failed');
        }

        try {
            Log::error('LONGOS web installation failed.', [
                'stage'        => $stage,
                'exception'    => $exception::class,
                'message'      => $message,
                'memory_limit' => (string) ini_get('memory_limit'),
            ]);
        } catch (Throwable) {
            // A logging permission problem must not hide the installer error.
        }

        return redirect()->route('installer.database')->withErrors(['error' => $message]);
    }

    private function memoryBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        if ($value === '' || ! preg_match('/^(\d+)([KMG])?$/i', $value, $matches)) {
            return 0;
        }

        $bytes = (int) $matches[1];
        $unit  = strtoupper((string) ($matches[2] ?? ''));

        return match ($unit) {
            'G'     => $bytes * 1024 * 1024 * 1024,
            'M'     => $bytes * 1024 * 1024,
            'K'     => $bytes * 1024,
            default => $bytes,
        };
    }

    private function formatBytes(int $bytes): string
    {
        return (string) max(1, (int) ceil($bytes / 1024 / 1024)) . 'M';
    }
}
