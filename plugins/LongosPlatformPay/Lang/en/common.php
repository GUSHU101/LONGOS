<?php

return [
    'secure_title' => 'Secure payment hosted by LONGOS',
    'secure_help'  => 'Choose PayPal or card on the main site, then return here after verification.',
    'continue'     => 'Continue to secure payment',
    'connecting'   => 'Creating a secure payment session…',
    'failed'       => 'The secure payment service is temporarily unavailable. Please try again.',
    'admin'        => [
        'one_click'             => 'One-click enablement',
        'platform_collection'   => 'Platform collection',
        'tenant_guide'          => 'Once enabled, customers move from the child store to the secure LONGOS main-site payment page. PayPal and Stripe secrets are never copied to this child site. The main site verifies the amount, currency, environment, and order ownership before updating the order.',
        'main_collection'       => 'Main-site collection',
        'workflow'              => 'How it works',
        'main_guide'            => 'Save the main account credentials in the PayPal and Stripe plugins, then choose which channels child sites may use. Provider creation, callbacks, and return URLs all use the main-site domain. Confirm that your PayPal/Stripe account agreement permits platform collection before enabling it. Stripe platform Webhook URL: <code>:url</code>',
        'channels'              => 'Shared channels',
        'share_paypal'          => 'Share PayPal with child sites',
        'share_paypal_help'     => 'Uses the REST API account for the current environment in the main-site PayPal plugin.',
        'share_stripe'          => 'Share Stripe with child sites',
        'share_stripe_help'     => 'Uses the current test/live account in the main-site Stripe plugin. Platform callbacks use the separate signing secret below.',
        'stripe_callback'       => 'Stripe callback',
        'stripe_webhook_secret' => 'Platform Webhook signing secret',
        'stripe_webhook_help'   => 'Create a separate endpoint in Stripe for the platform Webhook URL above, then paste that endpoint’s whsec_ signing secret. Do not reuse a child-site or standard Stripe-plugin endpoint secret.',
    ],
];
