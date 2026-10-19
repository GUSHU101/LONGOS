<?php

namespace Tests\Unit;

use Beike\Services\ShippingMethodService;
use PHPUnit\Framework\TestCase;

class ShippingMethodContractTest extends TestCase
{
    public function test_required_quote_fields_must_belong_to_the_selected_quote_and_be_non_empty(): void
    {
        $quote = [
            'code'            => 'flat_shipping.pickup',
            'required_fields' => ['pickup_point', 'delivery_date'],
        ];

        self::assertFalse(ShippingMethodService::quoteDataIsComplete($quote, []));
        self::assertFalse(ShippingMethodService::quoteDataIsComplete($quote, [
            'code'   => 'flat_shipping.other',
            'fields' => ['pickup_point' => 'A', 'delivery_date' => '2026-10-20'],
        ]));
        self::assertFalse(ShippingMethodService::quoteDataIsComplete($quote, [
            'code'   => 'flat_shipping.pickup',
            'fields' => ['pickup_point' => 'A', 'delivery_date' => '  '],
        ]));
        self::assertTrue(ShippingMethodService::quoteDataIsComplete($quote, [
            'code'   => 'flat_shipping.pickup',
            'fields' => ['pickup_point' => 'A', 'delivery_date' => '2026-10-20'],
        ]));
    }

    public function test_quotes_without_required_fields_remain_backward_compatible(): void
    {
        self::assertTrue(ShippingMethodService::quoteDataIsComplete([
            'code' => 'flat_shipping.standard',
        ], []));
    }

    public function test_only_fields_rendered_by_the_selected_quote_are_persisted(): void
    {
        $filtered = ShippingMethodService::filterQuoteData([
            'code'        => 'flat_shipping.pickup',
            'field_names' => ['pickup_point', 'instructions'],
        ], [
            'code'   => 'forged.code',
            'fields' => [
                'pickup_point' => 'A',
                'instructions' => 'Call on arrival',
                'admin_note'   => 'forged',
            ],
        ]);

        self::assertSame('flat_shipping.pickup', $filtered['code']);
        self::assertSame([
            'pickup_point' => 'A',
            'instructions' => 'Call on arrival',
        ], $filtered['fields']);
    }
}
