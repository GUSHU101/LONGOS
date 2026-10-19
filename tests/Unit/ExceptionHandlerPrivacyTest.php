<?php

namespace Tests\Unit;

use App\Exceptions\Handler;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ExceptionHandlerPrivacyTest extends TestCase
{
    public function test_production_json_errors_do_not_expose_runtime_exception_details(): void
    {
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config(['app.debug' => false]);

        $request = Request::create('/login', 'GET', server: [
            'HTTP_ACCEPT'           => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $response = (new Handler($app))->render(
            $request,
            new RuntimeException('SQL password and /var/www/private/path must stay in the log'),
        );
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('服务器错误，请联系管理员', $payload['message']);
        self::assertStringNotContainsString('password', (string) $response->getContent());
        self::assertStringNotContainsString('/var/www', (string) $response->getContent());
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }
}
