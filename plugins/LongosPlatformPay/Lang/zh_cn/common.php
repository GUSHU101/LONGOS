<?php

return [
    'secure_title' => '由 LONGOS 主站安全收款',
    'secure_help'  => '将前往主站选择 PayPal 或银行卡；付款结果验证完成后自动返回本店。',
    'continue'     => '前往安全支付',
    'connecting'   => '正在创建安全支付会话…',
    'failed'       => '暂时无法连接主站支付，请稍后重试。',
    'admin'        => [
        'one_click'              => '一键启用',
        'platform_collection'    => '平台代收',
        'tenant_guide'           => '启用后，顾客会从子站订单跳转到 LONGOS 主站的安全支付页。PayPal/Stripe 密钥不会复制到当前子站，支付成功后主站会验证金额、币种、交易环境和订单归属，再自动回写当前订单。',
        'main_collection'        => '主站统一收款',
        'workflow'               => '工作方式',
        'main_guide'             => '先在 PayPal、Stripe 插件中保存主账号凭据，再选择要共享给子站的渠道。所有供应商创建、回调和返回地址均使用主站域名；子站不会读取或保存密钥。请先确认您的 PayPal/Stripe 账户协议允许平台代收。Stripe 平台 Webhook 地址：<code>:url</code>',
        'channels'               => '共享渠道',
        'share_paypal'           => '向子站提供 PayPal',
        'share_paypal_help'      => '使用主站 PayPal 插件中当前环境的 REST API 账号。',
        'share_stripe'           => '向子站提供 Stripe',
        'share_stripe_help'      => '使用主站 Stripe 插件中当前测试/正式环境的账号；平台回调使用下方独立签名密钥。',
        'stripe_callback'        => 'Stripe 回调',
        'stripe_webhook_secret'  => '平台 Webhook 签名密钥',
        'stripe_webhook_help'    => '在 Stripe 中为上方平台 Webhook 地址单独创建端点，再粘贴该端点的 whsec_ 签名密钥。不要复用子站或普通 Stripe 插件端点的签名密钥。',
    ],
];
