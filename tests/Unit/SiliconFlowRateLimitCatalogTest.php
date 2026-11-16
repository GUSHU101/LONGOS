<?php

namespace Tests\Unit;

use App\Services\SiliconFlowRateLimitCatalog;
use App\Services\SiliconFlowRateLimiter;
use PHPUnit\Framework\TestCase;

class SiliconFlowRateLimitCatalogTest extends TestCase
{
    public function test_marketplace_profiles_are_parsed_for_the_requested_model_and_level(): void
    {
        $html = <<<'HTML'
<script>self.__next_f.push([1,"{\"modelName\":\"tencent/Hunyuan-MT-7B\",\"modelInfo\":[{\"Level\":0,\"RPM\":1000,\"TPM\":80000},{\"Level\":1,\"RPM\":1200,\"TPM\":120000}]}" ])</script>
HTML;

        self::assertSame(
            ['rpm' => 1000, 'tpm' => 80_000],
            SiliconFlowRateLimitCatalog::parseModelProfile($html, 'tencent/Hunyuan-MT-7B'),
        );
        self::assertSame(
            ['rpm' => 1200, 'tpm' => 120_000],
            SiliconFlowRateLimitCatalog::parseModelProfile($html, 'tencent/Hunyuan-MT-7B', 1),
        );
        self::assertNull(SiliconFlowRateLimitCatalog::parseModelProfile($html, 'missing/model'));
    }

    public function test_bundled_l0_profiles_and_rate_limit_classification_are_explicit(): void
    {
        self::assertSame([
            'rpm' => 1000,
            'tpm' => 80_000,
        ], SiliconFlowRateLimitCatalog::bundledLevelProfiles()['tencent/Hunyuan-MT-7B']);
        self::assertSame([
            'rpm' => 1000,
            'tpm' => 40_000,
        ], SiliconFlowRateLimitCatalog::bundledLevelProfiles()['XingChenAGI/Xing4.0-29B']);
        self::assertSame('RATE_LIMIT_TPM', SiliconFlowRateLimitCatalog::rateLimitCode(['message' => 'Details: TPM limit reached.']));
        self::assertSame('RATE_LIMIT_RPM', SiliconFlowRateLimitCatalog::rateLimitCode(['message' => 'Details: RPM limit reached.']));
        self::assertSame('RATE_LIMIT', SiliconFlowRateLimitCatalog::rateLimitCode(['message' => 'Request rate limited.']));
        self::assertSame(['rpm' => 900, 'tpm' => 36_000], SiliconFlowRateLimiter::effectiveLimits(1000, 40_000));
    }

    public function test_token_estimation_does_not_count_every_utf8_byte_as_a_token(): void
    {
        self::assertSame(100, SiliconFlowRateLimiter::estimateTokens(str_repeat('a', 300)));
        self::assertSame(100, SiliconFlowRateLimiter::estimateTokens(str_repeat('中', 100)));
        self::assertSame(0, SiliconFlowRateLimiter::estimateTokens(''));
    }
}
