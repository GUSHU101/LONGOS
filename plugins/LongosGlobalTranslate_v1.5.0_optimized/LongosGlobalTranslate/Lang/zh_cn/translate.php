<?php

return [
    'title'             => 'LONGOS 全球翻译',
    'preview'           => '翻译内容必须先扫描、审校并发布；没有经过明确的服务商验证与调度授权时，系统不会发起任何上游翻译请求。',
    'mode'              => '工作模式',
    'dispatch'          => '上游翻译请求',
    'disabled'          => '已关闭',
    'embed_description' => '为已发布店铺提供独立的展示语言选择器，不改变原有店铺语言、交易、价格或订单数据。',
    'choose_language'   => '选择展示语言',
    'presentation_only' => '仅改变已发布内容的展示文本，不改变订单、价格或结账。',
    'close'             => '关闭语言选择器',
    'recovery_badge'    => '正在安全修复',
    'recovery_title'    => '全球翻译工作区正在完成升级',
    'recovery_description' => '检测到翻译插件的数据结构尚未完整就绪。为保护店铺原文、订单与支付流程，翻译任务和前台浮窗已安全暂停。',
    'recovery_connection' => '暂时无法检查翻译数据结构。请确认本次 Coolify 部署已完成并且数据库连接正常，然后刷新此页面。',
    'recovery_runtime'    => '翻译工作区因一项历史配置需要恢复而被安全暂停。详细原因已记录在受保护的平台日志中，不会暴露给前台页面。',
    'recovery_missing'  => '仍需由自动升级补齐的项目：',
    'recovery_step_deploy' => '在 Coolify 使用 main 分支重新部署 LONGOS。',
    'recovery_step_wait' => '等待部署日志中的 saas:upgrade 与插件迁移完成。',
    'recovery_step_reload' => '重新打开此页面；无需在生产容器中手动执行迁移或 Composer。',
    'recovery_safety'   => '此状态不会修改核心语言、商品、购物车、结账、支付或订单数据。',
];
