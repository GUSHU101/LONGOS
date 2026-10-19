<?php

namespace Tests\Unit;

use App\Saas\Support\TenantDatabaseProvisioningState;
use PHPUnit\Framework\TestCase;

class SaasTenantDatabaseProvisioningStateTest extends TestCase
{
    public function test_it_distinguishes_first_creation_from_crash_safe_retries(): void
    {
        [$first, $canReuseFirst] = TenantDatabaseProvisioningState::begin([], 'lease-one');

        self::assertFalse($canReuseFirst);
        self::assertArrayHasKey('database_creation_pending', $first);
        self::assertArrayNotHasKey('database_created', $first);
        self::assertSame(hash('sha256', 'lease-one'), $first['database_creation_pending']['lease_hash']);

        [$retry, $canReuseRetry] = TenantDatabaseProvisioningState::begin($first, 'lease-two');

        self::assertTrue($canReuseRetry);
        self::assertSame(hash('sha256', 'lease-two'), $retry['database_creation_pending']['lease_hash']);

        $completed = TenantDatabaseProvisioningState::complete($retry);
        self::assertTrue($completed['database_created']);
        self::assertArrayNotHasKey('database_creation_pending', $completed);

        [$ownedRetry, $canReuseOwned] = TenantDatabaseProvisioningState::begin($completed, 'lease-three');
        self::assertTrue($canReuseOwned);
        self::assertArrayHasKey('database_creation_pending', $ownedRetry);

        self::assertSame([], TenantDatabaseProvisioningState::abort([
            'database_creation_pending' => ['lease_hash' => 'external-conflict'],
        ]));
    }
}
