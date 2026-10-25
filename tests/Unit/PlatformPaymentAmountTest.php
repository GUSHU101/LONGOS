<?php

namespace Tests\Unit;

use App\Services\PlatformPayment\PlatformPaymentAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PlatformPaymentAmountTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function test_amounts_are_converted_without_floating_point_math(string $amount, int $decimals, string $formatted, int $minor): void
    {
        self::assertSame($formatted, PlatformPaymentAmount::format($amount, $decimals));
        self::assertSame($minor, PlatformPaymentAmount::minorUnits($amount, $decimals));
    }

    public static function validAmounts(): array
    {
        return [
            'usd cents'      => ['19.99000000', 2, '19.99', 1999],
            'trailing zeros' => ['00000019.90000000', 2, '19.90', 1990],
            'jpy'            => ['154.00000000', 0, '154', 154],
            'kwd'            => ['12.34500000', 3, '12.345', 12345],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_or_lossy_amounts_are_rejected(string $amount, int $decimals): void
    {
        $this->expectException(RuntimeException::class);
        PlatformPaymentAmount::minorUnits($amount, $decimals);
    }

    public static function invalidAmounts(): array
    {
        return [
            'zero'               => ['0.00000000', 2],
            'negative'           => ['-1.00', 2],
            'scientific'         => ['1e3', 2],
            'too much precision' => ['1.00100000', 2],
            'invalid decimals'   => ['1.00', 4],
        ];
    }
}
