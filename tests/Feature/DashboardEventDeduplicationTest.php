<?php

namespace Tests\Feature;

use Beike\Models\DashboardEvent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class DashboardEventDeduplicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        Config::set('cache.default', 'array');
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        Cache::flush();

        Schema::create('dashboard_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->default(0);
            $table->string('session_id')->default('');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('ip')->nullable();
            $table->timestamps();
        });

        $request = Request::create('/checkout/confirm', 'POST', server: ['REMOTE_ADDR' => '203.0.113.8']);
        $request->headers->set('Bksessionid', 'checkout-session');
        $app->instance('request', $request);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_payment_information_is_recorded_once_per_order(): void
    {
        $customer = (object) ['id' => 42];
        $orderA   = (object) ['id' => 1001, 'number' => 'ORDER-A'];
        $orderB   = (object) ['id' => 1002, 'number' => 'ORDER-B'];

        DashboardEvent::recordPaymentInformationAdded($customer, $orderA);
        DashboardEvent::recordPaymentInformationAdded($customer, $orderA);
        DashboardEvent::recordPaymentInformationAdded($customer, $orderB);

        self::assertSame(2, DashboardEvent::query()->where('event_type', 'payment_information_added')->count());
        self::assertSame([42, 42], DashboardEvent::query()->orderBy('id')->pluck('customer_id')->all());
    }
}
