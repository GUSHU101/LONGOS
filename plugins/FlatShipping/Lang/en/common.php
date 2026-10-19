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
    'way'                     => 'Way', 'flat_shipping' => 'Fixed fee', 'percentage' => 'Percentage of order', 'weight_based' => 'Weight tier', 'rate_type' => 'Rate type',
    'shipping_value'          => 'Base shipping value', 'shipping_value_hint' => 'Fixed amount, percentage, or the base fee for a weight-tier rule.',
    'handling_fee'            => 'Handling fee', 'handling_fee_hint' => 'Added after the rate calculation. Use 0 for none.',
    'min_order'               => 'Minimum order amount', 'min_order_hint' => 'Hide this rule below this subtotal. Use 0 for no limit.',
    'max_order'               => 'Maximum order amount', 'max_order_hint' => 'Hide this rule above this subtotal. Use 0 for no limit.',
    'free_shipping_threshold' => 'Free shipping threshold', 'free_shipping_threshold_hint' => 'Set the fee to zero at or above this subtotal. Use 0 to disable.',
    'weight_initial'          => 'Initial weight', 'weight_initial_hint' => 'Weight covered by the base fee, in the store weight unit.',
    'weight_step'             => 'Additional weight step', 'weight_step_hint' => 'Each started step above the initial weight adds the additional weight rate.',
    'weight_rate'             => 'Additional weight rate', 'weight_rate_hint' => 'Fee added for every started additional weight step.',
    'country_codes'           => 'Allowed country codes', 'country_codes_hint' => 'Optional ISO country codes separated by commas, for example US,CA. Leave empty for all countries.',
    'zone_rules'              => 'Regional rules (JSON)', 'zone_rules_hint' => 'Optional array. Example: [{"name":"US Express","countries":["US"],"type":"fixed","value":12},{"name":"EU weight","countries":"DE,FR","type":"weight","value":8,"weight_initial":1,"weight_step":0.5,"weight_rate":2}]. Fields may include zones, min_order, max_order, min_weight, max_weight, handling_fee and free_shipping_threshold.',
];
