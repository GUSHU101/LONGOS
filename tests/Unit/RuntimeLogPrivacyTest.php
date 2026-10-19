<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class RuntimeLogPrivacyTest extends TestCase
{
    public function test_customer_and_deployment_failures_are_redacted_before_logging(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'beike/Shop/Http/Controllers/CheckoutController.php',
            'beike/Shop/Http/Controllers/OrderController.php',
            'beike/Shop/Http/Controllers/ProductController.php',
            'beike/Shop/Services/CheckoutService.php',
            'beike/Shop/Providers/PluginServiceProvider.php',
            'app/Services/DeploymentUpgradeService.php',
            'app/Http/Middleware/SetLocaleInstaller.php',
            'plugins/PixelTracking/Services/ServerTrackingService.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/' . $file);

            self::assertStringContainsString(
                'SensitiveValueRedactor::redact',
                $source,
                "{$file} must redact third-party and runtime exception details before persistence.",
            );
        }
    }
}
