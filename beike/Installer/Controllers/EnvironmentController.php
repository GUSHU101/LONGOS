<?php

namespace Beike\Installer\Controllers;

use Beike\Installer\Helpers\EnvironmentManager;
use Beike\Installer\Helpers\InstallationStateManager;
use Beike\Installer\Helpers\InstallerSetupHandoff;
use Beike\Installer\Helpers\RequirementsChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EnvironmentController extends BaseController
{
    /**
     * @var EnvironmentManager
     */
    protected EnvironmentManager $EnvironmentManager;

    /**
     * @var RequirementsChecker
     */
    protected RequirementsChecker $requirements;

    /**
     * @param EnvironmentManager  $environmentManager
     * @param RequirementsChecker $checker
     */
    public function __construct(
        EnvironmentManager $environmentManager,
        RequirementsChecker $checker,
        private readonly InstallationStateManager $installationState,
        private readonly InstallerSetupHandoff $setupHandoff,
    ) {
        $this->EnvironmentManager = $environmentManager;
        $this->requirements       = $checker;
    }

    /**
     * Display the Environment page.
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $this->checkInstalled();
        $steps             = 4;
        $installerHost     = strtolower(rtrim($request->getHost(), '.'));
        $defaultBaseDomain = $this->defaultBaseDomain($installerHost);
        $defaultDnsTarget  = filter_var($installerHost, FILTER_VALIDATE_IP) ? $installerHost : '';

        return view('installer::environment-wizard', compact('steps', 'defaultBaseDomain', 'defaultDnsTarget'));
    }

    /**
     * Processes the newly saved environment configuration (Form Wizard).
     *
     * @param Request    $request
     * @param Redirector $redirect
     * @return RedirectResponse
     */
    public function saveWizard(Request $request, Redirector $redirect): RedirectResponse
    {
        $this->checkInstalled();
        $rules    = config('installer.environment.form.rules');
        $messages = [
            'environment_custom.required_if' => trans('installer::installer_messages.environment.name_required'),
            'admin_password.confirmed'       => trans('installer::installer_messages.environment.admin_password_confirmation_mismatch'),
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return $redirect->route('installer.environment')->withInput()->withErrors($validator->errors());
        }

        $databaseResult = $this->checkDatabaseConnection($request);
        if ($databaseResult !== true) {
            return $redirect->route('installer.environment')->withInput()->withErrors($databaseResult);
        }

        if (! $this->EnvironmentManager->saveFileWizard($request)) {
            return $redirect->route('installer.environment')->withInput()->withErrors([
                'database_other' => trans('installer::installer_messages.environment.env_write_failed'),
            ]);
        }

        $databaseSignature = $this->databaseSignature($request);
        $request->session()->put('installer.database_signature', $databaseSignature);
        if (! $this->installationState->write($databaseSignature, 'database_validated')) {
            return $redirect->route('installer.environment')->withInput()->withErrors([
                'database_other' => trans('installer::installer_messages.environment.install_state_write_failed'),
            ]);
        }

        // The cached configuration may contain the placeholder database
        // values shipped with the release. Clear it before the redirected
        // migration request reads the just-written .env file.
        try {
            \Artisan::call('config:clear');
        } catch (\Throwable $exception) {
            return $redirect->route('installer.environment')->withInput()->withErrors([
                'database_other' => trans('installer::installer_messages.environment.env_write_failed'),
            ]);
        }

        $setup = $request->only('admin_email', 'admin_password');
        $request->session()->put('installer.admin', $setup);
        $this->setupHandoff->queue($request, $setup, $databaseSignature);

        return $redirect->route('installer.database');
    }

    /**
     * 数据库信息检测
     *
     * @param Request $request
     * @return JsonResponse|array
     */
    public function validateDatabase(Request $request): JsonResponse|array
    {
        $this->checkInstalled();
        $rules    = config('installer.environment.form.rules');
        $messages = [
            'environment_custom.required_if' => trans('installer::installer_messages.environment.name_required'),
        ];

        unset($rules['admin_email'], $rules['admin_password'], $rules['admin_password_confirmation']);

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return json_fail('', $validator->errors());
        }

        $dbValidateResult = $this->checkDatabaseConnection($request);
        if ($dbValidateResult !== true) {
            return json_fail('', $dbValidateResult);
        }

        return json_success('');
    }

    /**
     * TODO: We can remove this code if PR will be merged: https://github.com/RachidLaasri/LaravelInstaller/pull/162
     * Validate database connection with user credentials (Form Wizard).
     *
     * @param Request $request
     * @return array|bool
     */
    private function checkDatabaseConnection(Request $request): bool|array
    {
        $this->checkInstalled();
        $connection = $request->input('database_connection');

        $settings = config("database.connections.$connection");
        if (! is_array($settings)) {
            return [
                'database_connection' => trans('installer::installer_messages.environment.db_connection_failed'),
            ];
        }

        $runtimeSettings = array_merge($settings, [
            'driver'   => $connection,
            'host'     => $request->input('database_hostname'),
            'port'     => $request->input('database_port'),
            'database' => $request->input('database_name'),
            'username' => $request->input('database_username'),
            'password' => $request->input('database_password'),
            'options'  => [
                \PDO::ATTR_TIMEOUT => 2,
            ],
        ]);
        config([
            'database.default'                   => $connection,
            "database.connections.{$connection}" => $runtimeSettings,
        ]);

        DB::purge();

        $result = [];

        try {
            $database      = DB::connection();
            $pdo           = $database->getPdo();
            $serverVersion = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $minimum       = $connection === 'pgsql' ? '14.0' : '5.7';
            if (version_compare($serverVersion, $minimum, '<')) {
                $result['database_version'] = trans('installer::installer_messages.environment.db_connection_failed_invalid_version');
                $this->checkDbConnection($request, $result);

                return $result;
            }

            // The installer is intended for a dedicated, empty database. It
            // must never target a database that already contains application
            // or customer tables.
            $databaseSignature = $this->databaseSignature($request);
            $resumeSignature   = (string) (
                $request->session()->get('installer.migrated')
                ?: $request->session()->get('installer.database_signature', '')
            );
            $canResume = ($resumeSignature !== '' && hash_equals($resumeSignature, $databaseSignature))
                || $this->installationState->matches($databaseSignature);
            if ($database->getSchemaBuilder()->getTableListing() !== []
                && ! $canResume) {
                return [
                    'database_other' => trans('installer::installer_messages.environment.table_already_exists'),
                ];
            }

            if ($connection === 'mysql') {
                $account   = (string) ($database->selectOne('SELECT CURRENT_USER() AS account')?->account ?? '');
                $grantHost = str_contains($account, '@') ? substr($account, strrpos($account, '@') + 1) : '%';
                $request->merge(['saas_tenant_db_grant_host' => trim($grantHost, "'\"") ?: '%']);
            } else {
                $request->merge(['saas_tenant_db_grant_host' => '%']);
            }

            if ($request->input('saas_database_mode', 'prefix') === 'database') {
                return $this->checkProvisioningConnection($request, $runtimeSettings);
            }

            // Prefix mode needs no server-wide CREATE DATABASE or GRANT
            // permission. An ordinary per-database account is enough.
            return true;
        } catch (\Throwable $e) {

            switch ($e->getCode()) {
                case 7:
                    $result['database_username'] = trans('installer::installer_messages.environment.db_connection_failed_user_password');
                    $result['database_password'] = trans('installer::installer_messages.environment.db_connection_failed_user_password');
                    $result['database_name']     = trans('installer::installer_messages.environment.db_connection_failed_database_name');

                    break;
                case 1115:
                    $result['database_version'] = trans('installer::installer_messages.environment.db_connection_failed_invalid_version');

                    break;
                case 2002:
                    $result['database_hostname'] = trans('installer::installer_messages.environment.db_connection_failed_host_port');
                    $result['database_port']     = trans('installer::installer_messages.environment.db_connection_failed_host_port');

                    break;
                case 1045:
                    $result['database_username'] = trans('installer::installer_messages.environment.db_connection_failed_user_password');
                    $result['database_password'] = trans('installer::installer_messages.environment.db_connection_failed_user_password');

                    break;
                case 1049:
                    $result['database_name'] = trans('installer::installer_messages.environment.db_connection_failed_database_name');

                    break;
                default:
                    $result['database_other'] = trans('installer::installer_messages.environment.db_connection_failed');
            }
        }

        $this->checkDbConnection($request, $result);

        return $result;
    }

    /**
     * Verify the separate account used by the control plane to create tenant
     * databases. This makes the web installer fail early instead of leaving a
     * successfully installed platform that cannot create its first tenant.
     */
    private function checkProvisioningConnection(Request $request, array $runtimeSettings): bool|array
    {
        $connection    = (string) $request->input('database_connection');
        $adminPassword = (string) $request->input('saas_tenant_admin_password');
        if ($adminPassword                                   === ''
            && $request->input('saas_tenant_admin_username') === $request->input('database_username')) {
            $adminPassword = (string) $request->input('database_password');
            $request->merge(['saas_tenant_admin_password' => $adminPassword]);
        }
        $settings   = array_merge($runtimeSettings, [
            'username' => $request->input('saas_tenant_admin_username'),
            'password' => $adminPassword,
        ]);
        config(['database.connections.installer_tenant_admin' => $settings]);
        DB::purge('installer_tenant_admin');

        try {
            $admin = DB::connection('installer_tenant_admin');
            $admin->getPdo();

            if ($connection === 'mysql') {
                $grants            = $admin->select('SHOW GRANTS FOR CURRENT_USER');
                $runtimeUser       = (string) $request->input('database_username');
                $adminUser         = (string) $request->input('saas_tenant_admin_username');
                $canProvision      = false;
                $runtimePrivileges = [
                    'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP',
                    'REFERENCES', 'INDEX', 'ALTER', 'CREATE TEMPORARY TABLES',
                    'LOCK TABLES', 'EXECUTE', 'CREATE VIEW', 'SHOW VIEW',
                    'CREATE ROUTINE', 'ALTER ROUTINE', 'EVENT', 'TRIGGER',
                ];

                foreach ($grants as $row) {
                    $grant = strtoupper(implode(' ', array_values((array) $row)));
                    if (! str_contains($grant, ' ON *.* TO ')) {
                        continue;
                    }
                    preg_match('/GRANT\s+(.+)\s+ON\s+\*\.\*\s+TO\s+/', $grant, $matches);
                    $granted              = array_map('trim', explode(',', (string) ($matches[1] ?? '')));
                    $hasAll               = in_array('ALL PRIVILEGES', $granted, true);
                    $hasCreate            = $hasAll || in_array('CREATE', $granted, true);
                    $hasRuntimePrivileges = $hasAll || array_diff($runtimePrivileges, $granted) === [];
                    $canGrant             = str_contains($grant, 'WITH GRANT OPTION');
                    if (($adminUser === $runtimeUser && $hasCreate && $hasRuntimePrivileges)
                        || ($adminUser !== $runtimeUser && $hasCreate && $canGrant)) {
                        $canProvision = true;

                        break;
                    }
                }
            } else {
                $role = $admin->selectOne(
                    'SELECT r.rolsuper, r.rolcreatedb, current_user = ? AS same_runtime FROM pg_roles r WHERE r.rolname = current_user',
                    [(string) $request->input('database_username')]
                );
                $truthy       = static fn (mixed $value): bool => in_array($value, [true, 1, '1', 't', 'true'], true);
                $canProvision = $truthy($role?->rolsuper ?? false)
                    || ($truthy($role?->rolcreatedb ?? false) && $truthy($role?->same_runtime ?? false));
            }

            if (! $canProvision) {
                return [
                    'saas_tenant_admin_username' => trans('installer::installer_messages.environment.saas_admin_permission_failed'),
                ];
            }

            return true;
        } catch (\Throwable $exception) {
            return [
                'saas_tenant_admin_username' => trans('installer::installer_messages.environment.saas_admin_connection_failed'),
                'saas_tenant_admin_password' => trans('installer::installer_messages.environment.saas_admin_connection_failed'),
            ];
        } finally {
            DB::disconnect('installer_tenant_admin');
        }
    }

    private function defaultBaseDomain(string $host): string
    {
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }

        return strtolower((string) get_domain($host));
    }

    private function databaseSignature(Request $request): string
    {
        return hash('sha256', json_encode([
            (string) $request->input('database_connection'),
            strtolower((string) $request->input('database_hostname')),
            (string) $request->input('database_port'),
            (string) $request->input('database_name'),
            (string) $request->input('database_username'),
        ], JSON_THROW_ON_ERROR));
    }

    private function checkDbConnection(Request $request, &$result): void
    {
        $dbType = $request->input('database_connection');
        if (! $dbType) {
            return;
        }

        $dbType       = $request->input('database_connection');
        $config       = config('installer.requirements.db');
        $requirements = $this->requirements->checkDataBase(
            $config
        );

        $hasError       = data_get($requirements, $dbType);
        $currentVersion = data_get($this->requirements->checkPHPversion(), 'current');

        //        if (($dbType == 'pgsql') && version_compare($currentVersion, '8.3', '>='))
        //        {
        //            $result['database_connection'] = trans('installer::installer_messages.environment.down_phpversion');
        //            return;
        //        }

        if (! $hasError) {
            $result['database_connection'] = trans('installer::installer_messages.environment.php_extension') . '(' . data_get($config, $dbType) . ')';
        }

    }
}
