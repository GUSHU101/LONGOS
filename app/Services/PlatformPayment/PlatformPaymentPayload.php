<?php

namespace App\Services\PlatformPayment;

final class PlatformPaymentPayload
{
    /**
     * Retain only reconciliation fields. Names, emails, addresses, funding
     * details, and credentials returned by a provider are deliberately dropped.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function paypal(array $result): array
    {
        $units = [];
        foreach ((array) ($result['purchase_units'] ?? []) as $unit) {
            if (! is_array($unit)) {
                continue;
            }

            $captures = [];
            foreach ((array) data_get($unit, 'payments.captures', []) as $capture) {
                if (! is_array($capture)) {
                    continue;
                }

                $captures[] = self::compact([
                    'id'            => self::scalar($capture['id'] ?? null),
                    'status'        => self::scalar($capture['status'] ?? null),
                    'amount'        => self::money($capture['amount'] ?? null),
                    'final_capture' => is_bool($capture['final_capture'] ?? null) ? $capture['final_capture'] : null,
                    'payee'         => self::payee($capture['payee'] ?? null),
                ]);
            }

            $units[] = self::compact([
                'reference_id' => self::scalar($unit['reference_id'] ?? null),
                'custom_id'    => self::scalar($unit['custom_id'] ?? null),
                'invoice_id'   => self::scalar($unit['invoice_id'] ?? null),
                'amount'       => self::money($unit['amount'] ?? null),
                'payee'        => self::payee($unit['payee'] ?? null),
                'payments'     => $captures === [] ? null : ['captures' => $captures],
            ]);
        }

        return self::compact([
            'id'             => self::scalar($result['id'] ?? null),
            'status'         => self::scalar($result['status'] ?? null),
            'purchase_units' => $units,
        ]);
    }

    /** @return array<string, string>|null */
    private static function money(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $money = self::compact([
            'currency_code' => self::scalar($value['currency_code'] ?? null),
            'value'         => self::scalar($value['value'] ?? null),
        ]);

        return $money === [] ? null : $money;
    }

    /** @return array<string, string>|null */
    private static function payee(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $merchantId = self::scalar($value['merchant_id'] ?? null);

        return $merchantId === null ? null : ['merchant_id' => $merchantId];
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private static function compact(array $values): array
    {
        return array_filter($values, static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
