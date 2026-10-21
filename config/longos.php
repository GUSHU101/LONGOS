<?php

$cloudflareApiToken   = trim((string) env('CLOUDFLARE_API_TOKEN', ''));
$cloudflareTokenFile  = trim((string) env('CLOUDFLARE_API_TOKEN_FILE', ''));
$cloudflareRangesPath = trim((string) env('CLOUDFLARE_IP_RANGES_PATH', ''));
if ($cloudflareRangesPath === '') {
    // Keep this config file loadable by release/identity tooling which reads
    // it without bootstrapping a full Laravel Application instance.
    $cloudflareRangesPath = dirname(__DIR__) . '/storage/app/cloudflare-ip-ranges.json';
}
$cloudflareTokenPaths = [];
if ($cloudflareTokenFile !== '') {
    $absolute = str_starts_with($cloudflareTokenFile, '/')
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $cloudflareTokenFile) === 1;
    // Never probe an implicit secret path. Only an explicitly configured file
    // may be read, which avoids warnings and keeps immutable containers honest.
    $cloudflareTokenPaths[] = $absolute
        ? $cloudflareTokenFile
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($cloudflareTokenFile, '/\\');
}

$cloudflareTokenInEnvironment = $cloudflareApiToken !== '';
$cloudflareTokenFromFile      = false;
foreach (array_unique($cloudflareTokenPaths) as $cloudflareTokenPath) {
    // A stale absolute path in .env must not leak an open_basedir warning into
    // the storefront. Preflight will still report the credential as missing.
    if (! @is_readable($cloudflareTokenPath)) {
        continue;
    }

    $token = trim((string) @file_get_contents($cloudflareTokenPath));
    if ($token !== '' && $token !== 'not-configured') {
        $cloudflareTokenFromFile = true;

        break;
    }
}
unset($token);

return [
    /*
    |--------------------------------------------------------------------------
    | LONGOS Product Identity
    |--------------------------------------------------------------------------
    |
    | This is the canonical platform identity. Tenant storefront branding is
    | intentionally separate and can be customized per site.
    |
    */
    'identity' => [
        'name'        => env('LONGOS_PRODUCT_NAME', 'LONGOS'),
        'product'     => env('LONGOS_PRODUCT_EDITION', 'LONGOS Commerce SaaS'),
        'owner'       => env('LONGOS_OWNER', 'LONGOS'),
        'tagline'     => env('LONGOS_TAGLINE', 'One platform. Every storefront.'),
        'description' => env(
            'LONGOS_DESCRIPTION',
            'A multi-tenant commerce SaaS platform for operating isolated storefronts at scale.'
        ),
        'logo'    => env('LONGOS_LOGO', 'image/longos-logo.svg'),
        'favicon' => env('LONGOS_FAVICON', 'image/longos-favicon.svg'),
    ],

    'legal' => [
        'license'       => 'OSL-3.0',
        'upstream_name' => 'BeikeShop',
        'upstream_url'  => 'https://github.com/beikeshop/beikeshop',
    ],

    /* Legacy development fallback. Production Coolify deployments perform one
     * serialized fail-closed upgrade in the container entrypoint. */
    'auto_upgrade' => env('LONGOS_AUTO_UPGRADE', false),

    /* Seconds a failed automatic upgrade waits before another request retries. */
    'upgrade_retry_seconds' => env('LONGOS_UPGRADE_RETRY_SECONDS', 30),

    /* Wait for legacy development file replacement to settle. */
    'upgrade_settle_seconds' => env('LONGOS_UPGRADE_SETTLE_SECONDS', 15),

    /*
     * GitHub Release based application updater. Runtime configuration and the
     * optional read-only GitHub token are stored below storage/app/private so
     * a source release can never overwrite them.
     */
    'updater' => [
        'enabled'               => env('LONGOS_UPDATER_ENABLED', false),
        'repository'            => trim((string) env('LONGOS_UPDATE_REPOSITORY', '')),
        'channel'               => strtolower((string) env('LONGOS_UPDATE_CHANNEL', 'stable')),
        'asset_name'            => (string) env('LONGOS_UPDATE_ASSET', 'longos-release.zip'),
        'maximum_bytes'         => (int) env('LONGOS_UPDATE_MAX_BYTES', 1073741824),
        'maximum_files'         => (int) env('LONGOS_UPDATE_MAX_FILES', 100000),
        'command_timeout'       => (int) env('LONGOS_UPDATE_COMMAND_TIMEOUT', 3600),
        'queued_stale_seconds'  => (int) env('LONGOS_UPDATE_QUEUED_STALE_SECONDS', 600),
        'request_drain_seconds' => (int) env('LONGOS_UPDATE_REQUEST_DRAIN_SECONDS', 5),
        'queue_drain_seconds'   => (int) env('LONGOS_UPDATE_QUEUE_DRAIN_SECONDS', 300),
        'php_cli_path'          => (string) env('LONGOS_PHP_CLI_PATH', ''),
        'retain_backups'        => (int) env('LONGOS_UPDATE_RETAIN_BACKUPS', 3),
        'mysqldump_path'        => (string) env('LONGOS_MYSQLDUMP_PATH', ''),
        'mysql_path'            => (string) env('LONGOS_MYSQL_PATH', ''),
        'pg_dump_path'          => (string) env('LONGOS_PG_DUMP_PATH', ''),
        'pg_restore_path'       => (string) env('LONGOS_PG_RESTORE_PATH', ''),
        'psql_path'             => (string) env('LONGOS_PSQL_PATH', ''),
        'github_api'            => 'https://api.github.com',
        'github_api_version'    => '2022-11-28',
    ],

    /* Read-only order links sent by email expire and cannot mutate orders. */
    'order_access_link_days' => (int) env('LONGOS_ORDER_ACCESS_LINK_DAYS', 30),

    /* Keep the conversion-focused checkout language stable across storefront
     * admin locales. Tenants can override this explicitly when needed. */
    'checkout_locale' => (string) env('LONGOS_CHECKOUT_LOCALE', 'en'),

    /* Hard cap for images copied from a remote URL into the tenant store. */
    'remote_image_max_bytes' => (int) env('LONGOS_REMOTE_IMAGE_MAX_BYTES', 10 * 1024 * 1024),

    'network' => [
        // Exact IPs/CIDRs of operator-controlled reverse proxies. Wildcards
        // are deliberately rejected by TrustProxies.
        'trusted_proxies' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LONGOS_TRUSTED_PROXIES', ''))
        ))),
        'proxy_token' => env('LONGOS_PROXY_TOKEN'),
    ],

    'cloudflare' => [
        // off: do not treat Cloudflare anycast DNS as a valid edge target.
        // optional: accept direct traffic and correctly trust Cloudflare when
        // present. required is reserved for a server-level Coolify Caddy policy
        // because an application must not rewrite the shared proxy globally.
        'mode'                             => strtolower((string) env('LONGOS_CLOUDFLARE_MODE', 'optional')),
        // Runtime services must read credentials through configuration so
        // production `artisan optimize` / `config:cache` cannot hide them.
        'api_token'                        => $cloudflareApiToken,
        'api_token_configured'             => $cloudflareTokenInEnvironment || $cloudflareTokenFromFile,
        'api_token_file_configured'        => $cloudflareTokenFromFile,
        'api_token_environment_configured' => $cloudflareTokenInEnvironment,
        'api_token_file'                   => $cloudflareTokenFile,
        'ip_ranges_path'                   => $cloudflareRangesPath,
    ],
];
