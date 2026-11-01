<?php

namespace Tests\Unit;

use App\Services\OrderPaymentSummary;
use Beike\Models\OrderPayment;
use PHPUnit\Framework\TestCase;

class OrderPaymentSummaryTest extends TestCase
{
    public function test_it_distinguishes_unstarted_pending_failed_and_completed_attempts(): void
    {
        self::assertSame('not_started', OrderPaymentSummary::make(null, 'unpaid')['state']);

        $pending = $this->payment([
            'transaction_id' => '5O190127TN364715T',
            'request'        => json_encode(['stage' => 'create'], JSON_THROW_ON_ERROR),
            'response'       => json_encode(['status' => 'CREATED'], JSON_THROW_ON_ERROR),
        ]);
        $pendingSummary = OrderPaymentSummary::make($pending, 'unpaid');
        self::assertSame('pending', $pendingSummary['state']);
        self::assertSame('CREATED', $pendingSummary['provider_status']);
        self::assertSame('create', $pendingSummary['stage']);
        self::assertSame('5O190127TN364715T', $pendingSummary['transaction_id']);

        $failed = $this->payment([
            'request'  => json_encode([
                'stage'     => 'capture',
                'result'    => 'failed',
                'reference' => 'PP-0123456789AB',
            ], JSON_THROW_ON_ERROR),
            'response' => json_encode(['status' => 'CREATED'], JSON_THROW_ON_ERROR),
        ]);
        $failedSummary = OrderPaymentSummary::make($failed, 'unpaid');
        self::assertSame('failed', $failedSummary['state']);
        self::assertSame('PP-0123456789AB', $failedSummary['reference']);
        self::assertSame('completed', OrderPaymentSummary::make($failed, 'paid')['state']);

        $completed = $this->payment([
            'transaction_id' => '3C679366HH908993F',
            'response'       => json_encode(['status' => 'COMPLETED'], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame('completed', OrderPaymentSummary::make($completed, 'unpaid')['state']);
        self::assertSame('completed', OrderPaymentSummary::make($pending, 'paid')['state']);
        self::assertSame('cancelled', OrderPaymentSummary::make($pending, 'cancelled')['state']);
    }

    public function test_it_does_not_expose_arbitrary_gateway_payload_fields(): void
    {
        $payment = $this->payment([
            'request'  => json_encode(['stage' => 'create', 'client_secret' => 'private'], JSON_THROW_ON_ERROR),
            'response' => json_encode([
                'status' => 'CREATED',
                'payer'  => ['email_address' => 'buyer@example.com'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $encoded = json_encode(OrderPaymentSummary::make($payment, 'unpaid'), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('private', $encoded);
        self::assertStringNotContainsString('buyer@example.com', $encoded);
    }

    /** @param array<string, mixed> $attributes */
    private function payment(array $attributes): OrderPayment
    {
        $payment = (new \ReflectionClass(OrderPayment::class))->newInstanceWithoutConstructor();
        $payment->setRawAttributes($attributes, true);

        return $payment;
    }
}
