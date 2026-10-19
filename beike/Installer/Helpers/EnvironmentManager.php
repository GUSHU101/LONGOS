<?php

namespace Beike\Installer\Helpers;

use Exception;
use Illuminate\Http\Request;

class EnvironmentManager
{
    /**
     * @var string
     */
    private $envPath;

    /**
     * @var string
     */
    private $envExamplePath;

    /**
     * Set the .env and .env.example paths.
     */
    public function __construct()
    {
        $this->envPath        = base_path('.env');
        $this->envExamplePath = base_path('.env.example');
    }

    /**
     * Get the content of the .env file.
     *
     * @return string
     */
    public function getEnvContent(): string
    {
        if (! file_exists($this->envPath)) {
            if (file_exists($this->envExamplePath)) {
                @copy($this->envExamplePath, $this->envPath);
            } else {
                @touch($this->envPath);
            }
            @chmod($this->envPath, 0600);
        }

        $contents = @file_get_contents($this->envPath);

        return is_string($contents) ? $contents : '';
    }

    /**
     * Get the the .env file path.
     *
     * @return string
     */
    public function getEnvPath()
    {
        return $this->envPath;
    }

    /**
     * Get the the .env.example file path.
     *
     * @return string
     */
    public function getEnvExamplePath()
    {
        return $this->envExamplePath;
    }

    /**
     * Save the edited content to the .env file.
     *
     * @param Request $input
     * @return string
     */
    public function saveFileClassic(Request $input)
    {
        $message = trans('installer::installer_messages.environment.success');

        try {
            if (! $this->writeAtomically((string) $input->get('envConfig'))) {
                throw new \RuntimeException('Unable to write environment file.');
            }
        } catch (Exception $e) {
            $message = trans('installer::installer_messages.environment.errors');
        }

        return $message;
    }

    /**
     * Save the form content to the .env file.
     *
     * @param Request $request
     * @return string
     */
    public function saveFileWizard(Request $request): bool
    {
        $scheme                  = is_secure() ? 'https' : 'http';
        $appUrl                  = $scheme . '://' . $this->resolveInstallerHost($request);
        $appKey                  = $this->resolveApplicationKey();
        $host                    = strtolower(rtrim($request->getHost(), '.'));
        $server                  = strtolower((string) $request->server('SERVER_SOFTWARE', ''));
        $webServer               = str_contains($server, 'apache') ? 'apache' : 'nginx';
        $databaseMode            = $request->saas_database_mode === 'database' ? 'database' : 'prefix';
        $usesDatabaseProvisioner = $databaseMode                === 'database';

        // Start from the complete release template so upgrades and optional
        // subsystems retain every documented variable. SAAS_ENABLED stays off
        // until DatabaseController has registered the control site safely.
        $template = @file_get_contents($this->envExamplePath);
        if (! is_string($template)) {
            $template = $this->getEnvContent();
        }

        return $this->writeValues([
            'APP_NAME'                          => 'LONGOS',
            'APP_ENV'                           => 'production',
            // Keep the key which encrypted the active installer session.
            'APP_KEY'                           => $appKey,
            'APP_DEBUG'                         => 'false',
            'APP_URL'                           => $appUrl,
            'FRONTEND_URL'                      => $appUrl,
            'APP_FORCE_HTTPS'                   => $scheme === 'https' ? 'true' : 'false',
            'LONGOS_PROXY_TOKEN'                => bin2hex(random_bytes(32)),
            'TRUST_HOSTS_ENABLED'               => 'true',
            'DB_CONNECTION'                     => $request->database_connection,
            'DB_HOST'                           => $request->database_hostname,
            'DB_PORT'                           => $request->database_port,
            'DB_DATABASE'                       => $request->database_name,
            'DB_USERNAME'                       => $request->database_username,
            'DB_PASSWORD'                       => $request->database_password,
            'SAAS_ENABLED'                      => 'false',
            'SAAS_DATABASE_MODE'                => $databaseMode,
            'SAAS_SHARED_DATABASE'              => $request->database_name,
            'SAAS_TENANT_DB_CONNECTION'         => $request->database_connection,
            'SAAS_TENANT_DB_HOST'               => $request->database_hostname,
            'SAAS_TENANT_DB_PORT'               => $request->database_port,
            'SAAS_TENANT_DB_USERNAME'           => $request->database_username,
            'SAAS_TENANT_DB_PASSWORD'           => $request->database_password,
            'SAAS_TENANT_DB_GRANT_HOST'         => $request->saas_tenant_db_grant_host ?: '%',
            'SAAS_TENANT_ADMIN_HOST'            => $request->database_hostname,
            'SAAS_TENANT_ADMIN_PORT'            => $request->database_port,
            'SAAS_TENANT_ADMIN_DATABASE'        => $request->database_name,
            'SAAS_TENANT_ADMIN_USERNAME'        => $usesDatabaseProvisioner ? $request->saas_tenant_admin_username : '',
            'SAAS_TENANT_ADMIN_PASSWORD'        => $usesDatabaseProvisioner ? $request->saas_tenant_admin_password : '',
            'SAAS_BASE_DOMAIN'                  => strtolower(rtrim((string) $request->saas_base_domain, '.')),
            'SAAS_DATABASE_PREFIX'              => $request->saas_database_prefix,
            'SAAS_DEFAULT_SCHEME'               => 'https',
            'SAAS_CONTROL_DOMAINS'              => $host,
            'SAAS_HTTP_MANAGEMENT'              => 'true',
            'SAAS_DNS_TARGETS'                  => $request->saas_dns_target,
            'SAAS_WEB_SERVER'                   => $webServer,
            // The browser installer cannot configure a server-wide proxy.
            // Production Coolify deployments use the container entrypoint
            // instead and set this to caddy through Compose.
            'SAAS_TLS_DRIVER'                   => 'external',
            'SAAS_TLS_EXTERNAL_CONTROL_DOMAIN'  => 'false',
            'LONGOS_DEPLOYMENT_PLATFORM'        => 'docker',
            // The redirected migration request boots before the cache table
            // exists. DatabaseController promotes this after migrations.
            'CACHE_DRIVER'                      => 'file',
            'CACHE_DB_CONNECTION'               => 'saas',
            'SESSION_DRIVER'                    => 'file',
            'SESSION_SECURE_COOKIE'             => $scheme === 'https' ? 'true' : 'false',
            'QUEUE_CONNECTION'                  => 'database',
            'QUEUE_DB_CONNECTION'               => 'saas',
            'QUEUE_FAILED_DB_CONNECTION'        => 'saas',
            'MAIL_MAILER'                       => 'log',
        ], $template);
    }

