<?php

namespace Tests\Feature;

use Beike\Admin\Http\Controllers\CountryController;
use Beike\Admin\Http\Controllers\ZoneController;
use Beike\Admin\Http\Resources\CountryResource;
use Beike\Admin\Services\LocationManagementService;
use Beike\Models\AdminUser;
use Beike\Models\Country;
use Beike\Models\Zone;
use Beike\Repositories\CountryRepo;
use Beike\Repositories\SettingRepo;
use Beike\Repositories\ZoneRepo;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\TestCase;

class LocationManagementServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        config(['database.default' => 'mysql', 'database.connections.mysql' => $connection]);
        DB::purge('mysql');

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('space');
            $table->string('name');
            $table->text('value');
            $table->boolean('json')->default(false);
            $table->timestamps();
        });
        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->string('continent')->default('null');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('zones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('country_id');
            $table->string('name');
            $table->string('code');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('addresses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('country_id');
            $table->unsignedBigInteger('zone_id');
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shipping_country_id')->default(0);
            $table->unsignedBigInteger('payment_country_id')->default(0);
            $table->unsignedBigInteger('shipping_zone_id')->default(0);
            $table->unsignedBigInteger('payment_zone_id')->default(0);
            $table->softDeletes();
        });
        Schema::create('region_zones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('country_id');
            $table->unsignedBigInteger('zone_id');
        });

        DB::table('settings')->insert([
            ['type' => 'system', 'space' => 'base', 'name' => 'country_id', 'value' => '1', 'json' => false],
            ['type' => 'system', 'space' => 'base', 'name' => 'zone_id', 'value' => '10', 'json' => false],
        ]);
        SettingRepo::clearCache();
        Country::query()->insert([
            ['id' => 1, 'name' => 'Default', 'code' => 'ZZ', 'continent' => 'null', 'sort_order' => 0, 'active' => true],
            ['id' => 2, 'name' => 'Afghanistan', 'code' => 'AF', 'continent' => 'AS', 'sort_order' => 7, 'active' => true],
        ]);
        Zone::query()->insert([
            ['id' => 10, 'country_id' => 1, 'name' => 'Default zone', 'code' => 'D1', 'sort_order' => 0, 'active' => true],
            ['id' => 20, 'country_id' => 2, 'name' => 'Unused zone', 'code' => 'U1', 'sort_order' => 0, 'active' => true],
        ]);

        $admin = new AdminUser(['name' => 'Root', 'email' => 'root@example.com', 'active' => true]);
        $admin->forceFill(['id' => 1]);
        Auth::shouldUse(AdminUser::AUTH_GUARD);
        Auth::guard(AdminUser::AUTH_GUARD)->setUser($admin);
    }

    protected function tearDown(): void
    {
        DB::disconnect('mysql');
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_batch_status_skips_the_default_country_and_processes_the_rest(): void
    {
        $result = app(LocationManagementService::class)->batchCountries([1, 2], 'disable');

        self::assertSame(1, $result['processed']);
        self::assertSame(1, $result['skipped']);
        self::assertTrue((bool) Country::query()->findOrFail(1)->active);
        self::assertFalse((bool) Country::query()->findOrFail(2)->active);
    }

    public function test_country_delete_cascades_only_unused_zones_and_auto_sync_respects_the_deletion(): void
    {
        CountryRepo::delete(2);

        self::assertFalse(Country::query()->where('code', 'AF')->exists());
        self::assertFalse(Zone::query()->whereKey(20)->exists());

        app(LocationManagementService::class)->syncCountries();

        self::assertFalse(Country::query()->where('code', 'AF')->exists());
        self::assertTrue(Country::query()->where('code', 'AL')->exists());
    }

    public function test_batch_delete_skips_a_country_referenced_by_an_address(): void
    {
        DB::table('addresses')->insert(['country_id' => 2, 'zone_id' => 20]);

        $result = app(LocationManagementService::class)->batchCountries([2], 'delete');

        self::assertSame(0, $result['processed']);
        self::assertSame(1, $result['skipped']);
        self::assertTrue(Country::query()->whereKey(2)->exists());
        self::assertTrue(Zone::query()->whereKey(20)->exists());
    }

    public function test_deleted_standard_zone_is_not_recreated_by_automatic_sync(): void
    {
        Zone::query()->create([
            'id'         => 21,
            'country_id' => 2,
            'name'       => 'Badakhshan',
            'code'       => 'BDS',
            'sort_order' => 0,
            'active'     => true,
        ]);

        ZoneRepo::delete(21);
        app(LocationManagementService::class)->syncZones();

        self::assertFalse(Zone::query()->where('country_id', 2)->where('code', 'BDS')->exists());
        self::assertTrue(Zone::query()->where('country_id', 2)->where('code', 'BDG')->exists());
    }

    public function test_country_and_zone_admin_pages_render_without_runtime_errors(): void
    {
        // Console tests do not execute the admin HTTP request branch that
        // shares these layout variables, so provide the same view contract.
        View::share('admin_base_url', '/admin/');
        View::share('shop_base_url', '/');
        Gate::shouldReceive('check')->andReturnTrue();
        $countries   = CountryRepo::list();
        $countryPage = view('admin::pages.country.index', [
            'countries'        => $countries,
            'countries_format' => CountryResource::collection($countries)->jsonSerialize(),
            'continents'       => CountryRepo::getContinents(),
        ])->render();

        $zones    = ZoneRepo::list();
        $zonePage = view('admin::pages.zones.index', [
            'zones'     => $zones,
            'countries' => CountryRepo::all(),
        ])->render();

        self::assertStringContainsString('countries\\/batch', $countryPage);
        self::assertStringContainsString('countries\\/sync', $countryPage);
        self::assertStringContainsString('zones\\/batch', $zonePage);
        self::assertStringContainsString('zones\\/sync', $zonePage);
    }

    public function test_admin_json_lists_and_all_filtered_batch_actions_do_not_raise_server_errors(): void
    {
        Gate::shouldReceive('authorize')->twice()->andReturnTrue();

        $countryIndex = Request::create('/admin/countries', 'GET', ['code' => 'AF']);
        $countryIndex->headers->set('Accept', 'application/json');
        $countryResponse = app(CountryController::class)->index($countryIndex);

        $zoneIndex = Request::create('/admin/zones', 'GET', ['country_id' => 2]);
        $zoneIndex->headers->set('Accept', 'application/json');
        $zoneResponse = app(ZoneController::class)->index($zoneIndex);

        self::assertSame(200, $countryResponse->getStatusCode());
        self::assertSame(200, $zoneResponse->getStatusCode());

        $countryBatch = $this->formRequest('/admin/countries/batch', [
            'action'       => 'disable',
            'all_filtered' => true,
            'filters'      => ['code' => 'AF'],
        ]);
        $countryBatchResponse = app(CountryController::class)->batch($countryBatch, app(LocationManagementService::class));

        $zoneBatch = $this->formRequest('/admin/zones/batch', [
            'action'       => 'disable',
            'all_filtered' => true,
            'filters'      => ['country_id' => 2],
        ]);
        $zoneBatchResponse = app(ZoneController::class)->batch($zoneBatch, app(LocationManagementService::class));

        self::assertSame(200, $countryBatchResponse->getStatusCode());
        self::assertSame(200, $zoneBatchResponse->getStatusCode());
        self::assertFalse((bool) Country::query()->findOrFail(2)->active);
        self::assertFalse((bool) Zone::query()->findOrFail(20)->active);
    }

    public function test_manual_sync_returns_a_validation_response_instead_of_a_500_on_failure(): void
    {
        $countries = \Mockery::mock(LocationManagementService::class);
        $countries->shouldReceive('syncCountries')->once()->andThrow(new \RuntimeException('simulated failure'));

        $zones = \Mockery::mock(LocationManagementService::class);
        $zones->shouldReceive('syncCountries')->once()->andThrow(new \RuntimeException('simulated failure'));

        $countryResponse = app(CountryController::class)->sync($countries);
        $zoneResponse    = app(ZoneController::class)->sync($zones);

        self::assertSame(422, $countryResponse->getStatusCode());
        self::assertSame(422, $zoneResponse->getStatusCode());
    }

    private function formRequest(string $uri, array $payload): Request
    {
        $request = Request::create($uri, 'POST', $payload);
        $request->headers->set('Accept', 'application/json');

        return $request;
    }
}
