<?php

/**
 * order.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-02 14:22:41
 * @modified   2022-08-02 14:22:41
 */

return [
    // Heading
    'heading_title'                => '新手引导',

    // Tab
    'tab_basic'                    => '基本设置',
    'tab_language'                 => '多语言与货币',
    'tab_product'                  => '创建商品',
    'tab_theme'                    => '店铺装修',
    'tab_payment_shipping'         => '收款与物流',
    'tab_mail'                     => '配置邮件',

    // Text
    'text_extension'               => '扩展',
    'text_success'                 => '成功：新手引导已修改！',
    'text_edit'                    => '编辑新手引导',
    'text_view'                    => '显示详细...',
    'text_greeting'                => '恭喜，LONGOS 已成功安装！',
    'text_greeting_1'              => '接下来将引导您完成平台基础配置，快速创建并运营第一个站点。',
    'text_basic_1'                 => '首先，您可在系统设置中配置以下重要信息：',
    'text_language_1'              => 'LONGOS 支持多语言与多货币。创建第一个商品前，可先设置商城默认语言和货币。',
    'text_language_2'              => '如果您只需要使用一种语言和货币，可以将其他语言和货币删除。避免创建商品时，输入多种语言信息的麻烦。',
    'text_product_1'               => '在系统安装时，会自动导入一些默认商品数据做演示使用。您可以先尝试 <a href="' . admin_route('products.create') . '">创建商品</a>！',
    'text_product_2'               => 'LONGOS 提供完整的商品管理能力，包括：<a href="' . admin_route('categories.index') . '">商品分类</a>、<a href="' . admin_route('brands.index') . '">品牌管理</a>、多规格商品、<a href="' . admin_route('multi_filter.index') . '">高级筛选</a>和<a href="' . admin_route('attributes.index') . '">商品属性</a>。',
    'text_theme_1'                 => '系统默认安装了一套默认主题模板，如果默认主题不满足您的需求，也可以在<a href="' . admin_route('marketing.index', ['type' => 'theme']) . '">插件市场</a>选购其他模版主题。',
    'text_theme_2'                 => '此外，前台的主题模板的首页是由模块通过布局呈现的，您可能需要通过布局调整一些模块的设置。',
    'text_theme_3'                 => '使用<a href="' . admin_route('design.index') . '">LONGOS编辑器</a>自定义您的主题。',
    'text_payment_1'               => 'LONGOS 支持 PayPal、Stripe 等常用收款渠道。正式开放下单前，请启用并配置需要的收款方式。',
    'text_payment_2'               => '注意：某些支付接口申请审核时间较长，请提前申请。在国内使用的支付方式可能会要求网站域名备案。',
    'text_payment_3'               => '此外，您还需要设置物流配送方式供顾客选择。系统免费提供了固定运费插件。',
    'text_payment_4'               => '您还可以前往<a href="' . admin_route('marketing.index') . '">“插件市场”</a>了解更多收款和物流扩展。',
    'text_mail_1'                  => '邮件通知可以让您的客户及时了解订单状态，同时也可通过邮件注册和找回密码。',
    'text_mail_2'                  => '您可按实际业务需求配置 SMTP，Sendmail 等邮件引擎用于发送邮件。',

    // Button
    'button_setting_general'       => '网站基础设置',
    'button_setting_store'         => '网站名称',
    'button_setting_logo'          => '更换 Logo',
    'button_setting_option'        => '选项设置',
    'button_setting'               => '所有系统设置',
    'button_language'              => '语言管理',
    'button_currency'              => '货币管理',
    'button_product'               => '查看商品',
    'button_product_create'        => '创建商品',
    'button_theme_pc'              => '模板设置',
    'button_theme_h5'              => '手机主题设置',
    'button_theme'                 => '所有主题',
    'button_layout'                => '布局管理',
    'button_payment'               => '支付方式',
    'button_shipping'              => '配送方式',
    'button_mail'                  => '邮件设置',
    'button_sms'                   => '短信配置',
    'button_hide'                  => '不再显示',

    // Error
    'error_permission'             => '错误：您没有权限修改新手引导！',
];
