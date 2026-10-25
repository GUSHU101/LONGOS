<?php

return [
    [
        'name'        => 'browser_tracking',
        'label'       => '启用浏览器像素',
        'description' => '加载 Meta Pixel、TikTok Pixel 和 Snap Pixel，记录店铺浏览与漏斗事件。',
        'type'        => 'bool',
        'value'       => '1',
        'required'    => false,
    ],
    [
        'name'        => 'server_tracking',
        'label'       => '启用服务端追踪',
        'description' => '由本网站 Laravel 服务器直接调用 Meta Conversions API、TikTok Events API 与 Snapchat Conversions API v3。',
        'type'        => 'bool',
        'value'       => '1',
        'required'    => false,
    ],
    [
        'name'        => 'require_consent',
        'label'       => '要求访客同意后才追踪',
        'description' => '启用后，只有 consent_cookie 指定的 Cookie 值为 1、true 或 yes 时才发送浏览器和服务端事件。请与您的 Cookie 同意横幅配合使用。',
        'type'        => 'bool',
        'value'       => '0',
        'required'    => false,
    ],
    [
        'name'        => 'consent_cookie',
        'label'       => '同意 Cookie 名称',
        'description' => '默认 tracking_consent。',
        'type'        => 'string',
        'value'       => 'tracking_consent',
        'rules'       => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
        'required'    => false,
    ],
    [
        'name'        => 'meta_pixels',
        'label'       => 'Meta/Facebook 像素配置（JSON）',
        'description' => '数组格式：[ {"pixel_id":"123","access_token":"EA...","api_version":"v26.0","test_event_code":"TEST..."} ]。每个渠道最多 10 个像素；浏览器端只公开 pixel_id。',
        'type'        => 'textarea',
        'value'       => '[]',
        'rules'       => ['nullable', 'json', function ($attribute, $value, $fail) {
            if ($message = \Plugin\PixelTracking\Services\PixelProfiles::validationError($value, 'meta')) {
                $fail($message);
            }
        }],
        'required'    => false,
    ],
    [
        'name'        => 'tiktok_pixels',
        'label'       => 'TikTok 像素配置（JSON）',
        'description' => '数组格式：[ {"pixel_code":"C...","access_token":"..."} ]。每个渠道最多 10 个像素；浏览器端只公开 pixel_code。',
        'type'        => 'textarea',
        'value'       => '[]',
        'rules'       => ['nullable', 'json', function ($attribute, $value, $fail) {
            if ($message = \Plugin\PixelTracking\Services\PixelProfiles::validationError($value, 'tiktok')) {
                $fail($message);
            }
        }],
        'required'    => false,
    ],
    [
        'name'        => 'snapchat_pixels',
        'label'       => 'Snapchat 像素配置（JSON）',
        'description' => '数组格式：[ {"pixel_id":"xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx","access_token":"..."} ]。访问令牌用于 Conversions API v3，浏览器端只公开 pixel_id。',
        'type'        => 'textarea',
        'value'       => '[]',
        'rules'       => ['nullable', 'json', function ($attribute, $value, $fail) {
            if ($message = \Plugin\PixelTracking\Services\PixelProfiles::validationError($value, 'snapchat')) {
                $fail($message);
            }
        }],
        'required' => false,
    ],
    [
        'name'        => 'advanced_matching',
        'label'       => '高级匹配',
        'description' => '在服务器端对订单/已登录客户的邮箱和电话作 SHA-256 后发送；请确认已获得适用的用户同意。',
        'type'        => 'bool',
        'value'       => '0',
        'required'    => false,
    ],
    [
        'name'        => 'debug_log',
        'label'       => '记录调试日志',
        'description' => '仅记录渠道、事件名称、事件 ID 和响应状态，不记录访问令牌或明文个人信息。',
        'type'        => 'bool',
        'value'       => '0',
        'required'    => false,
    ],
];
