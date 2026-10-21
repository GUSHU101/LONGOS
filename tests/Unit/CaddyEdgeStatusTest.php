<?php

namespace Tests\Unit;

use App\Saas\CaddyEdgeStatus;
use PHPUnit\Framework\TestCase;

class CaddyEdgeStatusTest extends TestCase
{
    public function test_coolify_caddy_is_healthy_only_when_the_authenticated_edge_is_observed(): void
    {
        self::assertTrue(CaddyEdgeStatus::healthy(true, true, 'coolify'));
        self::assertFalse(CaddyEdgeStatus::healthy(true, false, 'coolify'));
        self::assertFalse(CaddyEdgeStatus::healthy(false, true, 'coolify'));
        self::assertFalse(CaddyEdgeStatus::healthy(true, true, 'unknown'));
    }

    public function test_warning_is_actionable_without_leaking_raw_configuration(): void
    {
        self::assertNull(CaddyEdgeStatus::warning(true, true, 'coolify'));
        self::assertStringContainsString(
            '未经过已认证的 Coolify Caddy',
            (string) CaddyEdgeStatus::warning(true, false, 'coolify'),
        );
        self::assertStringContainsString(
            'On-Demand TLS',
            (string) CaddyEdgeStatus::warning(false, false, 'coolify'),
        );
    }
}
