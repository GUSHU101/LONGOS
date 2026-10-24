<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StorePolicyAdminPresentationTest extends TestCase
{
    public function test_policy_admin_has_profile_generator_and_overwrite_protection(): void
    {
        $root       = dirname(__DIR__, 2);
        $view       = (string) file_get_contents($root . '/resources/beike/admin/views/pages/setting/policies.blade.php');
        $routes     = (string) file_get_contents($root . '/beike/Admin/Routes/admin.php');
        $controller = (string) file_get_contents($root . '/beike/Admin/Http/Controllers/SettingController.php');

        self::assertStringContainsString('data-policy-profile="business_name"', $view);
        self::assertStringContainsString('data-policy-profile="legal_name"', $view);
        self::assertStringContainsString('data-policy-profile="registration_number"', $view);
        self::assertStringContainsString('data-policy-profile="business_address"', $view);
        self::assertStringContainsString('data-policy-profile="contact_phone"', $view);
        self::assertStringContainsString('data-policy-profile="support_hours"', $view);
        self::assertStringContainsString('data-policy-profile="support_response_hours"', $view);
        self::assertStringContainsString('data-policy-profile="cancellation_window_hours"', $view);
        self::assertStringContainsString('data-policy-profile="return_window_days"', $view);
        self::assertStringContainsString('id="policy-generate-button"', $view);
        self::assertStringContainsString('window.confirm', $view);
        self::assertStringContainsString("admin_route('settings.policies.generate')", $view);
        self::assertStringNotContainsString('policy_link_title', $view);
        self::assertStringContainsString("name('settings.policies.generate')", $routes);
        self::assertStringContainsString('function generatePolicies', $controller);
        self::assertStringContainsString('RateLimiter::attempt', $controller);
        $storefront = (string) file_get_contents($root . '/themes/default/policies/show.blade.php');
        self::assertStringContainsString('class="policy-identity"', $storefront);
        self::assertStringContainsString("__('policy.legal_entity')", $storefront);
        foreach (['contact', 'payment', 'cancellation', 'cookies'] as $document) {
            self::assertStringContainsString("'{$document}' => [", (string) file_get_contents($root . '/beike/Repositories/StorePolicyRepo.php'));
        }
    }
}
