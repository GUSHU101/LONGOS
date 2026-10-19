<?php

namespace Tests\Unit;

use Beike\Services\StorePolicyTemplateService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StorePolicyTemplateServiceTest extends TestCase
{
    public function test_it_generates_eight_complete_standard_documents(): void
    {
        $policies = StorePolicyTemplateService::generate($this->profile());

        self::assertSame(['refund', 'shipping', 'privacy', 'terms', 'contact', 'payment', 'cancellation', 'cookies'], array_keys($policies));
        self::assertSame('Refund Policy', $policies['refund']['title']);
        self::assertSame('Shipping Policy', $policies['shipping']['title']);
        self::assertSame('Privacy Policy', $policies['privacy']['title']);
        self::assertSame('Terms of Service', $policies['terms']['title']);
        self::assertSame('Contact Us', $policies['contact']['title']);
        self::assertSame('Payment Policy', $policies['payment']['title']);
        self::assertSame('Order Cancellation Policy', $policies['cancellation']['title']);
        self::assertSame('Cookie Policy', $policies['cookies']['title']);
        self::assertStringContainsString('30 calendar days', $policies['refund']['content']);
        self::assertStringContainsString('1–3 business days', $policies['shipping']['content']);
        self::assertStringContainsString('Your privacy rights', $policies['privacy']['content']);
        self::assertStringContainsString('Mandatory consumer guarantees', $policies['terms']['content']);
        self::assertStringContainsString('support@example.com', $policies['contact']['content']);
        self::assertStringContainsString('48 hours', $policies['contact']['content']);
        self::assertStringContainsString('methods currently available', $policies['payment']['content']);
        self::assertStringContainsString('within 2 hours', $policies['cancellation']['content']);
        self::assertStringContainsString('Necessary:', $policies['cookies']['content']);

        foreach ($policies as $policy) {
            self::assertTrue($policy['enabled']);
            self::assertStringNotContainsString('{{', $policy['content']);
            self::assertStringNotContainsString('[INSERT', $policy['content']);
        }
    }

    public function test_merchant_values_are_normalized_and_escaped_before_rendering(): void
    {
        $profile                     = $this->profile();
        $profile['business_name']    = '<b>North & Co</b>';
        $profile['business_address'] = '1 Main & Market <script>alert(1)</script>';

        $policies = StorePolicyTemplateService::generate($profile);
        $content  = $policies['privacy']['content'];

        self::assertStringContainsString('North &amp; Co', $content);
        self::assertStringContainsString('1 Main &amp; Market alert(1)', $content);
        self::assertStringNotContainsString('<script>', $content);
    }

    public function test_required_identity_and_day_ranges_are_rejected(): void
    {
        $profile                  = $this->profile();
        $profile['business_name'] = '';

        $this->expectException(InvalidArgumentException::class);
        StorePolicyTemplateService::generate($profile);
    }

    public function test_minimum_days_cannot_exceed_maximum_days(): void
    {
        $profile                        = $this->profile();
        $profile['processing_days_min'] = 8;
        $profile['processing_days_max'] = 2;

        $this->expectException(InvalidArgumentException::class);
        StorePolicyTemplateService::normalizeProfile($profile);
    }

    public function test_zero_hour_cancellation_window_uses_a_natural_fallback(): void
    {
        $profile                              = $this->profile();
        $profile['cancellation_window_hours'] = 0;

        $policy = StorePolicyTemplateService::generate($profile)['cancellation']['content'];

        self::assertStringContainsString('request cancellation before fulfillment begins', $policy);
        self::assertStringNotContainsString('within 0 hours', $policy);
    }

    public function test_invalid_contact_phone_is_rejected(): void
    {
        $profile                  = $this->profile();
        $profile['contact_phone'] = 'call-me<script>';

        $this->expectException(InvalidArgumentException::class);
        StorePolicyTemplateService::normalizeProfile($profile);
    }

    private function profile(): array
    {
        return [
            'business_name'             => 'LONGOS Demo LLC',
            'contact_email'             => 'support@example.com',
            'contact_phone'             => '+1 415 555 0123',
            'business_address'          => '100 Market Street, San Francisco, CA 94105, United States',
            'support_hours'             => 'Monday–Friday, 9:00 AM–5:00 PM UTC',
            'support_response_hours'    => 48,
            'jurisdiction'              => 'the laws of the State of California, United States',
            'effective_date'            => '2026-10-10',
            'cancellation_window_hours' => 2,
            'return_window_days'        => 30,
            'refund_processing_days'    => 10,
            'processing_days_min'       => 1,
            'processing_days_max'       => 3,
            'transit_days_min'          => 5,
            'transit_days_max'          => 10,
            'return_shipping'           => 'customer',
            'international_shipping'    => true,
            'duties_responsibility'     => 'customer',
            'data_sale'                 => 'no',
        ];
    }
}
