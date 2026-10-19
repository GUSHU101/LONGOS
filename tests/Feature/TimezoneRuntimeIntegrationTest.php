<?php

namespace Tests\Feature;

use App\Http\Middleware\SetAppTimezone;
use Beike\Admin\Services\SettingService;
use Beike\Services\TimezoneService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class TimezoneRuntimeIntegrationTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        Carbon::setTestNow();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_middleware_applies_the_saved_timezone_to_laravel_and_php(): void
    {
        config(['bk.system.base.app_timezone' => 'America/New_York']);

        $response = (new SetAppTimezone)->handle(Request::create('/admin'), static fn () => 'ok');

        self::assertSame('ok', $response);
        self::assertSame('America/New_York', config('app.timezone'));
        self::assertSame('America/New_York', date_default_timezone_get());
    }

    public function test_invalid_legacy_value_fails_safe_instead_of_breaking_admin(): void
    {
        config([
            'app.timezone'                    => 'Asia/Shanghai',
            'bk.system.base.app_timezone'     => 'Broken/Timezone',
        ]);

        (new SetAppTimezone)->handle(Request::create('/admin'), static fn () => 'ok');

        self::assertSame('Asia/Shanghai', config('app.timezone'));
        self::assertSame('Asia/Shanghai', date_default_timezone_get());
    }

    public function test_shared_time_formatter_converts_absolute_instants_to_store_timezone(): void
    {
        TimezoneService::apply('Asia/Shanghai');

        self::assertSame('2026-01-01 08:00:00', time_format(Carbon::parse('2026-01-01 00:00:00', 'UTC')));
        self::assertSame('2026-01-01 08:00:00', time_format(1767225600));
    }

    public function test_saving_basic_settings_applies_timezone_without_a_restart(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'longos-timezone-');
        config([
            'database.default'                   => 'timezone_test',
            'database.connections.timezone_test' => [
                'driver'                  => 'sqlite',
                'database'                => $databasePath,
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'bk' => [],
        ]);
        DB::purge('timezone_test');
        DB::setDefaultConnection('timezone_test');

        try {
            Schema::create('settings', function (Blueprint $table): void {
                $table->id();
                $table->string('type');
                $table->string('space');
                $table->string('name');
                $table->text('value');
                $table->boolean('json')->default(false);
                $table->timestamps();
            });

            SettingService::storeSettings(['app_timezone' => 'Asia/Tokyo']);

            self::assertSame('Asia/Tokyo', config('app.timezone'));
            self::assertSame('Asia/Tokyo', date_default_timezone_get());
            self::assertSame('Asia/Tokyo', DB::table('settings')->where('name', 'app_timezone')->value('value'));
        } finally {
            DB::disconnect('timezone_test');
            @unlink($databasePath);
        }
    }
}
