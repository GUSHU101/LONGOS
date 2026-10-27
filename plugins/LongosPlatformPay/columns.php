<?php

use App\Services\PlatformPayment\PlatformPaymentSettings;
use Illuminate\Validation\Rule;
use Plugin\LongosPlatformPay\Services\PlatformPaymentCredentials;

$isControl = (bool) data_get(current_site()?->meta, 'control_site', false);

if (! $isControl) {
    return [[
        'name'            => 'tenant_guide',
        'label_key'       => 'common.admin.one_click',
        'section_key'     => 'common.admin.platform_collection',
        'type'            => 'guide',
        'required'        => false,
        'description_key' => 'common.admin.tenant_guide',
    ]];
}

try {
    $stripeWebhook = e(app(PlatformPaymentSettings::class)->mainUrl('_longos/pay/webhooks/stripe'));
} catch (\Throwable) {
    // Keep the settings screen reachable when the operator is repairing an
    // invalid control-domain configuration. The save-time readiness check
    // still refuses to enable a route that cannot be reached canonically.
    $stripeWebhook = e(rtrim(request()->getSchemeAndHttpHost(), '/') . '/_longos/pay/webhooks/stripe');
}
$isEnabled      = static fn (): bool => filter_var(request()->input('status', false), FILTER_VALIDATE_BOOLEAN);
$paypalEnabled  = static fn (): bool => $isEnabled()
    && filter_var(request()->input('share_paypal', false), FILTER_VALIDATE_BOOLEAN);
$stripeEnabled = static fn (): bool => $isEnabled()
    && filter_var(request()->input('share_stripe', false), FILTER_VALIDATE_BOOLEAN);