    /**
     * Update one or more values without deleting unrelated release settings.
     * Used after control-site registration to atomically enable SaaS.
     *
     * @param array<string, mixed> $values
     */
    public function setValues(array $values): bool
    {
        return $this->writeValues($values, $this->getEnvContent());
    }

    /** @param array<string, mixed> $values */
    private function writeValues(array $values, string $contents): bool
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key)) {
                return false;
            }

            $line    = $key . '=' . $this->encodeValue($value);
            $pattern = '/^(?:export\s+)?' . preg_quote((string) $key, '/') . '=.*$/m';
            if (preg_match($pattern, $contents) === 1) {
                $contents = (string) preg_replace($pattern, $line, $contents, 1);
            } else {
                $contents = rtrim($contents) . "\n" . $line . "\n";
            }
        }

        try {
            return $this->writeAtomically(rtrim($contents) . "\n");
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Replace .env atomically so a killed PHP worker cannot leave a truncated
     * configuration file. The small sidecar lock also serializes concurrent
     * installer/finalization requests.
     */
    private function writeAtomically(string $contents): bool
    {
        $directory = dirname($this->envPath);
        if (! is_dir($directory) || ! is_writable($directory)) {
            return false;
        }

        $lock = @fopen($this->envPath . '.lock', 'c+');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return false;
        }

        $temporary = $this->envPath . '.tmp-' . bin2hex(random_bytes(4));
        $backup    = $this->envPath . '.replace-backup-' . bin2hex(random_bytes(4));
        $hadFile   = is_file($this->envPath);

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                return false;
            }

            @chmod($temporary, 0600);

            if ($hadFile && ! @rename($this->envPath, $backup)) {
                return false;
            }
            if (! @rename($temporary, $this->envPath)) {
                if ($hadFile) {
                    @rename($backup, $this->envPath);
                }

                return false;
            }
            if ($hadFile) {
                @unlink($backup);
            }
            @chmod($this->envPath, 0600);

            return true;
        } finally {
            @unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function encodeValue(mixed $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], '', (string) $value);
        if (in_array($value, ['true', 'false', 'null'], true) || preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return $value;
        }
        if ($value === '') {
            return '';
        }

        return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value) . '"';
    }

    /**
     * Resolve a host for APP_URL without persisting an arbitrary Host header.
     */
    private function resolveInstallerHost(Request $request): string
    {
        $host = trim((string) $request->server('SERVER_NAME', ''));
        if (! $this->isValidHost($host)) {
            $host = trim((string) $request->getHost());
        }

        if (! $this->isValidHost($host)) {
            return 'localhost';
        }

        $port = (int) $request->server('SERVER_PORT', $request->getPort());
        if ($port > 0 && ! in_array($port, [80, 443], true)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $host = "[{$host}]";
            }

            return $host . ':' . $port;
        }

        return $host;
    }

    private function isValidHost(string $host): bool
    {
        $host = rtrim($host, '.');

        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return (bool) preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',
            $host
        );
    }

    private function resolveApplicationKey(): string
    {
        $key = trim((string) config('app.key', ''));
        if (str_starts_with($key, 'base64:') && strlen((string) base64_decode(substr($key, 7), true)) === 32) {
            return $key;
        }

        if (strlen($key) >= 32) {
            return $key;
        }

        return 'base64:' . base64_encode(random_bytes(32));
    }
}
