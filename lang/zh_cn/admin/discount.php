<?php

return [
    'title'            => '折扣', 'description' => '创建自动优惠或客户可输入的折扣码。商品 ID 留空时规则适用于全店商品。', 'add' => '添加折扣',
    'name'             => '名称', 'mode' => '方式', 'type' => '折扣类型', 'rule' => '规则', 'status' => '状态', 'period' => '有效期', 'code' => '代码', 'empty' => '尚未创建折扣活动',
    'campaign_name'    => '活动名称', 'name_placeholder' => '例如：夏季全场九折', 'automatic' => '自动折扣', 'discount_code' => '折扣码',
    'product_amount'   => '产品金额减免', 'buy_x_get_y' => '买 X 送 Y', 'order_amount' => '订单金额减免', 'free_shipping' => '免运费',
    'value'            => '优惠值', 'percentage' => '百分比', 'fixed' => '固定金额', 'buy_get' => '购买 / 赠送', 'buy' => '买', 'get' => '送',
    'gift_product_ids' => '赠品商品 ID', 'gift_product_ids_help' => '多个商品 ID 请用英文逗号分隔；留空时赠送同范围商品中单价最低的商品。',
    'minimum_amount'   => '最低订单金额', 'minimum_amount_help' => '以店铺基准货币 USD 计算；0 表示无金额门槛。',
    'minimum_quantity' => '最低购买件数', 'minimum_quantity_help' => '0 表示无件数门槛。商品折扣只统计适用商品，订单与免邮折扣统计整单商品。', 'items' => '件',
    'product_ids'      => '适用商品 ID', 'product_ids_help' => '多个商品 ID 请用英文逗号分隔；留空为全店商品。', 'starts_at' => '开始时间', 'ends_at' => '结束时间', 'unlimited' => '不限制', 'name_required' => '请填写活动名称', 'code_required' => '请填写折扣码。', 'code_taken' => '该折扣码已被使用。',
    'js'               => [
        'enabled'                => '启用', 'disabled' => '停用', 'edit' => '编辑折扣', 'add' => '添加折扣', 'automatic' => '自动折扣', 'discount_code' => '折扣码',
        'types'                  => ['product_amount' => '产品金额减免', 'buy_x_get_y' => '买 X 送 Y', 'order_amount' => '订单金额减免', 'free_shipping' => '免运费'],
        'type_descriptions'      => ['product_amount' => '为适用商品提供金额或百分比减免。', 'buy_x_get_y' => '达到适用商品购买数量后赠送商品。', 'order_amount' => '按订单商品总额提供金额或百分比减免。', 'free_shipping' => '同时达到设置门槛后免除当前配送费用。'],
        'minimum_amount_summary' => '满 :amount', 'minimum_quantity_summary' => '满 :quantity 件', 'and' => '且', 'threshold_separator' => ' · ', 'buy_get_summary' => '买 :buy 送 :get', 'free_shipping' => '免运费', 'value_summary' => '优惠 :value',
        'now'                    => '立即', 'to' => '至', 'long_term' => '长期', 'delete_confirm' => '删除后该折扣将不能再被使用，是否继续？',
    ],
];
