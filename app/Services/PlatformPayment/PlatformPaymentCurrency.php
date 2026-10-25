<?php

namespace App\Services\PlatformPayment;

/** Resolve one settlement amount that is safe for every hosted channel. */
final class PlatformPaymentCurrency
{
    private const SAFE_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF',
        'ILS', 'JPY', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'RUB', 'SEK', 'SGD',
        'THB', 'TWD', 'USD',
    ];

    public static function paymentCurrency(object $order): string
    {
        $currency = self::displayCurrency($order);

        return in_array($currency, self::SAFE_CURRENCIES, true) && self::hasLockedRate($order, $currency)
            ? $currency
            : 'USD';
    }

    public static function paymentAmount(object $order): float
    {
        $currency = self::paymentCurrency($order);
        $amount   = $currency === self::displayCurrency($order)
            ? (float) ($order->total ?? 0) * (float) ($order->currency_value ?? 1)
            : (float) ($order->total ?? 0);

        return round($amount, self::decimalPlaces($currency));
    }

    public static function decimalPlaces(string $currency): int
    {
        return in_array(strtoupper($currency), ['HUF', 'JPY', 'TWD'], true) ? 0 : 2;
    }

    private static function displayCurrency(object $order): string
    {
        $currency = strtoupper(trim((string) ($order->currency_code ?? '')));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'USD';
    }

    private static function hasLockedRate(object $order, string $currency): bool
    {
        if ($currency === 'USD') {
            return true;
        }

        $rate = $order->currency_value ?? null;

        return is_numeric($rate) && is_finite((float) $rate) && (float) $rate > 0;
    }
}
