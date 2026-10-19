<?php

/**
 * dd.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-28 16:19:06
 * @modified   2022-07-28 16:19:06
 */

return [
    'way'                     => '方式', 'flat_shipping' => '固定金额', 'percentage' => '按订单金额百分比', 'weight_based' => '按重量阶梯', 'rate_type' => '计费方式',
    'shipping_value'          => '基础运费值', 'shipping_value_hint' => '固定金额、订单百分比或重量阶梯的起步费用。',
    'handling_fee'            => '处理费', 'handling_fee_hint' => '在运费计算完成后附加；填 0 表示不收取。',
    'min_order'               => '最低订单金额', 'min_order_hint' => '订单小计低于该金额时不显示此规则；填 0 不限制。',
    'max_order'               => '最高订单金额', 'max_order_hint' => '订单小计高于该金额时不显示此规则；填 0 不限制。',
    'free_shipping_threshold' => '免邮门槛', 'free_shipping_threshold_hint' => '订单小计达到该金额时运费为 0；填 0 关闭。',
    'weight_initial'          => '首重', 'weight_initial_hint' => '基础运费覆盖的重量，单位为商城设置的重量单位。',
    'weight_step'             => '续重步长', 'weight_step_hint' => '超过首重后，每开始一个步长都会增加续重费用。',
    'weight_rate'             => '续重费用', 'weight_rate_hint' => '每个续重步长增加的费用。',
    'country_codes'           => '允许国家代码', 'country_codes_hint' => '可选，使用逗号分隔 ISO 国家代码，例如 US,CA；留空表示全部国家。',
    'zone_rules'              => '地区规则（JSON）', 'zone_rules_hint' => '可选数组。示例：[{"name":"美国加急","countries":["US"],"type":"fixed","value":12},{"name":"欧洲重量","countries":"DE,FR","type":"weight","value":8,"weight_initial":1,"weight_step":0.5,"weight_rate":2}]。还可使用 zones、min_order、max_order、min_weight、max_weight、handling_fee、free_shipping_threshold。',
];
