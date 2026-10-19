<?php

namespace Plugin\Stripe\Services;

use Beike\Services\CurrencyCatalog;

/** Resolve a Stripe-safe charge currency without changing storefront prices. */
class StripeCurrency
{
    private const SUPPORTED_CURRENCIES = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN',
        'BAM', 'BBD', 'BDT', 'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BWP',
        'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CVE',
        'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP',
        'GBP', 'GEL', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG',
        'HUF', 'IDR', 'ILS', 'INR', 'JMD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF',
        'KRW', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL',
        'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR',
        'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK',
        'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD',
        'SCR', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SZL', 'THB', 'TJS',
        'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS',
        'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW',
    ];

    public static function paymentCurrency(object $order): string
    {
        $currency = self::displayCurrency($order);

        // Stripe represents ISK with two decimals but requires the minor-unit
        // amount to end in 00. USD fallback preserves the exact order total.
        return in_array($currency, self::SUPPORTED_CURRENCIES, true) && self::hasLockedRate($order, $currency)
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

    public static function paymentAmount(object $order): int
    {
        $paymentCurrency = self::paymentCurrency($order);
        $decimalPlaces   = CurrencyCatalog::decimalPlaces($paymentCurrency);
        $amount          = $paymentCurrency === self::displayCurrency($order)
            ? round((float) ($order->total ?? 0) * (float) ($order->currency_value ?? 1), $decimalPlaces)
            : round((float) ($order->total ?? 0), 2);

        return (int) round($amount * (10 ** $decimalPlaces));
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
