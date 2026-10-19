<?php

namespace Tests\Unit;

use App\Saas\Support\SensitiveValueRedactor;
use PHPUnit\Framework\TestCase;

class SaasSensitiveValueRedactorTest extends TestCase
{
    public function test_it_redacts_known_and_inline_credentials(): void
    {
        $message  = 'mysql://tenant:super-secret@db/longos password="hunter 2" token=abc123 credential:visible Authorization: Bearer bearer-secret short=x https://cdn.longos.test/plugin.zip?X-Amz-Signature=signed-value&access_token=url-token';
        $redacted = SensitiveValueRedactor::redact($message, ['super-secret', 'x']);

        self::assertStringNotContainsString('super-secret', $redacted);
        self::assertStringNotContainsString('hunter 2', $redacted);
        self::assertStringNotContainsString('abc123', $redacted);
        self::assertStringNotContainsString('visible', $redacted);
        self::assertStringNotContainsString('bearer-secret', $redacted);
        self::assertStringNotContainsString('short=x', $redacted);
        self::assertStringNotContainsString('signed-value', $redacted);
        self::assertStringNotContainsString('url-token', $redacted);
        self::assertStringContainsString('https://cdn.longos.test/plugin.zip?', $redacted);
        self::assertGreaterThanOrEqual(8, substr_count($redacted, '[redacted]'));
    }
}
