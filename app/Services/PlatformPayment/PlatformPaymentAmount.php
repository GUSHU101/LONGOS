<?php

namespace App\Services\PlatformPayment;

use RuntimeException;

final class PlatformPaymentAmount
{
    public static function format(string $amount, int $decimals): string
    {
        if ($decimals < 0 || $decimals > 3 || preg_match('/^\d+(?:\.(\d+))?$/', trim($amount), $matches) !== 1) {
            throw new RuntimeException('支付金额格式无效。');
        }

        [$whole, $fraction] = array_pad(explode('.', trim($amount), 2), 2, '');
        $excess             = substr($fraction, $decimals);
        if ($excess !== '' && trim($excess, '0') !== '') {
            throw new RuntimeException('支付金额精度超出币种限制。');
        }

        $whole = ltrim($whole, '0') ?: '0';
        if ($decimals === 0) {
            return $whole;
        }

        return $whole . '.' . str_pad(substr($fraction, 0, $decimals), $decimals, '0');
    }

    public static function minorUnits(string $amount, int $decimals): int
    {
        $digits = ltrim(str_replace('.', '', self::format($amount, $decimals)), '0') ?: '0';
        $max    = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new RuntimeException('支付金额超出处理范围。');
        }

        $minor = (int) $digits;
        if ($minor < 1) {
            throw new RuntimeException('支付金额必须大于零。');
        }

        return $minor;
    }
}
