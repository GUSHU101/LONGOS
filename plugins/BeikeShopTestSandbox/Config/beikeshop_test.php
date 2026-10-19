<?php

return [
    'checkout_takeover' => [
        // This plugin is a checkout sandbox, not a real payment method. While
        // enabled it becomes the shop-wide checkout surface for every theme.
        // Fail closed: merely installing/enabling this diagnostics plugin must
        // never replace a tenant's real checkout. Operators must opt in
        // explicitly for a dedicated test storefront.
        'enabled' => env('BEIKESHOP_TEST_CHECKOUT_TAKEOVER', false),
    ],

    'card_input' => [
        // whitelist: only the built-in synthetic cards may start a checkout.
        // open: any 12-19 digit value may run through the local demo flow.
        // In open mode valid values are sent to the configured BIN provider so
        // the full detection flow remains available.
        'mode' => env('BEIKESHOP_TEST_CARD_MODE', 'whitelist'),
    ],

    'records' => [
        // V3.1: database records have no automatic TTL/prune policy.
        // They remain until an authenticated admin explicitly deletes an order.
        'manual_delete_only' => true,
    ],

    'telegram' => [
        // Keep external notifications opt-in. Plugin settings take precedence
        // over these environment fallbacks.
        'enabled'                 => env('BEIKESHOP_TEST_TELEGRAM_ENABLED', false),
        'bot_token'               => env('BEIKESHOP_TEST_TELEGRAM_BOT_TOKEN'),
        'chat_id'                 => env('BEIKESHOP_TEST_TELEGRAM_CHAT_ID'),
        'product_name'            => env('BEIKESHOP_TEST_TELEGRAM_PRODUCT_NAME', '招牌套餐'),
        'message_template'        => env('BEIKESHOP_TEST_TELEGRAM_MESSAGE_TEMPLATE', "🔔 店铺新订单\n订单号：{{order_id}}\n商品：{{product_name}}\n请及时处理。"),
        'timeout_seconds'         => env('BEIKESHOP_TEST_TELEGRAM_TIMEOUT', 5),
        'connect_timeout_seconds' => env('BEIKESHOP_TEST_TELEGRAM_CONNECT_TIMEOUT', 3),
        'retry_attempts'          => env('BEIKESHOP_TEST_TELEGRAM_RETRY_ATTEMPTS', 2),
        // Empty selects Composer's maintained CA bundle while keeping TLS
        // verification enabled on Windows hosts without a configured CA file.
        'ca_bundle' => env('BEIKESHOP_TEST_TELEGRAM_CA_BUNDLE'),
    ],

    'bin_lookup' => [
        'enabled'  => env('BINSEARCHLOOKUP_ENABLED', true),
        'base_url' => env('BINSEARCHLOOKUP_BASE_URL', 'https://api.binsearchlookup.com'),
        'api_key'  => env('BINSEARCHLOOKUP_API_KEY'),
        'user_id'  => env('BINSEARCHLOOKUP_USER_ID'),
        // Optional PEM bundle for hosts with a custom CA. Empty uses Composer's
        // maintained bundle while preserving TLS certificate verification.
        'ca_bundle' => env('BINSEARCHLOOKUP_CA_BUNDLE'),

        // Fresh cache protects quota; stale cache keeps the demo functional on outage/429.
        'cache_ttl_seconds' => env('BINSEARCHLOOKUP_CACHE_TTL', 86400),
        'stale_ttl_seconds' => env('BINSEARCHLOOKUP_STALE_TTL', 604800),

        'timeout_seconds'         => env('BINSEARCHLOOKUP_TIMEOUT', 5),
        'connect_timeout_seconds' => env('BINSEARCHLOOKUP_CONNECT_TIMEOUT', 3),
        'retry_attempts'          => env('BINSEARCHLOOKUP_RETRY_ATTEMPTS', 2),

        // Do not hard-code provider tier limits. Read actual quota and guard by ratios.
        'quota_cache_seconds'       => env('BINSEARCHLOOKUP_QUOTA_CACHE', 60),
        'quota_guard_enabled'       => env('BINSEARCHLOOKUP_QUOTA_GUARD', true),
        'monthly_quota_guard_ratio' => env('BINSEARCHLOOKUP_MONTH_GUARD', 0.95),
        'minute_quota_guard_ratio'  => env('BINSEARCHLOOKUP_MINUTE_GUARD', 0.90),

        // Simple distributed circuit breaker.
        'circuit_failure_threshold' => env('BINSEARCHLOOKUP_CIRCUIT_FAILURES', 3),
        'circuit_window_seconds'    => env('BINSEARCHLOOKUP_CIRCUIT_WINDOW', 60),
        'circuit_open_seconds'      => env('BINSEARCHLOOKUP_CIRCUIT_OPEN', 60),

        'health_cache_seconds' => env('BINSEARCHLOOKUP_HEALTH_CACHE', 30),

        // Used by the admin "warm cache" endpoint / Artisan command.
        'warm_bins' => [
            '42424242',
            '40000566',
            '55555555',
            '52008282',
            '51051051',
            '37828224',
            '35660020',
            '35301113',
            '62000000',
        ],

        // Optional signed quota webhook receiver.
        'quota_webhook_secret' => env('BINSEARCHLOOKUP_WEBHOOK_SECRET'),
    ],
];
