<?php

use Illuminate\Validation\Rule;
use Plugin\LongosPlatformPay\Services\PlatformPaymentCredentials;

$isControl = (bool) data_get(current_site()?->meta, 'control_site', false);

if (! $isControl) {
    return [[
        'name'        => 'tenant_guide',
        'label'       => trans('LongosPlatformPay::common.admin.one_click'),
        'section'     => trans('LongosPlatformPay::common.admin.platform_collection'),
        'type'        => 'guide',
        'required'    => false,
        'description' => trans('LongosPlatformPay::common.admin.tenant_guide'),
    ]];
}

$stripeWebhook = e(trans('LongosPlatformPay::common.admin.webhook_unavailable'));

try {
    $stripeWebhook = e(app(\App\Services\PlatformPayment\PlatformPaymentSettings::class)->mainUrl('_longos/pay/webhooks/stripe'));
} catch (\Throwable) {
    // Keep the settings panel available while the control domain is being
    // provisioned so an operator can still inspect and repair the plugin.
}
$isEnabled      = static fn (): bool => filter_var(request()->input('status', false), FILTER_VALIDATE_BOOLEAN);
$paypalEnabled  = static fn (): bool => $isEnabled()
    && filter_var(request()->input('share_paypal', false), FILTER_VALIDATE_BOOLEAN);
$stripeEnabled = static fn (): bool => $isEnabled()
    && filter_var(request()->input('share_stripe', false), FILTER_VALIDATE_BOOLEAN);

