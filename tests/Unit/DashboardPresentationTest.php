<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DashboardPresentationTest extends TestCase
{
    public function test_dashboard_uses_one_accessible_snapshot_driven_surface(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/resources/beike/admin/views/pages/dashboard/charts_data.blade.php');

        self::assertStringContainsString('data-dashboard-range="{{ $range }}"', $view);
        self::assertStringContainsString("'month' => __('admin/dashboard.near_30_days')", $view);
        self::assertStringContainsString("'overview' => admin_route('dashboard.overview')", $view);
        self::assertStringContainsString('window.dashboardConfig = {!! json_encode([', $view);
        self::assertStringNotContainsString('window.dashboardConfig = @json([', $view);
        self::assertStringContainsString('aria-busy="true"', $view);
        self::assertStringContainsString('data-dashboard-retry', $view);
        self::assertStringNotContainsString('onclick=', $view);
    }

    public function test_dashboard_client_is_race_safe_and_does_not_interpolate_api_content(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/resources/beike/admin/js/admin-dashboard.js');

        self::assertStringContainsString('const requestId = ++this.requestId', $script);
        self::assertStringContainsString('requestId !== this.requestId', $script);
        self::assertStringContainsString('textContent = value', $script);
        self::assertStringContainsString('document.visibilityState', $script);
        self::assertStringNotContainsString('innerHTML =', $script);
    }

    public function test_dashboard_layout_has_desktop_tablet_mobile_and_reduced_motion_contracts(): void
    {
        $styles = file_get_contents(dirname(__DIR__, 2) . '/resources/beike/admin/css/_admin-dashboard.scss');

        self::assertStringContainsString('grid-template-columns: repeat(12', $styles);
        self::assertStringContainsString('@media (max-width: 991.98px)', $styles);
        self::assertStringContainsString('@media (max-width: 479.98px)', $styles);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        self::assertStringContainsString(':focus-visible', $styles);
    }
}
