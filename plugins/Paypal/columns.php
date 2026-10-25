<?php

/**
 * PayPal settings.
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-06-29 21:16:23
 * @modified   2022-06-29 21:16:23
 */

use Illuminate\Validation\Rule;

$isEnabled    = static fn (): bool => filter_var(request()->input('status', false), FILTER_VALIDATE_BOOLEAN);
$isSandbox    = static fn (): bool => filter_var(request()->input('sandbox_mode', true), FILTER_VALIDATE_BOOLEAN);
$isRest       = static fn (): bool => strtolower((string) request()->input('api_mode', 'rest')) === 'rest';
$isNvp        = static fn (): bool => strtolower((string) request()->input('api_mode', 'rest')) === 'nvp';
$requiredWhen = static fn (callable $condition): array => [
    Rule::requiredIf(static fn (): bool => $isEnabled() && $condition()),
    'nullable',
    'string',
    'max:2048',
];

return [
    [
        'name'        => 'api_mode',
        'label_key'   => 'setting.api_mode',
        'type'        => 'select',
        'value'       => 'rest',
        'options'     => [
            ['value' => 'rest', 'label_key' => 'setting.api_mode_rest'],
            ['value' => 'nvp', 'label_key' => 'setting.api_mode_nvp'],
        ],
        'required'        => true,
        'rules'           => ['required', Rule::in(['rest', 'nvp'])],
        'description_key' => 'setting.api_mode_desc',
    ],
    [
        'name'            => 'sandbox_client_id',
        'label_key'       => 'setting.sandbox_client_id',
        'type'            => 'string',
        'required'        => false,
        'rules'           => $requiredWhen(static fn (): bool => $isRest() && $isSandbox()),
        'description_key' => 'setting.client_id_desc',
    ],
    [
        'name'            => 'sandbox_secret',
        'label_key'       => 'setting.sandbox_secret',
        'type'            => 'password',
        'required'        => false,
        'rules'           => $requiredWhen(static fn (): bool => $isRest() && $isSandbox()),
        'description_key' => 'setting.secret_desc',
    ],
    [
        'name'            => 'sandbox_merchant_id',
        'label_key'       => 'setting.sandbox_merchant_id',
        'type'            => 'string',
        'required'        => false,
        'rules'           => ['nullable', 'regex:/^[2-9A-HJ-NP-Z]{13}$/'],
        'description_key' => 'setting.merchant_id_desc',
    ],
    [
        'name'            => 'live_client_id',
        'label_key'       => 'setting.live_client_id',
        'type'            => 'string',
        'required'        => false,
        'rules'           => $requiredWhen(static fn (): bool => $isRest() && ! $isSandbox()),
        'description_key' => 'setting.client_id_desc',
    ],
    [
        'name'            => 'live_secret',
        'label_key'       => 'setting.live_secret',
        'type'            => 'password',
        'required'        => false,
        'rules'           => $requiredWhen(static fn (): bool => $isRest() && ! $isSandbox()),
        'description_key' => 'setting.secret_desc',
    ],
    [
        'name'            => 'live_merchant_id',
        'label_key'       => 'setting.live_merchant_id',
        'type'            => 'string',
        'required'        => false,
        'rules'           => ['nullable', 'regex:/^[2-9A-HJ-NP-Z]{13}$/'],
        'description_key' => 'setting.merchant_id_desc',
    ],
    [
        'name'        => 'sandbox_mode',
        'label_key'   => 'setting.sandbox_mode',
        'type'        => 'select',
        'options'     => [
            ['value' => '1', 'label_key' => 'setting.enabled'],
            ['value' => '0', 'label_key' => 'setting.disabled'],
        ],
        'required'    => true,
        'rules'       => ['required', 'boolean'],
        'description' => '',
    ],
    [
        'name'            => 'sandbox_api_username',
        'label_key'       => 'setting.sandbox_api_username',
        'type'            => 'string',
        'required'        => false,
        'rules'           => $requiredWhen(static fn (): bool => $isNvp() && $isSandbox()),
        'description_key' => 'setting.nvp_credentials_desc',
    ],
    [
        'name'            => 'sandbox_api_password',
        'label_key'       => 'setting.sandbox_api_password',
        'type'            => 'password',
        'required'        => false,
        'rules'           => $requiredWhen(static fn (): bool => $isNvp() && $isSandbox()),
        'description_key' => 'setting.nvp_credentials_desc',
    ],
    [
        'name'            => 'sandbox_api_signature',
        'label_key'       => 'setting.sandbox_api_signature',
        'type'            => 'password',
        'required'        => false,
        'rules'           => $requiredWhen(static fn (): bool => $isNvp() && $isSandbox()),
        'description_key' => 'setting.nvp_credentials_desc',
    ],
    [
        'name'            => 'live_api_username',
        'label_key'       => 'setting.live_api_username',
        'type'            => 'string',
        'required'        => false,
        'rules'           => $requiredWhen(static fn (): bool => $isNvp() && ! $isSandbox()),
        'description_key' => 'setting.nvp_credentials_desc',
    ],
    [
        'name'            => 'live_api_password',
        'label_key'       => 'setting.live_api_password',
        'type'            => 'password',
        'required'        => false,
        'rules'           => $requiredWhen(static fn (): bool => $isNvp() && ! $isSandbox()),
        'description_key' => 'setting.nvp_credentials_desc',
    ],
    [
        'name'            => 'live_api_signature',
        'label_key'       => 'setting.live_api_signature',
        'type'            => 'password',
        'required'        => false,
        'rules'           => $requiredWhen(static fn (): bool => $isNvp() && ! $isSandbox()),
        'description_key' => 'setting.nvp_credentials_desc',
    ],
];
