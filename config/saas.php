<?php

// A standard Coolify deployment needs only the control domain. Its current
// DNS route is a safe default baseline for tenant-domain publication because
// the control host is already served by this same Caddy edge. Operators with
// split Cloudflare/direct-origin routing can still provide explicit targets.
$configuredDnsTargets = trim((string) env('SAAS_DNS_TARGETS', ''));
if ($configuredDnsTargets === '') {
    $configuredDnsTargets = (string) env('SAAS_CONTROL_DOMAINS', '');
}

return [
    /*
    |--------------------------------------------------------------------------
    | SaaS multi-site mode
    |--------------------------------------------------------------------------
    |
    | The existing DB_DATABASE remains the control database and the database
    | of the first site. The default prefix mode keeps deployment simple:
    | every tenant uses the same physical database with isolated table prefixes.
    | Set SAAS_DATABASE_MODE=database for separate physical tenant databases.
    |
    */
    'enabled' => env('SAAS_ENABLED', false),

    'database_mode' => env('SAAS_DATABASE_MODE', 'prefix'),

    'shared_database' => env('SAAS_SHARED_DATABASE') ?: env('DB_DATABASE'),

    'central_connection' => env('SAAS_DB_CONNECTION') ?: env('DB_CONNECTION', 'mysql'),

    'tenant_connection' => env('SAAS_TENANT_DB_CONNECTION') ?: env('DB_CONNECTION', 'mysql'),

    'central' => [
        'host'     => env('SAAS_DB_HOST'),
        'port'     => env('SAAS_DB_PORT'),
        'database' => env('SAAS_DB_DATABASE', env('DB_DATABASE')),
        'username' => env('SAAS_DB_USERNAME'),
        'password' => env('SAAS_DB_PASSWORD'),
    ],

    // Tenant databases may live on a different server from the control DB.
    // Blank values inherit the selected tenant_connection configuration.
    'tenant' => [
        'host'       => env('SAAS_TENANT_DB_HOST'),
        'port'       => env('SAAS_TENANT_DB_PORT'),
        'username'   => env('SAAS_TENANT_DB_USERNAME'),
        'password'   => env('SAAS_TENANT_DB_PASSWORD'),
        'grant_host' => env('SAAS_TENANT_DB_GRANT_HOST', '%'),
    ],

    // Optional privileged provisioning account. Blank values inherit the
    // tenant runtime connection, while production may use a separate account.
    'tenant_admin' => [
        'host'     => env('SAAS_TENANT_ADMIN_HOST'),
        'port'     => env('SAAS_TENANT_ADMIN_PORT'),
        'database' => env('SAAS_TENANT_ADMIN_DATABASE'),
        'username' => env('SAAS_TENANT_ADMIN_USERNAME'),
        'password' => env('SAAS_TENANT_ADMIN_PASSWORD'),
    ],

    'base_domain'       => env('SAAS_BASE_DOMAIN'),
    'database_prefix'   => env('SAAS_DATABASE_PREFIX', 'longos_'),
    'default_scheme'    => env('SAAS_DEFAULT_SCHEME', 'https'),
    'reject_unknown'    => env('SAAS_REJECT_UNKNOWN_DOMAINS', true),
    'max_batch_size'    => (int) env('SAAS_MAX_BATCH_SIZE', 100),
    'control_domains'   => array_values(array_filter(array_map('trim', explode(',', (string) env('SAAS_CONTROL_DOMAINS', ''))))),
    'http_management'   => env('SAAS_HTTP_MANAGEMENT', false),
    'isolate_files'     => env('SAAS_ISOLATE_FILES', true),
    'custom_domains'    => [
        'enabled'                       => env('SAAS_CUSTOM_DOMAINS_ENABLED', true),
        'max_per_site'                  => (int) env('SAAS_CUSTOM_DOMAINS_MAX_PER_SITE', 10),
        'verification_ttl_hours'        => (int) env('SAAS_DOMAIN_VERIFICATION_TTL_HOURS', 72),
        'verification_retry_minutes'    => (int) env('SAAS_DOMAIN_VERIFICATION_RETRY_MINUTES', 15),
        'verification_batch'            => (int) env('SAAS_DOMAIN_VERIFICATION_BATCH', 100),
        'probe_path'                    => '/_longos/domain/probe',
        'probe_connect_timeout_seconds' => (int) env('SAAS_DOMAIN_PROBE_CONNECT_TIMEOUT', 3),
        'probe_timeout_seconds'         => (int) env('SAAS_DOMAIN_PROBE_TIMEOUT', 8),
        // The first Caddy handshake may need to complete an ACME order before
        // the signed probe can reach Laravel, so it needs a larger bound than
        // an already-provisioned external HTTPS edge.
        'caddy_probe_timeout_seconds'   => (int) env('SAAS_CADDY_PROBE_TIMEOUT', 45),
    ],
    'domains'           => [
        // Shopify-style canonical routing: secondary domains redirect to the
        // primary storefront unless a tenant explicitly marks one as alias.
        'redirect_secondary'    => env('SAAS_REDIRECT_SECONDARY_DOMAINS', true),
        'redirect_status'       => (int) env('SAAS_PRIMARY_REDIRECT_STATUS', 308),
        'redirect_exempt_paths' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value, " /\t\n\r\0\x0B"),
            explode(',', (string) env('SAAS_PRIMARY_REDIRECT_EXEMPT_PATHS', 'api,installer'))
        ))),
        'retain_platform_domain' => env('SAAS_RETAIN_PLATFORM_DOMAIN', true),
        'reserved_subdomains'    => array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', (string) env('SAAS_RESERVED_SUBDOMAINS', 'www,admin,api,app,mail,ftp,smtp,pop,imap,support,status,docs,cdn,static,assets,ns1,ns2'))
        ))),
    ],
    'tls'               => [
        // Caddy On-Demand TLS obtains and renews certificates only after its
        // ask endpoint confirms that a host is active in the control plane.
        'driver'   => env('SAAS_TLS_DRIVER', 'caddy'),
        'email'    => env('SAAS_TLS_EMAIL'),
        'ask_path' => '/_longos/tls/authorize',
        // Kept for backwards-compatible environment parsing. Coolify+Caddy
        // owns the control domain certificate as well, so this remains false.
        'external_control_domain' => env('SAAS_TLS_EXTERNAL_CONTROL_DOMAIN', false),
    ],
    'edge'              => [
        // Coolify runs one server-wide Caddy proxy. LONGOS contributes a
        // fail-closed On-Demand TLS route through Docker labels.
        'platform' => strtolower((string) env('LONGOS_DEPLOYMENT_PLATFORM', 'coolify')),
    ],
    'idempotency'       => [
        'required'                   => env('SAAS_REQUIRE_IDEMPOTENCY_KEY', true),
        'ttl_hours'                  => (int) env('SAAS_IDEMPOTENCY_TTL_HOURS', 24),
        'processing_timeout_minutes' => (int) env('SAAS_IDEMPOTENCY_PROCESSING_TIMEOUT_MINUTES', 120),
    ],
    'provisioning'      => [
        'lease_timeout_minutes' => (int) env('SAAS_PROVISIONING_LEASE_TIMEOUT_MINUTES', 120),
        // Tenant migrations and seeders boot the console kernel inside the
        // authenticated management request, just like the web installer.
        'memory_limit'             => env('SAAS_PROVISIONING_MEMORY_LIMIT', '512M'),
        'minimum_memory_bytes'     => (int) env('SAAS_PROVISIONING_MINIMUM_MEMORY_BYTES', 268435456),
        'max_execution_seconds'    => (int) env('SAAS_PROVISIONING_MAX_EXECUTION_SECONDS', 0),
    ],
    'dns_targets'       => array_values(array_filter(array_map(
        static fn (string $value): string => rtrim(strtolower(trim($value)), '.'),
        explode(',', $configuredDnsTargets)
    ))),

    'domain_config' => [
        'driver'               => env('SAAS_WEB_SERVER', 'caddy'),
        'path'                 => env('SAAS_DOMAIN_CONFIG_PATH') ?: storage_path('app/saas/domains.conf'),
        'manifest_path'        => env('SAAS_DOMAIN_MANIFEST_PATH') ?: storage_path('app/saas/domains.json'),
        'lock_timeout_seconds' => (int) env('SAAS_DOMAIN_LOCK_TIMEOUT_SECONDS', 10),
    ],
];
