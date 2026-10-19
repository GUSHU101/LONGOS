<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\Paypal\Services\PaypalCurrency;
use Plugin\Stripe\Services\StripeCurrency;

class PaymentCurrencyCompatibilityTest extends TestCase
{
    public function test_paypal_uses_the_frozen_local_currency_only_when_it_is_safe(): void
    {
        $eur = $this->order('EUR', 0.91, 19.99);
        self::assertSame('EUR', PaypalCurrency::paymentCurrency($eur));
        self::assertSame(18.19, PaypalCurrency::paymentAmount($eur));

        $jpy = $this->order('JPY', 154.25, 19.99);
        self::assertSame('JPY', PaypalCurrency::paymentCurrency($jpy));
        self::assertSame(3083.0, PaypalCurrency::paymentAmount($jpy));

        foreach (['VND', 'CNY', 'MYR'] as $currency) {
            $order = $this->order($currency, 100, 19.99);
            self::assertSame('USD', PaypalCurrency::paymentCurrency($order));
            self::assertSame(19.99, PaypalCurrency::paymentAmount($order));
            self::assertTrue(PaypalCurrency::usesFallback($order));
        }

        foreach (['HUF', 'TWD'] as $currency) {
            $order = $this->order($currency, 100.49, 19.99);
            self::assertSame($currency, PaypalCurrency::paymentCurrency($order));
            self::assertSame(2009.0, PaypalCurrency::paymentAmount($order));
        }

        self::assertSame('RUB', PaypalCurrency::paymentCurrency($this->order('RUB', 80, 19.99)));
    }

    public function test_payment_falls_back_to_base_usd_when_an_order_has_no_valid_frozen_rate(): void
    {
        foreach ([null, '', 0, -1, INF] as $rate) {
            $order = $this->order('EUR', $rate, 12.34);
            self::assertSame('USD', PaypalCurrency::paymentCurrency($order));
            self::assertSame('USD', StripeCurrency::paymentCurrency($order));
            self::assertSame(1234, StripeCurrency::paymentAmount($order));
        }
    }

    public function test_stripe_uses_supported_minor_units_and_falls_back_for_unsupported_codes(): void
    {
        $vnd = $this->order('VND', 25000, 19.99);
        self::assertSame('VND', StripeCurrency::paymentCurrency($vnd));
        self::assertSame(499750, StripeCurrency::paymentAmount($vnd));

        $eur = $this->order('EUR', 0.91, 19.99);
        self::assertSame('EUR', StripeCurrency::paymentCurrency($eur));
        self::assertSame(1819, StripeCurrency::paymentAmount($eur));

        foreach (['BHD', 'IQD', 'IRR', 'ISK', 'OMR'] as $currency) {
            $order = $this->order($currency, 1.5, 19.99);
            self::assertSame('USD', StripeCurrency::paymentCurrency($order));
            self::assertSame(1999, StripeCurrency::paymentAmount($order));
            self::assertTrue(StripeCurrency::usesFallback($order));
        }

        self::assertSame('RUB', StripeCurrency::paymentCurrency($this->order('RUB', 80, 19.99)));

        $huf = $this->order('HUF', 360.25, 19.99);
        self::assertSame('HUF', StripeCurrency::paymentCurrency($huf));
        self::assertSame(720140, StripeCurrency::paymentAmount($huf));
    }

    private function order(string $currency, mixed $rate, float $total): object
    {
        return (object) [
            'currency_code'  => $currency,
            'currency_value' => $rate,
            'total'          => $total,
        ];
    }
}
