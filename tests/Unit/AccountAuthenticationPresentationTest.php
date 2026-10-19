<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AccountAuthenticationPresentationTest extends TestCase
{
    public function test_login_and_registration_use_one_accessible_native_account_flow(): void
    {
        $root  = dirname(__DIR__, 2);
        $login = (string) file_get_contents($root . '/themes/default/account/login.blade.php');

        self::assertStringContainsString('role="tablist"', $login);
        self::assertStringContainsString('role="tabpanel"', $login);
        self::assertStringContainsString('aria-selected=', $login);
        self::assertStringContainsString('autocomplete="username"', $login);
        self::assertStringContainsString('autocomplete="current-password"', $login);
        self::assertGreaterThanOrEqual(2, substr_count($login, 'autocomplete="new-password"'));
        self::assertStringContainsString('name="password_confirmation"', $login);
        self::assertStringContainsString('data-auth-recovery-link', $login);
        self::assertStringContainsString('minlength="8"', $login);
        self::assertStringContainsString('reportValidity()', $login);
        self::assertStringContainsString('role="alert"', $login);
        self::assertStringContainsString('event.origin !== window.location.origin', $login);
        self::assertStringContainsString('Illuminate\Support\Js::from', $login);
        self::assertStringNotContainsString('<el-form', $login);
        self::assertStringNotContainsString('password_confirmation = this.registerForm.password', $login);
    }

    public function test_password_recovery_is_a_validated_two_step_native_flow(): void
    {
        $root      = dirname(__DIR__, 2);
        $forgotten = (string) file_get_contents($root . '/themes/default/account/forgotten.blade.php');
        $request   = (string) file_get_contents($root . '/beike/Shop/Http/Requests/ForgottenRequest.php');

        self::assertStringContainsString('class="auth-steps"', $forgotten);
        self::assertStringContainsString('autocomplete="email"', $forgotten);
        self::assertStringContainsString('autocomplete="one-time-code"', $forgotten);
        self::assertStringContainsString('pattern="[0-9]{6}"', $forgotten);
        self::assertStringContainsString('autocomplete="new-password"', $forgotten);
        self::assertStringContainsString('reportValidity()', $forgotten);
        self::assertStringNotContainsString('<el-form', $forgotten);
        self::assertStringContainsString("'code'     => 'required|digits:6'", $request);
        self::assertStringContainsString('min:8|max:255|confirmed', $request);
    }

    public function test_authentication_backend_does_not_leak_passwords_or_activate_pending_accounts(): void
    {
        $root       = dirname(__DIR__, 2);
        $login      = (string) file_get_contents($root . '/beike/Shop/Http/Controllers/Account/LoginController.php');
        $register   = (string) file_get_contents($root . '/beike/Shop/Http/Controllers/Account/RegisterController.php');
        $redirect   = (string) file_get_contents($root . '/beike/Shop/Services/AccountRedirectService.php');
        $account    = (string) file_get_contents($root . '/beike/Shop/Services/AccountService.php');
        $validation = (string) file_get_contents($root . '/beike/Shop/Http/Requests/RegisterRequest.php');

        self::assertStringContainsString("safe()->only(['email'])", $login);
        self::assertStringContainsString('catch (Throwable $e)', $login);
        self::assertStringContainsString("['error' => 'system']", $login);
        self::assertStringContainsString('session()->regenerate()', $login);
        self::assertStringContainsString("if (\$customer->status !== 'approved')", $register);
        self::assertStringContainsString("'pending' => true", $register);
        self::assertStringContainsString('auth(Customer::AUTH_GUARD)->login($customer)', $register);
        self::assertLessThan(
            strpos($register, 'auth(Customer::AUTH_GUARD)->login($customer)'),
            strpos($register, "if (\$customer->status !== 'approved')")
        );
        self::assertStringContainsString("str_starts_with(\$intended, '//')", $redirect);
        self::assertStringContainsString('hash_equals', $redirect);
        self::assertStringContainsString('report($e);', $account);
        self::assertStringContainsString('min:8|max:255|confirmed', $validation);
    }

    public function test_authentication_visual_contract_is_responsive_and_keyboard_visible(): void
    {
        $root       = dirname(__DIR__, 2);
        $styles     = (string) file_get_contents($root . '/resources/beike/shop/default/css/auth-rebuild.scss');
        $stylesheet = (string) file_get_contents($root . '/resources/beike/shop/default/css/app.scss');

        self::assertStringContainsString('grid-template-columns: minmax(330px, 0.86fr) minmax(460px, 1.14fr)', $styles);
        self::assertStringContainsString('min-height: 52px', $styles);
        self::assertStringContainsString('min-height: 54px', $styles);
        self::assertStringContainsString(':focus-visible', $styles);
        self::assertStringContainsString('@media (max-width: 900px)', $styles);
        self::assertStringContainsString('@media (max-width: 575px)', $styles);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        self::assertStringContainsString("@import './auth-rebuild';", $stylesheet);
    }

    public function test_new_authentication_copy_is_available_in_english_and_chinese(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['login', 'forgotten'] as $file) {
            $english = require $root . "/lang/en/shop/{$file}.php";
            $chinese = require $root . "/lang/zh_cn/shop/{$file}.php";

            self::assertSame([], array_values(array_diff(array_keys($english), array_keys($chinese))));
            self::assertSame([], array_values(array_diff(array_keys($chinese), array_keys($english))));
        }
    }
}