return [
    [
        'name'               => 'control_guide',
        'label_key'          => 'common.admin.main_collection',
        'section_key'        => 'common.admin.workflow',
        'type'               => 'guide',
        'required'           => false,
        'description_key'    => 'common.admin.main_guide',
        'description_params' => ['url' => $stripeWebhook],
    ],
    [
        'name'             => 'provider_health',
        'label_key'        => 'common.admin.health_check',
        'section_key'      => 'common.admin.runtime_section',
        'type'             => 'action',
        'required'         => false,
        // Plugin columns may be discovered before the admin route collection
        // is ready. Resolve the named route when the form is rendered instead
        // of caching an empty URL that leaves a visible but inert button.
        'action_route'     => 'plugins.platform_payment_health',
        'action_form'      => true,
        'action_label_key' => 'common.admin.health_check_action',
        'action_feedback'  => 'longos-platform-payment-health-feedback',
        'description_key'  => 'common.admin.health_check_help',
    ],
    [
        'name'            => 'share_paypal',
        'label_key'       => 'common.admin.share_paypal',
        'section_key'     => 'common.admin.channels',
        'type'            => 'bool',
        'required'        => false,
        'rules'           => ['nullable', 'boolean'],
        'description_key' => 'common.admin.share_paypal_help',
    ],
    [
        'name'            => 'share_stripe',
        'label_key'       => 'common.admin.share_stripe',
        'section_key'     => 'common.admin.channels',
        'type'            => 'bool',
        'required'        => false,
        'rules'           => ['nullable', 'boolean'],
        'description_key' => 'common.admin.share_stripe_help',
    ],
    [
        'name'            => 'paypal_mode',
        'label_key'       => 'common.admin.paypal_mode',
        'section_key'     => 'common.admin.paypal_account',
        'type'            => 'select',
        'options'         => [
            ['value' => 'sandbox', 'label_key' => 'common.admin.sandbox'],
            ['value' => 'live', 'label_key' => 'common.admin.live'],
        ],
        'required'        => true,
        'rules'           => ['required', Rule::in(['sandbox', 'live'])],
        'description_key' => 'common.admin.paypal_mode_help',
    ],
    [
        'name'            => 'paypal_client_id',
        'label_key'       => 'common.admin.paypal_client_id',
        'section_key'     => 'common.admin.paypal_account',
        'type'            => 'string',
        'required'        => false,
        'rules'           => [
            Rule::requiredIf($paypalEnabled), 'nullable', 'string', 'max:512',
            static function ($attribute, $value, $fail) use ($paypalEnabled): void {
                if ($paypalEnabled() && preg_match('/^[A-Za-z0-9_-]{10,512}$/', trim((string) $value)) !== 1) {
                    $fail(trans('LongosPlatformPay::common.admin.paypal_client_id_invalid'));
                }
            },
        ],
        'description_key' => 'common.admin.paypal_client_id_help',
    ],
    [
        'name'            => 'paypal_secret',
        'label_key'       => 'common.admin.paypal_secret',
        'section_key'     => 'common.admin.paypal_account',
        'type'            => 'password',
        'required'        => false,
        'rules'           => [
            Rule::requiredIf($paypalEnabled), 'nullable', 'string', 'max:4096',
            static function ($attribute, $value, $fail) use ($paypalEnabled): void {
                $secret = PlatformPaymentCredentials::runtimeValue('paypal_secret', $value);
                if ($paypalEnabled() && strlen($secret) < 8) {
                    $fail(trans('LongosPlatformPay::common.admin.paypal_secret_invalid'));
                }
            },
        ],
        'description_key' => 'common.admin.paypal_secret_help',
    ],
    [
        'name'            => 'paypal_merchant_id',
        'label_key'       => 'common.admin.paypal_merchant_id',
        'section_key'     => 'common.admin.paypal_account',
        'type'            => 'string',
        'required'        => false,
        'rules'           => ['nullable', 'regex:/^[2-9A-HJ-NP-Z]{13}$/'],
        'description_key' => 'common.admin.paypal_merchant_id_help',
    ],
    [
        'name'            => 'stripe_test_mode',
        'label_key'       => 'common.admin.stripe_test_mode',
        'section_key'     => 'common.admin.stripe_account',
        'type'            => 'select',
        'options'         => [
            ['value' => '1', 'label_key' => 'common.admin.test'],
            ['value' => '0', 'label_key' => 'common.admin.live'],
        ],
        'required'        => true,
        'rules'           => ['required', 'boolean'],
        'description_key' => 'common.admin.stripe_test_mode_help',
    ],
    [
        'name'            => 'stripe_publishable_key',
        'label_key'       => 'common.admin.stripe_publishable_key',
        'section_key'     => 'common.admin.stripe_account',
        'type'            => 'string',
        'required'        => false,
        'rules'           => [
            Rule::requiredIf($stripeEnabled), 'nullable', 'string', 'max:255',
            static function ($attribute, $value, $fail) use ($stripeEnabled): void {
                if (! $stripeEnabled()) {
                    return;
                }
                $prefix = filter_var(request()->input('stripe_test_mode', true), FILTER_VALIDATE_BOOLEAN) ? 'test' : 'live';
                if (preg_match('/^pk_' . $prefix . '_[A-Za-z0-9_]{16,}$/', trim((string) $value)) !== 1) {
                    $fail(trans('LongosPlatformPay::common.admin.stripe_key_invalid'));
                }
            },
        ],
        'description_key' => 'common.admin.stripe_publishable_key_help',
    ],
    [
        'name'            => 'stripe_secret_key',
        'label_key'       => 'common.admin.stripe_secret_key',
        'section_key'     => 'common.admin.stripe_account',
        'type'            => 'password',
        'required'        => false,
        'rules'           => [
            Rule::requiredIf($stripeEnabled), 'nullable', 'string', 'max:4096',
            static function ($attribute, $value, $fail) use ($stripeEnabled): void {
                if (! $stripeEnabled()) {
                    return;
                }
                $prefix = filter_var(request()->input('stripe_test_mode', true), FILTER_VALIDATE_BOOLEAN) ? 'test' : 'live';
                $secret = PlatformPaymentCredentials::runtimeValue('stripe_secret_key', $value);
                if (preg_match('/^sk_' . $prefix . '_[A-Za-z0-9_]{16,}$/', $secret) !== 1) {
                    $fail(trans('LongosPlatformPay::common.admin.stripe_key_invalid'));
                }
            },
        ],
        'description_key' => 'common.admin.stripe_secret_key_help',
    ],
    [
        'name'            => 'stripe_webhook_secret',
        'label_key'       => 'common.admin.stripe_webhook_secret',
        'section_key'     => 'common.admin.stripe_callback',
        'type'            => 'password',
        'required'        => false,
        'rules'           => [
            Rule::requiredIf($stripeEnabled), 'nullable', 'string', 'max:4096',
            static function ($attribute, $value, $fail) use ($stripeEnabled): void {
                $secret = PlatformPaymentCredentials::runtimeValue('stripe_webhook_secret', $value);
                if ($stripeEnabled() && preg_match('/^whsec_[A-Za-z0-9_]{16,}$/', $secret) !== 1) {
                    $fail(trans('LongosPlatformPay::common.admin.stripe_webhook_invalid'));
                }
            },
        ],
        'description_key' => 'common.admin.stripe_webhook_help',
    ],
];
