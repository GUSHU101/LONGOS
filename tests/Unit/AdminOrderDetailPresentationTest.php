<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\TestCase;

class AdminOrderDetailPresentationTest extends TestCase
{
    public function test_order_detail_uses_compact_responsive_sections_and_safe_payment_disclosure(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/beike/admin/views/pages/orders/form.blade.php');

        self::assertStringContainsString('class="order-facts"', $view);
        self::assertStringContainsString('class="address-grid"', $view);
        self::assertStringContainsString('class="status-layout"', $view);
        self::assertStringContainsString('class="payment-state-pill', $view);
        self::assertStringContainsString('<details>', $view);
        self::assertStringContainsString('payment-payload-grid', $view);
        self::assertStringContainsString('@media (max-width: 767.98px)', $view);
        self::assertStringNotContainsString('<textarea class="form-control" readonly>{{ $payment->request }}</textarea>', $view);
    }

    public function test_order_detail_blade_compiles_without_a_database_connection(): void
    {
        $view     = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/beike/admin/views/pages/orders/form.blade.php');
        $compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());
        $compiled = $compiler->compileString($view);

        // Keep every generated statement unreachable: eval is used only as a
        // PHP parser so this test does not need routes, settings or a tenant.
        eval('if (false) { ?>' . $compiled . '<?php }');

        self::assertStringContainsString("startSection('content')", $compiled);
    }
}
