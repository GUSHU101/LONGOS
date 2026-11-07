<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\LongosGlobalTranslate\Services\ProviderAccountService;

class GlobalTranslationConfigurationTest extends TestCase
{
    public function test_provider_profiles_match_the_persisted_credential_contract(): void
    {
        $profiles = ProviderAccountService::profiles();

        self::assertSame(['aliyun', 'volcengine', 'baidu', 'deepl', 'google', 'azure'], array_keys($profiles));
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
        self::assertSame(
            ['auth_key', 'api_plan'],
            array_column($profiles['deepl']['credential_fields'], 'key'),
        );
        self::assertSame(
            ['api_key'],
            array_column($profiles['google']['credential_fields'], 'key'),
        );
        self::assertSame(
            ['subscription_key', 'region'],
            array_column($profiles['azure']['credential_fields'], 'key'),
        );

        foreach ($profiles as $profile) {
            self::assertNotEmpty($profile['credential_fields']);
            self::assertNotSame('', $profile['label']);
            self::assertNotSame('', $profile['account_help']);
            self::assertNotSame('', $profile['quota_help']);
        }
    }
}
