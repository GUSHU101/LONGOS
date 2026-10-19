<?php

namespace Tests\Unit;

use Beike\Admin\Services\SettingService;
use Beike\Services\TimezoneService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TimezoneServiceTest extends TestCase
{
    public function test_it_normalizes_legacy_aliases_and_rejects_fixed_offsets(): void
    {
        self::assertSame('Asia/Shanghai', TimezoneService::normalize('PRC'));
        self::assertSame('UTC', TimezoneService::normalize('Etc/UTC'));
        self::assertSame('America/New_York', TimezoneService::normalize('America/New_York'));
        self::assertFalse(TimezoneService::isSupported('+08:00'));
        self::assertFalse(TimezoneService::isSupported('Invalid/Timezone'));
        self::assertSame('Europe/Berlin', TimezoneService::normalize('Invalid/Timezone', 'Europe/Berlin'));
    }

    public function test_timezone_selector_contains_the_complete_iana_catalog(): void
    {
        $options = TimezoneService::options();
        $values  = array_column($options, 'value');

        self::assertGreaterThan(300, count($options));
        self::assertContains('UTC', $values);
        self::assertContains('Asia/Shanghai', $values);
        self::assertContains('America/New_York', $values);
        self::assertStringStartsWith('(UTC', $options[0]['label']);
    }

    public function test_setting_write_boundary_canonicalizes_legacy_aliases(): void
    {
        $method   = new ReflectionMethod(SettingService::class, 'prepareTimezoneSettings');
        $settings = $method->invoke(null, ['app_timezone' => 'PRC']);

        self::assertSame('Asia/Shanghai', $settings['app_timezone']);
    }
}
