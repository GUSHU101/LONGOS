<?php

use Illuminate\Validation\Rule;

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

$stripeWebhook = e(app(\App\Services\PlatformPayment\PlatformPaymentSettings::class)->mainUrl('_longos/pay/webhooks/stripe'));

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
        'name'        => 'stripe_webhook_secret',
        'label'       => trans('LongosPlatformPay::common.admin.stripe_webhook_secret'),
        'section'     => trans('LongosPlatformPay::common.admin.stripe_callback'),
        'type'        => 'password',
        'required'    => false,
        'rules'       => [
            Rule::requiredIf(static fn (): bool => filter_var(request()->input('status', false), FILTER_VALIDATE_BOOLEAN)
                && filter_var(request()->input('share_stripe', false), FILTER_VALIDATE_BOOLEAN)),
            'nullable', 'string', 'max:255', 'regex:/^whsec_[A-Za-z0-9_]{16,}$/',
        ],
        'description' => trans('LongosPlatformPay::common.admin.stripe_webhook_help'),
    ],
];
