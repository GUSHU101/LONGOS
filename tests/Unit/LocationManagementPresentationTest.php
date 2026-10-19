<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LocationManagementPresentationTest extends TestCase
{
    public function test_country_and_zone_pages_offer_safe_batch_and_sync_controls(): void
    {
        $root      = dirname(__DIR__, 2);
        $countries = (string) file_get_contents($root . '/resources/beike/admin/views/pages/country/index.blade.php');
        $zones     = (string) file_get_contents($root . '/resources/beike/admin/views/pages/zones/index.blade.php');

        foreach ([$countries, $zones] as $page) {
            self::assertStringContainsString('v-model="allSelected"', $page);
            self::assertStringContainsString('v-model="selectedIds"', $page);
            self::assertStringContainsString("batchAction('enable')", $page);
            self::assertStringContainsString("batchAction('disable')", $page);
            self::assertStringContainsString("batchAction('delete')", $page);
            self::assertStringContainsString('syncCatalog()', $page);
            self::assertStringContainsString('@click.stop="deleteCustomer', $page);
            self::assertStringNotContainsString('event.stopPropagation()', $page);
        }
    }

    public function test_location_automation_is_non_destructive_and_scheduled_for_all_tenants(): void
    {
        $root       = dirname(__DIR__, 2);
        $service    = (string) file_get_contents($root . '/beike/Admin/Services/LocationManagementService.php');
        $exclusions = (string) file_get_contents($root . '/beike/Services/LocationCatalogExclusionService.php');
        $kernel     = (string) file_get_contents($root . '/app/Console/Kernel.php');
        $routes     = (string) file_get_contents($root . '/beike/Admin/Routes/admin.php');

        self::assertStringContainsString('without changing an', $service);
        self::assertStringContainsString("'active'     => (bool)", $service);
        self::assertStringContainsString("'sort_order' => (int)", $service);
        self::assertStringContainsString('excluded_countries', $exclusions);
        self::assertStringContainsString('excluded_zones', $exclusions);
        self::assertStringContainsString('saas:run longos:locations:sync --continue-on-error', $kernel);
        self::assertStringContainsString("->weeklyOn(1, '04:20')", $kernel);
        self::assertStringContainsString('countries/batch', $routes);
        self::assertStringContainsString('zones/batch', $routes);
        self::assertStringContainsString('countries/sync', $routes);
        self::assertStringContainsString('zones/sync', $routes);
    }
}
