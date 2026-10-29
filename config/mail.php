<?php

$longosRelaySecret    = (string) env('MAIL_RELAY_SHARED_SECRET', '');
$cloudMailRelaySecret = (string) env('CLOUD_MAIL_RELAY_SHARED_SECRET', '');
$normalizeRelayDomain = static function (mixed $value): string {
    $domain = strtolower(trim((string) $value, " .\t\n\r\0\x0B"));

    return str_contains($domain, '.')
        && filter_var($domain, FILTER_VALIDATE_IP) === false
        && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
        ? $domain
        : '';
};
$appUrl              = rtrim((string) env('APP_URL', ''), '/');
$appHost             = $normalizeRelayDomain(parse_url($appUrl, PHP_URL_HOST));
$automaticMailDomain = $normalizeRelayDomain(env('SAAS_BASE_DOMAIN', '')) ?: $appHost;
$relayDomains        = trim((string) env('MAIL_RELAY_DOMAINS', '')) ?: $automaticMailDomain;
$allowedDomains      = trim((string) env('MAIL_RELAY_ALLOWED_SENDER_DOMAINS', '')) ?: $relayDomains;
$allowedDomains      = array_values(array_unique(array_filter(array_map(
    $normalizeRelayDomain,
    explode(',', $allowedDomains),
))));
$platformSenderDomain = $normalizeRelayDomain(env('MAIL_RELAY_PLATFORM_SENDER_DOMAIN', ''))
    ?: ($allowedDomains[0] ?? $automaticMailDomain);
$relayHostname = $normalizeRelayDomain(env('MAIL_RELAY_HOSTNAME', ''))
    ?: ($automaticMailDomain !== '' ? 'mailout.' . $automaticMailDomain : '');
$relayPublicUrl = trim((string) env('MAIL_RELAY_PUBLIC_URL', ''))
    ?: ($appUrl !== '' ? $appUrl . '/_longos/mail-relay' : '');
$relayPublicIps = array_values(array_unique(array_filter(array_map(
    static fn (string $value): string => trim($value),
    explode(',', (string) env('MAIL_RELAY_IPS', '')),
), static fn (string $value): bool => filter_var(
    $value,
    FILTER_VALIDATE_IP,
    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE,
) !== false)));
$relayReadyByConfiguration = $platformSenderDomain !== ''
    && strlen($longosRelaySecret)    >= 32
    && strlen($cloudMailRelaySecret) >= 32
    && ! hash_equals($longosRelaySecret, $cloudMailRelaySecret);
$relayEnabled             = (bool) env('LONGOS_MAIL_RELAY_ENABLED', $relayReadyByConfiguration);
$configuredMailFrom       = strtolower(trim((string) env('MAIL_FROM_ADDRESS', '')));
$configuredMailFromDomain = $normalizeRelayDomain(substr(strrchr($configuredMailFrom, '@') ?: '', 1));
$configuredMailFromValid  = $configuredMailFrom               !== ''
    && filter_var($configuredMailFrom, FILTER_VALIDATE_EMAIL) !== false
    && (! $relayEnabled || in_array($configuredMailFromDomain, $allowedDomains, true));
