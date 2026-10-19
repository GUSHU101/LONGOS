<?php

namespace Tests\Unit;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\TestCase;

class HookWrapperNestingTest extends TestCase
{
    public function test_nested_wrapper_hooks_preserve_their_names_and_content(): void
    {
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $compiled = Blade::compileString(<<<'BLADE'
@hookwrapper('account.login.password')
<div>password-start
@if (true)
@hookwrapper('account.login.forget_password')
<a href="/forgotten">Forgot password</a>
@endhookwrapper
@endif
password-end</div>
@endhookwrapper
BLADE);

        ob_start();
        eval('?>' . $compiled);
        $output = (string) ob_get_clean();

        self::assertStringContainsString('password-start', $output);
        self::assertStringContainsString('Forgot password', $output);
        self::assertStringContainsString('password-end', $output);
        self::assertStringNotContainsString('$__hook_name', $compiled);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }
}
