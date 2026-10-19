<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\FlatShipping\Bootstrap;
use ReflectionMethod;

class FlatShippingRuleCodeTest extends TestCase
{
    public function test_duplicate_rule_codes_are_assigned_before_eligibility_filtering(): void
    {
        $method = new ReflectionMethod(Bootstrap::class, 'assignRuleCodes');
        $items  = $method->invoke(new Bootstrap, [
            ['code' => 'pickup', 'countries' => ['US']],
            ['code' => 'pickup', 'countries' => ['CN']],
            ['code' => 'pickup', 'countries' => ['DE']],
        ]);

        self::assertSame(['pickup', 'pickup_2', 'pickup_3'], array_column($items, 'code'));
        // If the first rule is ineligible in a later address context, the
        // second remains pickup_2 rather than taking over pickup.
        self::assertSame('pickup_2', $items[1]['code']);
    }

    public function test_runtime_normalization_clamps_invalid_numeric_configuration(): void
    {
        $method = new ReflectionMethod(Bootstrap::class, 'normalizeRules');
        $rules  = $method->invoke(new Bootstrap, [[
            'code'          => ['invalid'],
            'name'          => ['invalid'],
            'type'          => 'unexpected',
            'value'         => 'INF',
            'handling_fee'  => -20,
            'weight_step'   => 'not-a-number',
            'quantity_rate' => 1.5e20,
        ]]);

        self::assertSame('method_1', $rules[0]['code']);
        self::assertSame('', $rules[0]['name']);
        self::assertSame('fixed', $rules[0]['type']);
        self::assertSame(0.0, $rules[0]['value']);
        self::assertSame(0.0, $rules[0]['handling_fee']);
        self::assertSame(0.0, $rules[0]['weight_step']);
        self::assertSame(1_000_000_000_000.0, $rules[0]['quantity_rate']);
    }

    public function test_admin_contract_rejects_invalid_shipping_rule_ranges(): void
    {
        $columns = require dirname(__DIR__, 2) . '/plugins/FlatShipping/columns.php';
        $column  = collect($columns)->firstWhere('name', 'shipping_rules');
        $custom  = collect($column['rules'])->first(fn ($rule) => $rule instanceof \Closure);
        $message = null;

        $custom('shipping_rules', json_encode([[
            'code'      => 'standard',
            'name'      => 'Standard',
            'type'      => 'fixed',
            'min_order' => 100,
            'max_order' => 50,
        ]]), function (string $error) use (&$message): void {
            $message = $error;
        });

        self::assertStringContainsString('maximum below its minimum', (string) $message);
    }
}
