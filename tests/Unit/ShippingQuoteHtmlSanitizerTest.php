<?php

namespace Tests\Unit;

use Beike\Services\ShippingQuoteHtmlSanitizer;
use PHPUnit\Framework\TestCase;

class ShippingQuoteHtmlSanitizerTest extends TestCase
{
    public function test_it_keeps_shipping_fields_but_removes_executable_markup(): void
    {
        $html = <<<'HTML'
<div class="pickup" onclick="steal()">
  <label for="pickup-point">Pickup point</label>
  <select id="pickup-point" name="pickup_point" data-provider="carrier" data-key="payment_method_code" required><option value="A">A</option></select>
  <input name="unsupported[]" required>
  <button data-submit-checkout data-shipping-quote="other">Fake checkout</button>
  <a href="javascript:alert(1)" target="_blank">Unsafe</a>
  <script>alert(document.cookie)</script>
</div>
HTML;

        $sanitizer = new ShippingQuoteHtmlSanitizer;
        $safe      = $sanitizer->sanitize($html, 'flat_shipping.pickup');

        self::assertStringContainsString('name="pickup_point"', $safe);
        self::assertStringContainsString('data-provider="carrier"', $safe);
        self::assertStringNotContainsString('data-key=', $safe);
        self::assertStringNotContainsString('data-submit-checkout', $safe);
        self::assertStringNotContainsString('data-shipping-quote=', $safe);
        self::assertStringNotContainsString('name="unsupported[]"', $safe);
        self::assertStringNotContainsString('onclick', $safe);
        self::assertStringNotContainsString('javascript:', $safe);
        self::assertStringNotContainsString('<script', $safe);
        self::assertStringNotContainsString('document.cookie', $safe);
        self::assertMatchesRegularExpression('/id="longos-shipping-[a-f0-9]{12}-pickup-point"/', $safe);
        self::assertMatchesRegularExpression('/for="longos-shipping-[a-f0-9]{12}-pickup-point"/', $safe);
        self::assertSame(['pickup_point'], $sanitizer->requiredFieldNames($safe));
        self::assertSame(['pickup_point'], $sanitizer->fieldNames($safe));
    }

    public function test_it_rehydrates_safe_carrier_fields_after_checkout_refresh(): void
    {
        $sanitizer = new ShippingQuoteHtmlSanitizer;
        $safe      = $sanitizer->sanitize(<<<'HTML'
<input name="delivery_date" type="date" required>
<textarea name="instructions"></textarea>
<select name="pickup_point"><option value="A">A</option><option value="B">B</option></select>
<input name="weekend" type="checkbox">
HTML, 'flat_shipping.pickup');

        $rehydrated = $sanitizer->applyFieldValues($safe, [
            'delivery_date' => '2026-10-20',
            'instructions'  => '<leave & call>',
            'pickup_point'  => 'B',
            'weekend'       => 'on',
        ]);

        self::assertStringContainsString('value="2026-10-20"', $rehydrated);
        self::assertStringContainsString('&lt;leave &amp; call&gt;', $rehydrated);
        self::assertMatchesRegularExpression('/value="B" selected/', $rehydrated);
        self::assertMatchesRegularExpression('/name="weekend"[^>]*checked/', $rehydrated);
        self::assertStringNotContainsString('<leave & call>', $rehydrated);
    }
}
