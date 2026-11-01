<?php

namespace App\Services;

use Beike\Models\OrderPayment;

final class OrderPaymentSummary
{
    /**
     * Return the small, non-sensitive payment snapshot used by order admin.
     * Raw gateway payloads remain available behind the technical details
     * disclosure and are never interpreted as trusted display text.
     *
     * @return array{
     *   state:string,
     *   provider_status:string,
     *   stage:string,
     *   reference:string,
     *   transaction_id:string,
     *   updated_at:mixed
     * }
     */
    public static function make(?OrderPayment $payment, string $orderStatus): array
    {
        if (! $payment) {
            return self::result('not_started');
        }

        $request  = self::decode($payment->request);
        $response = self::decode($payment->response);
        $callback = self::decode($payment->callback);

        $stage          = self::text($request['stage'] ?? '');
        $result         = strtolower(self::text($request['result'] ?? ''));
        $reference      = self::text($request['reference'] ?? '');
        $providerStatus = strtoupper(self::text(
            $callback['payment']['status']
            ?? $callback['status']
            ?? $response['status']
            ?? $response['PAYMENTINFO_0_PAYMENTSTATUS']
            ?? ''
        ));

        if (in_array($orderStatus, ['paid', 'shipped', 'completed', 'refunding'], true)
            || in_array($providerStatus, ['COMPLETED', 'SUCCEEDED', 'PAID'], true)) {
            $state = 'completed';
        } elseif ($result === 'failed') {
            $state = 'failed';
        } elseif (in_array($providerStatus, ['FAILED', 'DECLINED', 'CANCELLED', 'CANCELED', 'EXPIRED'], true)) {
            $state = 'failed';
        } elseif ($orderStatus === 'cancelled') {
            $state = 'cancelled';
        } else {
            $state = 'pending';
        }

        return self::result(
            $state,
            $providerStatus,
            $stage,
            $state === 'completed' ? '' : $reference,
            self::text($payment->transaction_id),
            $payment->updated_at,
        );
    }

    /** @return array<string, mixed> */
    private static function result(
        string $state,
        string $providerStatus = '',
        string $stage = '',
        string $reference = '',
        string $transactionId = '',
        mixed $updatedAt = null,
    ): array {
        return [
            'state'           => $state,
            'provider_status' => $providerStatus,
            'stage'           => $stage,
            'reference'       => $reference,
            'transaction_id'  => $transactionId,
            'updated_at'      => $updatedAt,
        ];
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
