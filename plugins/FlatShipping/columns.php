<?php

/**
 * Flat Shipping 字段
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-06-29 21:16:23
 * @modified   2022-06-29 21:16:23
 */
$shippingRulesValidator = static function (string $attribute, mixed $value, \Closure $fail): void {
    $rules = json_decode((string) $value, true);
    if (! is_array($rules) || ! array_is_list($rules) || count($rules) > 100) {
        $fail('Shipping rules must be a JSON list containing no more than 100 methods.');

        return;
    }

    $numericFields = [
        'value', 'handling_fee', 'min_order', 'max_order', 'min_weight', 'max_weight',
        'min_quantity', 'max_quantity', 'free_shipping_threshold', 'weight_initial',
        'weight_step', 'weight_rate', 'quantity_initial', 'quantity_step', 'quantity_rate',
    ];
    foreach ($rules as $index => $rule) {
        if (! is_array($rule)) {
            $fail('Every shipping method must be an object.');

            return;
        }
        $name = $rule['name'] ?? '';
        $code = $rule['code'] ?? '';
        $type = $rule['type'] ?? 'fixed';
        if (! is_scalar($name) || trim((string) $name) === '' || mb_strlen((string) $name) > 160
                               || ! is_scalar($code) || preg_match('/^[a-z0-9_-]{1,80}$/', (string) $code) !== 1
                               || ! in_array($type, ['fixed', 'percent', 'weight', 'quantity', 'free'], true)) {
            $fail('Shipping method #' . ($index + 1) . ' has an invalid name, code, or rate type.');

            return;
        }
        foreach ($numericFields as $field) {
            $number = $rule[$field] ?? 0;
            if (! is_numeric($number) || ! is_finite((float) $number) || (float) $number < 0) {
                $fail('Shipping method #' . ($index + 1) . ' contains an invalid non-negative number.');

                return;
            }
        }
        foreach ([['min_order', 'max_order'], ['min_weight', 'max_weight'], ['min_quantity', 'max_quantity']] as [$minimum, $maximum]) {
            $min = (float) ($rule[$minimum] ?? 0);
            $max = (float) ($rule[$maximum] ?? 0);
            if ($max > 0 && $max < $min) {
                $fail('Shipping method #' . ($index + 1) . ' has a maximum below its minimum.');

                return;
            }
        }
        if (($type === 'weight' && (float) ($rule['weight_step'] ?? 0) <= 0)
            || ($type === 'quantity' && (float) ($rule['quantity_step'] ?? 0) <= 0)) {
            $fail('Shipping method #' . ($index + 1) . ' requires a positive billing step.');

            return;
        }
    }
};

return [
    [
        'name'    => 'type', 'label_key' => 'common.rate_type', 'type' => 'select', 'value' => 'fixed', 'required' => true,
        'options' => [
            ['value' => 'fixed', 'label_key' => 'common.flat_shipping'],
            ['value' => 'percent', 'label_key' => 'common.percentage'],
            ['value' => 'weight', 'label_key' => 'common.weight_based'],
        ],
    ],
    ['name' => 'value', 'label_key' => 'common.shipping_value', 'description_key' => 'common.shipping_value_hint', 'type' => 'string', 'value' => '0', 'required' => true],
    ['name' => 'handling_fee', 'label_key' => 'common.handling_fee', 'description_key' => 'common.handling_fee_hint', 'type' => 'string', 'value' => '0', 'required' => false],
    ['name' => 'min_order', 'label_key' => 'common.min_order', 'description_key' => 'common.min_order_hint', 'type' => 'string', 'value' => '0', 'required' => false],
    ['name' => 'max_order', 'label_key' => 'common.max_order', 'description_key' => 'common.max_order_hint', 'type' => 'string', 'value' => '0', 'required' => false],
    ['name' => 'free_shipping_threshold', 'label_key' => 'common.free_shipping_threshold', 'description_key' => 'common.free_shipping_threshold_hint', 'type' => 'string', 'value' => '0', 'required' => false],
    ['name' => 'weight_initial', 'label_key' => 'common.weight_initial', 'description_key' => 'common.weight_initial_hint', 'type' => 'string', 'value' => '0', 'required' => false],
    ['name' => 'weight_step', 'label_key' => 'common.weight_step', 'description_key' => 'common.weight_step_hint', 'type' => 'string', 'value' => '1', 'required' => false],
    ['name' => 'weight_rate', 'label_key' => 'common.weight_rate', 'description_key' => 'common.weight_rate_hint', 'type' => 'string', 'value' => '0', 'required' => false],
    ['name' => 'country_codes', 'label_key' => 'common.country_codes', 'description_key' => 'common.country_codes_hint', 'type' => 'textarea', 'value' => '', 'required' => false],
    ['name' => 'zone_rules', 'label_key' => 'common.zone_rules', 'description_key' => 'common.zone_rules_hint', 'type' => 'textarea', 'value' => '[]', 'rules' => 'nullable|json', 'required' => false],
    ['name' => 'shipping_rules', 'label' => 'Shipping rules', 'type' => 'hidden', 'value' => '[]', 'rules' => ['required', 'json', 'max:200000', $shippingRulesValidator], 'required' => true],
];
