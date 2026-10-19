<?php

use Illuminate\Validation\Rule;

$isEnabled = static fn (): bool => filter_var(request()->input('status', false), FILTER_VALIDATE_BOOLEAN);
$isTest    = static fn (): bool => filter_var(request()->input('test_mode', true), FILTER_VALIDATE_BOOLEAN);

return [
    [
        'name'        => 'publishable_key',
        'label'       => 'Stripe 公钥',
        'section'     => 'Stripe 连接',
        'type'        => 'string',
        'required'    => true,
        'rules'       => [
            Rule::requiredIf($isEnabled), 'nullable', 'string', 'max:255',
            static function ($attribute, $value, $fail) use ($isEnabled, $isTest): void {
                if (! $isEnabled() || trim((string) $value) === '') {
                    return;
                }
                $prefix  = $isTest() ? 'pk_test_' : 'pk_live_';
                $pattern = '/^' . preg_quote($prefix, '/') . '[A-Za-z0-9_]{16,}$/';
                if (preg_match($pattern, trim((string) $value)) !== 1) {
                    $fail('Stripe 公钥与当前测试模式不匹配。');
                }
            },
        ],
        'description' => '从 Stripe 控制台复制 Publishable key；测试模式使用 pk_test_，正式模式使用 pk_live_。',
    ],
    [
        'name'        => 'secret_key',
        'label'       => 'Stripe 私钥',
        'section'     => 'Stripe 连接',
        'type'        => 'password',
        'required'    => true,
        'rules'       => [
            Rule::requiredIf($isEnabled), 'nullable', 'string', 'max:255',
            static function ($attribute, $value, $fail) use ($isEnabled, $isTest): void {
                if (! $isEnabled() || trim((string) $value) === '') {
                    return;
                }
                $prefix  = $isTest() ? 'sk_test_' : 'sk_live_';
                $pattern = '/^' . preg_quote($prefix, '/') . '[A-Za-z0-9_]{16,}$/';
                if (preg_match($pattern, trim((string) $value)) !== 1) {
                    $fail('Stripe 私钥与当前测试模式不匹配。');
                }
            },
        ],
        'description' => '只保存在服务器；测试模式使用 sk_test_，正式模式使用 sk_live_。已保存时留空不会被覆盖。',
    ],
    [
        'name'        => 'webhook_secret',
        'label'       => 'Webhook 签名密钥',
        'section'     => 'Stripe 连接',
        'type'        => 'password',
        'required'    => true,
        'rules'       => [Rule::requiredIf($isEnabled), 'nullable', 'string', 'max:255', 'regex:/^whsec_[A-Za-z0-9_]{16,}$/'],
        'description' => '启用插件时必须配置，值以 whsec_ 开头；已保存时留空不会被覆盖。',
    ],
    [
        'name'        => 'test_mode',
        'label'       => '测试模式',
        'section'     => '上线控制',
        'type'        => 'select',
        'options'     => [
            ['value' => '1', 'label' => '开启'],
            ['value' => '0', 'label' => '关闭'],
        ],
        'required'    => true,
        'rules'       => ['required', 'boolean'],
        'description' => '测试时使用 test 密钥；正式收款前切换为关闭并填写 live 密钥及对应 Webhook secret。',
    ],
];
