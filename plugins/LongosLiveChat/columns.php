<?php

return [
    [
        'name'        => 'welcome_message', 'label' => '欢迎语', 'section' => '基础功能',
        'description' => '访客第一次打开客服窗口时显示。', 'type' => 'textarea',
        'value'       => 'Hello! Leave us a message and we will get back to you shortly.', 'required' => false,
    ],
    [
        'name'        => 'poll_seconds', 'label' => '消息刷新间隔', 'section' => '基础功能',
        'description' => '单位秒，建议 4–8 秒。网络异常时会自动降低刷新频率。', 'type' => 'number',
        'value'       => '5', 'min' => 3, 'max' => 20, 'required' => false,
    ],
    [
        'name'        => 'background_poll_seconds', 'label' => '后台消息检查间隔', 'section' => '基础功能',
        'description' => '客户收起聊天窗口后检查新回复的间隔，单位秒。', 'type' => 'number',
        'value'       => '30', 'min' => 15, 'max' => 120, 'required' => false,
    ],
    [
        'name'        => 'retention_days', 'label' => '聊天记录保留天数', 'section' => '基础功能',
        'description' => '默认 1 天。系统按最后消息时间判定，分批清理超期且没有新消息的整个会话。', 'type' => 'number',
        'value'       => '1', 'min' => 1, 'max' => 3650, 'required' => false,
    ],
    [
        'name'        => 'bubble_position', 'label' => '客服入口位置', 'section' => '前台展示',
        'description' => '选择客服按钮固定显示的屏幕角落。', 'type' => 'select', 'value' => 'bottom_right', 'required' => false,
        'options'     => [
            ['value' => 'bottom_right', 'label' => '右下角'], ['value' => 'bottom_left', 'label' => '左下角'],
            ['value' => 'top_right', 'label' => '右上角'], ['value' => 'top_left', 'label' => '左上角'],
        ],
    ],
    [
        'name'        => 'bubble_offset_x', 'label' => '距侧边', 'section' => '前台展示',
        'description' => '单位 px，建议 16–32。', 'type' => 'number', 'value' => '20', 'min' => 0, 'max' => 96, 'required' => false,
    ],
    [
        'name'        => 'bubble_offset_y', 'label' => '距底部 / 顶部', 'section' => '前台展示',
        'description' => '单位 px，建议 16–32。', 'type' => 'number', 'value' => '20', 'min' => 0, 'max' => 96, 'required' => false,
    ],
    [
        'name'        => 'bubble_button_size', 'label' => '按钮尺寸', 'section' => '前台展示',
        'description' => '单位 px。', 'type' => 'number', 'value' => '56', 'min' => 44, 'max' => 72, 'required' => false,
    ],
    [
        'name'        => 'panel_width', 'label' => '聊天窗口宽度', 'section' => '前台展示',
        'description' => '单位 px，窄屏会自动适配。', 'type' => 'number', 'value' => '380', 'min' => 300, 'max' => 480, 'required' => false,
    ],
    [
        'name'        => 'panel_height', 'label' => '聊天窗口高度', 'section' => '前台展示',
        'description' => '单位 px，超过可视高度时自动收缩。', 'type' => 'number', 'value' => '570', 'min' => 360, 'max' => 760, 'required' => false,
    ],
];