$mailFromAddress = $configuredMailFromValid
    ? $configuredMailFrom
    : ($platformSenderDomain !== '' ? 'no-reply@' . $platformSenderDomain : 'hello@example.com');

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send any email
    | messages sent by your application. Alternative mailers may be setup
    | and used as needed; however, this mailer will be used by default.
    |
    */

    'default'  => env('MAIL_MAILER', $relayEnabled ? 'self_hosted' : 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers to be used while
    | sending an e-mail. You will specify which one you are using for your
    | mailers below. You are free to add additional mailers as required.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses",
    |            "postmark", "log", "array", "failover"
    |
    */

    'mailers'  => [
        'smtp'     => [
            'transport'  => 'smtp',
            'host'       => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port'       => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username'   => env('MAIL_USERNAME'),
            'password'   => env('MAIL_PASSWORD'),
            'timeout'    => null,
            'auth_mode'  => null,
        ],

        'brevo' => [
            'transport' => 'brevo',
            'api_key'   => env('BREVO_API_KEY'),
            'timeout'   => (int) env('BREVO_TIMEOUT', 15),
        ],

        // The Docker installer provisions this authenticated relay on the
        // private LONGOS network. Postfix itself only listens on loopback.
        'self_hosted' => [
            'transport'            => 'longos_relay',
            'endpoint'             => env('MAIL_RELAY_ENDPOINT', 'http://mail-relay:3000'),
            'secret'               => $longosRelaySecret,
            'timeout'              => (int) env('MAIL_RELAY_TIMEOUT', 30),
            'max_request_bytes'    => (int) env('MAIL_RELAY_MAX_REQUEST_BYTES', 33554432),
            'max_attachment_bytes' => (int) env('MAIL_RELAY_MAX_ATTACHMENT_BYTES', 20971520),
            'max_attachments'      => (int) env('MAIL_RELAY_MAX_ATTACHMENTS', 25),
            'max_recipients'       => (int) env('MAIL_RELAY_MAX_RECIPIENTS', 50),
        ],

        'ses'      => [
            'transport' => 'ses',
        ],

        'mailgun'  => [
            'transport' => 'mailgun',
        ],

        'postmark' => [
            'transport' => 'postmark',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path'      => '/usr/sbin/sendmail -bs',
        ],

        'log'      => [
            'transport' => 'log',
            'channel'   => env('MAIL_LOG_CHANNEL'),
        ],

        'array'    => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers'   => [
                'smtp',
                'log',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all e-mails sent by your application to be sent from
    | the same address. Here, you may specify a name and address that is
    | used globally for all e-mails that are sent by your application.
    |
    */

    'from'     => [
        'address' => $mailFromAddress,
        'name'    => env('MAIL_FROM_NAME', 'LONGOS'),
    ],

    'relay' => [
        'enabled'                       => $relayEnabled,
        'hostname'                      => $relayHostname,
        'domains'                       => $relayDomains,
        'allowed_sender_domains'        => $allowedDomains,
        'platform_sender_domain'        => $platformSenderDomain,
        'public_url'                    => $relayPublicUrl,
        'public_ips'                    => $relayPublicIps,
        // MAIL_RELAY_IPS is an override. When it is blank, the app and
        // mail-relay share the same Docker bridge/NAT and LONGOS accepts an
        // automatically observed egress IP only after independent HTTPS
        // reflectors reach quorum. Website DNS is never used for inference.
        'auto_detect_public_ips'         => filter_var(env('MAIL_RELAY_AUTO_DETECT_IPS', true), FILTER_VALIDATE_BOOL),
        'public_ip_detection_endpoints'  => [
            'https://1.1.1.1/cdn-cgi/trace',
            'https://checkip.amazonaws.com',
            'https://api.ipify.org',
        ],
        'public_ip_detection_quorum'        => 2,
        'public_ip_detection_timeout'       => 3,
        'public_ip_detection_cache_seconds' => 900,
        // Public DoH responses expose DNS RCODEs, unlike dns_get_record(), so
        // diagnostics can distinguish a missing PTR from SERVFAIL/REFUSED and
        // broken reverse-zone delegation. These lookups are never cached.
        'ptr_doh_endpoints' => [
            'Cloudflare' => 'https://cloudflare-dns.com/dns-query',
            'Google'     => 'https://dns.google/resolve',
        ],
        'ptr_doh_timeout'                   => 4,
        'dkim_selector'                     => env('MAIL_RELAY_DKIM_SELECTOR', 'mail2026'),
        'auto_configure_dns'                => (bool) env('LONGOS_AUTO_CONFIGURE_MAIL_DNS', true),
        'dns_records_path'                  => env('MAIL_RELAY_DNS_RECORDS_PATH', '/var/lib/longos-mail-dns'),
        // Non-secret topology overrides saved from the control-site mail page.
        // app, worker and scheduler share this file through longos-storage.
        'runtime_settings_path'              => env('MAIL_RELAY_RUNTIME_SETTINGS_PATH', storage_path('app/longos/mail-relay-runtime.json')),
        'cloudflare_api_base'                => rtrim((string) env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'), '/'),
        'cloud_mail_secret'                  => $cloudMailRelaySecret,
        'max_request_bytes'                  => (int) env('MAIL_RELAY_MAX_REQUEST_BYTES', 33554432),
        'max_attachment_bytes'               => (int) env('MAIL_RELAY_MAX_ATTACHMENT_BYTES', 20971520),
        'max_attachments'                    => (int) env('MAIL_RELAY_MAX_ATTACHMENTS', 25),
        'max_recipients'                     => (int) env('MAIL_RELAY_MAX_RECIPIENTS', 50),
        'longos_client_configured'           => strlen($longosRelaySecret)    >= 32,
        'cloud_mail_client_configured'       => strlen($cloudMailRelaySecret) >= 32,
        'client_secrets_distinct'            => $longosRelaySecret !== ''
            && $cloudMailRelaySecret                               !== ''
            && ! hash_equals($longosRelaySecret, $cloudMailRelaySecret),
        // An invalid or unsigned legacy MAIL_FROM_ADDRESS must not brick a
        // deployment. The runtime falls back to the signed platform domain;
        // preflight still reports the ignored override as an operator warning.
        'configured_from_address_rejected' => $configuredMailFrom !== '' && ! $configuredMailFromValid,
    ],

    /*
    |--------------------------------------------------------------------------
    | Markdown Mail Settings
    |--------------------------------------------------------------------------
    |
    | If you are using Markdown based email rendering, you may configure your
    | theme and component paths here, allowing you to customize the design
    | of the emails. Or, you may simply stick with the Laravel defaults!
    |
    */

    'markdown' => [
        'theme' => 'default',

        'paths' => [
            resource_path('views/vendor/mail'),
        ],
    ],

];
