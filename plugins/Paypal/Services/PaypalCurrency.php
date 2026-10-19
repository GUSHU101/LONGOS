<?php

namespace Plugin\Paypal\Services;

/**
 * Keeps storefront presentation currencies separate from PayPal settlement.
 *
 * LONGOS stores every order total in base USD and freezes the visitor-facing
 * currency/rate on the order. PayPal accepts only a smaller currency set and
 * some currencies are account- or zero-decimal-specific. Those ambiguous
 * currencies settle in USD instead of allowing checkout to fail at PayPal.
 */
class PaypalCurrency
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

    public static function displayCurrency(object $order): string
    {
        $currency = strtoupper(trim((string) ($order->currency_code ?? '')));

        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    }

    public static function usesFallback(object $order): bool
    {
        return self::paymentCurrency($order) !== self::displayCurrency($order);
    }

    public static function paymentAmount(object $order): float
    {
        $paymentCurrency = self::paymentCurrency($order);
        if ($paymentCurrency === self::displayCurrency($order)) {
            return round(
                (float) ($order->total ?? 0) * (float) ($order->currency_value ?? 1),
                self::decimalPlaces($paymentCurrency)
            );
        }

        // The persisted order total is the authoritative base USD amount.
        return round((float) ($order->total ?? 0), 2);
    }

    public static function decimalPlaces(string $currency): int
    {
        return in_array(strtoupper($currency), ['HUF', 'JPY', 'TWD'], true) ? 0 : 2;
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
