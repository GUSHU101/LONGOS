<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\LongosGlobalTranslate\Services\ProviderAccountService;

class GlobalTranslationConfigurationTest extends TestCase
{
    public function test_provider_profiles_match_the_persisted_credential_contract(): void
    {
        $profiles = ProviderAccountService::profiles();

        self::assertSame(['aliyun', 'volcengine', 'baidu'], array_keys($profiles));
        self::assertSame(
            ['access_key_id', 'access_key_secret'],
            array_column($profiles['aliyun']['credential_fields'], 'key'),
        );
        self::assertSame(
            ['access_key_id', 'secret_access_key'],
            array_column($profiles['volcengine']['credential_fields'], 'key'),
        );
        self::assertSame(
            ['appid', 'secret'],
            array_column($profiles['baidu']['credential_fields'], 'key'),
        );

        foreach ($profiles as $profile) {
            self::assertCount(2, $profile['credential_fields']);
            self::assertNotSame('', $profile['label']);
            self::assertNotSame('', $profile['account_help']);
            self::assertNotSame('', $profile['quota_help']);
        }
    }
}
