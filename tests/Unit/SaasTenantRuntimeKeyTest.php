<?php

namespace Tests\Unit;

use App\Saas\Support\TenantRuntimeKey;
use PHPUnit\Framework\TestCase;

class SaasTenantRuntimeKeyTest extends TestCase
{
    public function test_session_cookie_is_bounded_and_collision_resistant(): void
    {
        $base   = str_repeat('longos-session-', 20);
        $first  = TenantRuntimeKey::sessionCookie($base, 'tenant-a');
        $second = TenantRuntimeKey::sessionCookie($base, 'tenant-b');

        self::assertLessThanOrEqual(120, strlen($first));
        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $first);
        self::assertSame($first, TenantRuntimeKey::sessionCookie($base, 'tenant-a'));
        self::assertStringEndsWith(
            'tenants' . DIRECTORY_SEPARATOR . 'tenant-a',
            TenantRuntimeKey::sessionDirectory('/var/longos/sessions', 'tenant-a'),
        );
    }
}
