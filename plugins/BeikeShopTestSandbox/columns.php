<?php

/**
 * Settings rendered by BeikeShop's native plugin configuration page.
 * The stored values are available through plugin_setting().
 */
return [
    [
        'name'        => 'card_input_mode',
        'section'     => 'Checkout mode',
        'label'       => 'Card input mode',
        'type'        => 'select',
        'required'    => true,
        'rules'       => 'required|in:whitelist,open',
        'description' => 'Whitelist mode accepts only the built-in synthetic TEST cards. Open mode accepts any 12–19 digit card number and sends eligible BINs through BinSearchLookup. This only controls the sandbox checkout; it never enables live card charging.',
        'options'     => [
            ['value' => 'whitelist', 'label' => 'Whitelist mode — built-in TEST cards only'],
            ['value' => 'open', 'label' => 'Open mode — any valid-length card number'],
        ],
    ],
    [
        'name'        => 'bin_lookup_enabled',
        'section'     => 'BinSearchLookup API',
        'label'       => 'Enable BIN intelligence',
        'type'        => 'bool',
        'required'    => false,
        'description' => 'When enabled, eligible checkout card numbers are validated and enriched through BinSearchLookup. The storefront never displays provider results.',
    ],
    [
        'name'        => 'bin_api_base_url',
        'section'     => 'BinSearchLookup API',
        'label'       => 'API base URL',
        'type'        => 'string',
        'required'    => false,
        'rules'       => 'nullable|url|max:255',
        'placeholder' => 'https://api.binsearchlookup.com',
        'description' => 'Leave blank to use the official BinSearchLookup endpoint.',
    ],
    [
        'name'        => 'bin_user_id',
        'section'     => 'BinSearchLookup API',
        'label'       => 'User ID',
        'type'        => 'string',
        'required'    => false,
        'rules'       => 'nullable|string|max:255',
        'description' => 'Your BinSearchLookup account user identifier.',
    ],
    [
        'name'        => 'bin_api_key',
        'section'     => 'BinSearchLookup API',
        'label'       => 'API key',
        'type'        => 'password',
        'required'    => false,
        'rules'       => 'nullable|string|max:500',
        'description' => 'Stored as a secret. It is never displayed again after saving; leave blank when editing to keep the current key.',
    ],
    [
        'name'        => 'telegram_enabled',
        'section'     => 'Telegram 新订单通知',
        'label'       => '启用 Telegram 新订单提醒',
        'type'        => 'bool',
        'required'    => false,
        'description' => '开启后，每一笔新建的 TEST 沙盒订单都会发送提醒。通知失败不会影响下单、3DS 弹窗或后台人工审核。',
    ],
    [
        'name'        => 'telegram_bot_token',
        'section'     => 'Telegram 新订单通知',
        'label'       => '机器人 Bot Token',
        'type'        => 'password',
        'required'    => false,
        'rules'       => 'nullable|string|max:255',
        'placeholder' => '由 @BotFather 提供的 Token',
        'description' => '在 Telegram 的 @BotFather 中创建机器人后取得。该值会以密钥方式保存，编辑其他配置时留空即可保留原 Token。',
    ],
    [
        'name'            => 'telegram_discover_chats',
        'section'         => 'Telegram 新订单通知',
        'label'           => '自动识别可用 Chat ID',
        'type'            => 'action',
        'required'        => false,
        'action_url'      => '/api/beikeshop-test/admin/telegram/discover-chats',
        'action_label'    => '识别 Chat ID',
        'action_feedback' => 'telegram-discover-chats-feedback',
        'description'     => '先保存 Bot Token；再向机器人发送 /start，或在已加入机器人的群组/频道中发送一条消息。点击后会在下方显示可复制的 Chat ID，Token 不会出现在浏览器地址栏。',
    ],
    [
        'name'        => 'telegram_chat_id',
        'section'     => 'Telegram 新订单通知',
        'label'       => '接收方 Chat ID',
        'type'        => 'string',
        'required'    => false,
        'rules'       => 'nullable|string|max:1000',
        'placeholder' => '-1001234567890, @your_channel',
        'description' => '填写私聊、群组或频道的 Chat ID。群组/超级群通常以负数或 -100 开头；频道也可以填写 @频道用户名。支持使用英文逗号、分号或空格填写多个接收方；请先将机器人加入目标位置。',
    ],
    [
        'name'        => 'telegram_product_name',
        'section'     => 'Telegram 新订单通知',
        'label'       => '通知中的商品名称',
        'type'        => 'string',
        'required'    => false,
        'rules'       => 'nullable|string|max:120',
        'value'       => '招牌套餐',
        'description' => '用于新订单通知的 {{product_name}} 变量。默认“招牌套餐”。',
    ],
    [
        'name'        => 'telegram_message_template',
        'section'     => 'Telegram 新订单通知',
        'label'       => '新订单消息模板',
        'type'        => 'textarea',
        'required'    => false,
        'rules'       => 'nullable|string|max:4096',
        'value'       => "🔔 店铺新订单\n订单号：{{order_id}}\n商品：{{product_name}}\n请及时处理。",
        'description' => '支持 {{order_id}}、{{product_name}}、{{created_at}} 三个变量；消息按纯文本发送，不解析 HTML/Markdown，避免格式符导致发送失败。留空会恢复默认模板。',
    ],
    [
        'name'            => 'telegram_test_notification',
        'section'         => 'Telegram 新订单通知',
        'label'           => '连接与发送测试',
        'type'            => 'action',
        'required'        => false,
        'action_url'      => '/api/beikeshop-test/admin/telegram/test',
        'action_label'    => '发送测试通知',
        'action_feedback' => 'telegram-test-notification-feedback',
        'description'     => '请先保存 Token 和 Chat ID，再点击测试。按钮会发送一条“Telegram 通知测试”消息；每分钟最多 3 次，不会创建订单。',
    ],
    [
        'name'        => 'telegram_setup_guide',
        'section'     => 'Telegram 机器人配置教程',
        'label'       => '从创建机器人到收到订单提醒',
        'type'        => 'guide',
        'required'    => false,
        'description' => <<<'HTML'
<ol class="mb-3 ps-3">
  <li class="mb-2">在 Telegram 搜索并打开 <a href="https://t.me/BotFather" target="_blank" rel="noopener noreferrer">@BotFather</a>，发送 <code>/newbot</code>，按提示填写机器人名称和用户名。</li>
  <li class="mb-2">BotFather 会返回一串 <strong>Bot Token</strong>。复制该 Token 到上方“机器人 Bot Token”字段。Token 等同于机器人密码，请勿发送给他人、截图或填写到不可信网站。</li>
  <li class="mb-2"><strong>私聊提醒：</strong>先在 Telegram 打开你的机器人并发送 <code>/start</code>。<br><strong>群组提醒：</strong>将机器人加入群组后，在群内发送一条消息。<br><strong>频道提醒：</strong>将机器人添加为频道管理员，并授予发布消息权限。</li>
  <li class="mb-2">获取 Chat ID：先保存 Token，再点击上方“识别 Chat ID”。系统会从机器人已收到的更新中列出私聊、群组或频道 ID；群组/超级群 ID 常见格式为 <code>-1001234567890</code>。频道若设置了公开用户名，也可填写 <code>@频道用户名</code>。</li>
  <li class="mb-2">填写完 Token 与 Chat ID 后，开启“启用 Telegram 新订单提醒”并保存。点击“发送测试通知”确认连接；成功后在店铺创建一笔新的 TEST 沙盒订单，即会收到新订单提醒。</li>
  <li class="mb-2">可选：修改“通知中的商品名称”或“新订单消息模板”。模板支持 <code>{{order_id}}</code>、<code>{{product_name}}</code>、<code>{{created_at}}</code>，不要删除你希望显示的变量。</li>
</ol>
<p class="mb-0"><strong>可靠性与安全：</strong>订单通知在结账响应结束后发送，不会拖慢 3DS 流程；网络和限流失败会自动重试，成功订单会去重；可填写多个接收方。服务器日志只记录成功数量、HTTP 状态和异常类型，不会记录 Token、验证码、卡号或完整消息内容。<br><strong>排查：</strong>未收到消息时，请确认机器人已在目标私聊/群组/频道中有发言权限，并使用“发送测试通知”检查连接。</p>
HTML,
    ],
];