return [
    [
        'name'        => 'control_guide',
        'label'       => trans('LongosPlatformPay::common.admin.main_collection'),
        'section'     => trans('LongosPlatformPay::common.admin.workflow'),
        'type'        => 'guide',
        'required'    => false,
        'description' => trans('LongosPlatformPay::common.admin.main_guide', ['url' => $stripeWebhook]),
    ],
    [
        'name'        => 'share_paypal',
        'label'       => trans('LongosPlatformPay::common.admin.share_paypal'),
        'section'     => trans('LongosPlatformPay::common.admin.channels'),
        'type'        => 'bool',
        'required'    => false,
        'rules'       => ['nullable', 'boolean'],
        'description' => trans('LongosPlatformPay::common.admin.share_paypal_help'),
    ],
    [
        'name'        => 'share_stripe',
        'label'       => trans('LongosPlatformPay::common.admin.share_stripe'),
        'section'     => trans('LongosPlatformPay::common.admin.channels'),
        'type'        => 'bool',
        'required'    => false,
        'rules'       => ['nullable', 'boolean'],
        'description' => trans('LongosPlatformPay::common.admin.share_stripe_help'),
    ],
    [
        'name'        => 'paypal_mode',
        'label'       => trans('LongosPlatformPay::common.admin.paypal_mode'),
        'section'     => trans('LongosPlatformPay::common.admin.paypal_account'),
        'type'        => 'select',
        'options'     => [
            ['value' => 'sandbox', 'label' => trans('LongosPlatformPay::common.admin.sandbox')],
            ['value' => 'live', 'label' => trans('LongosPlatformPay::common.admin.live')],
        ],
        'required'    => true,
        'rules'       => ['required', Rule::in(['sandbox', 'live'])],
        'description' => trans('LongosPlatformPay::common.admin.paypal_mode_help'),
    ],
    [
        'name'        => 'paypal_client_id',
        'label'       => trans('LongosPlatformPay::common.admin.paypal_client_id'),
        'section'     => trans('LongosPlatformPay::common.admin.paypal_account'),
        'type'        => 'string',
        'required'    => false,
        'rules'       => [
            Rule::requiredIf($paypalEnabled), 'nullable', 'string', 'max:512',
            static function ($attribute, $value, $fail) use ($paypalEnabled): void {
                if ($paypalEnabled() && preg_match('/^[A-Za-z0-9_-]{10,512}$/', trim((string) $value)) !== 1) {
                    $fail(trans('LongosPlatformPay::common.admin.paypal_client_id_invalid'));
                }
            },
        ],
        'description' => trans('LongosPlatformPay::common.admin.paypal_client_id_help'),
    ],
    [
        'name'        => 'paypal_secret',
        'label'       => trans('LongosPlatformPay::common.admin.paypal_secret'),
        'section'     => trans('LongosPlatformPay::common.admin.paypal_account'),
        'type'        => 'password',
        'required'    => false,
        'rules'       => [
            Rule::requiredIf($paypalEnabled), 'nullable', 'string', 'max:4096',
            static function ($attribute, $value, $fail) use ($paypalEnabled): void {
                $secret = PlatformPaymentCredentials::runtimeValue('paypal_secret', $value);
                if ($paypalEnabled() && strlen($secret) < 8) {
                    $fail(trans('LongosPlatformPay::common.admin.paypal_secret_invalid'));
                }
            },
        ],
        'description' => trans('LongosPlatformPay::common.admin.paypal_secret_help'),
    ],
    [
        'name'        => 'paypal_merchant_id',
        'label'       => trans('LongosPlatformPay::common.admin.paypal_merchant_id'),
        'section'     => trans('LongosPlatformPay::common.admin.paypal_account'),
        'type'        => 'string',
        'required'    => false,
        'rules'       => ['nullable', 'regex:/^[2-9A-HJ-NP-Z]{13}$/'],
        'description' => trans('LongosPlatformPay::common.admin.paypal_merchant_id_help'),
    ],
    [
        'name'        => 'stripe_test_mode',
        'label'       => trans('LongosPlatformPay::common.admin.stripe_test_mode'),
        'section'     => trans('LongosPlatformPay::common.admin.stripe_account'),
        'type'        => 'select',
        'options'     => [
            ['value' => '1', 'label' => trans('LongosPlatformPay::common.admin.test')],
            ['value' => '0', 'label' => trans('LongosPlatformPay::common.admin.live')],
        ],
        'required'    => true,
        'rules'       => ['required', 'boolean'],
        'description' => trans('LongosPlatformPay::common.admin.stripe_test_mode_help'),
    ],
    [
        'name'        => 'stripe_publishable_key',
        'label'       => trans('LongosPlatformPay::common.admin.stripe_publishable_key'),
        'section'     => trans('LongosPlatformPay::common.admin.stripe_account'),
        'type'        => 'string',
        'required'    => false,
        'rules'       => [
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
        'description' => trans('LongosPlatformPay::common.admin.stripe_publishable_key_help'),
    ],
    [
        'name'        => 'stripe_secret_key',
        'label'       => trans('LongosPlatformPay::common.admin.stripe_secret_key'),
        'section'     => trans('LongosPlatformPay::common.admin.stripe_account'),
        'type'        => 'password',
        'required'    => false,
        'rules'       => [
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
        'description' => trans('LongosPlatformPay::common.admin.stripe_secret_key_help'),
    ],
    [
        'name'        => 'stripe_webhook_secret',
        'label'       => trans('LongosPlatformPay::common.admin.stripe_webhook_secret'),
        'section'     => trans('LongosPlatformPay::common.admin.stripe_callback'),
        'type'        => 'password',
        'required'    => false,
        'rules'       => [
            Rule::requiredIf($stripeEnabled), 'nullable', 'string', 'max:4096',
            static function ($attribute, $value, $fail) use ($stripeEnabled): void {
                $secret = PlatformPaymentCredentials::runtimeValue('stripe_webhook_secret', $value);
                if ($stripeEnabled() && preg_match('/^whsec_[A-Za-z0-9_]{16,}$/', $secret) !== 1) {
                    $fail(trans('LongosPlatformPay::common.admin.stripe_webhook_invalid'));
                }
            },
        ],
        'description' => trans('LongosPlatformPay::common.admin.stripe_webhook_help'),
    ],
];
