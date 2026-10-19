<?php

namespace Tests\Feature;

use App\Http\Controllers\CloudMailRelayController;
use App\Mail\CloudMailTenantResolver;
use App\Mail\MailSenderProfileService;
use App\Saas\Models\Domain;
use App\Saas\Models\MailSenderDomain;
use App\Saas\Models\Site;
use App\Saas\TenantManager;
use Beike\Admin\Http\Controllers\AccountController;
use Beike\Admin\Http\Controllers\SaasSiteController;
use Beike\Models\AdminUser;
use Beike\Models\AdminUserToken;
use Beike\Repositories\AdminUserTokenRepo;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class CloudMailIntegrationTokenTest extends TestCase
{
    private Application $app;

    private string $databasePath;

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();

        $this->databasePath       = tempnam(sys_get_temp_dir(), 'longos-integration-token-');
        $this->originalConnection = (string) config('database.default');
        config([
            'app.key'                                     => 'base64:' . base64_encode(str_repeat('k', 32)),
            'app.cipher'                                  => 'AES-256-CBC',
            'database.default'                            => 'integration_token_test',
            'database.connections.integration_token_test' => [
                'driver'                  => 'sqlite',
                'database'                => $this->databasePath,
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'saas.enabled'                              => false,
            'mail.relay.allowed_sender_domains'         => ['mail.example.com'],
            'mail.relay.platform_sender_domain'         => 'mail.example.com',
            'mail.relay.cloud_mail_secret'              => str_repeat('s', 64),
            'mail.mailers.self_hosted.endpoint'         => 'http://mail-relay:3000',
            'mail.mailers.self_hosted.timeout'          => 10,
        ]);
        DB::purge('integration_token_test');
        DB::setDefaultConnection('integration_token_test');

        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('active');
            $table->boolean('is_root')->default(false);
            $table->string('locale')->default('');
            $table->timestamps();
        });
        Schema::create('admin_user_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_user_id');
            $table->string('token', 64);
            $table->timestamps();
        });

        DB::table('admin_users')->insert([
            [
                'id'       => 1,
                'name'     => 'Root',
                'email'    => 'root@example.com',
                'password' => 'unused',
                'active'   => true,
                'is_root'  => true,
                'locale'   => 'en',
            ],
            [
                'id'       => 2,
                'name'     => 'Other',
                'email'    => 'other@example.com',
                'password' => 'unused',
                'active'   => true,
                'is_root'  => false,
                'locale'   => 'en',
            ],
        ]);
        DB::table('admin_user_tokens')->insert([
            'admin_user_id' => 1,
            'token'         => 'legacy-plaintext-token-that-is-long-enough-123456789',
        ]);

        (require base_path('database/migrations/2026_10_16_020000_harden_admin_user_tokens_for_integrations.php'))->up();
        (require base_path('database/migrations/2026_10_16_030000_add_encrypted_value_to_admin_user_tokens.php'))->up();
        (require base_path('database/migrations/2026_10_16_040000_add_reveal_audit_to_admin_user_tokens.php'))->up();
        self::assertTrue(Schema::hasIndex('admin_user_tokens', ['admin_user_id']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('integration_token_test');
        DB::disconnect('saas');
        DB::setDefaultConnection($this->originalConnection);
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();
        Facade::clearResolvedInstances();
        $this->app->flush();
        parent::tearDown();
    }

    public function test_migration_revokes_legacy_plaintext_and_new_tokens_are_hashed_scoped_and_revocable(): void
    {
        $legacyPlain = 'legacy-plaintext-token-that-is-long-enough-123456789';
        $legacy      = AdminUserToken::query()->firstOrFail();

        self::assertSame(hash('sha256', $legacyPlain), $legacy->getRawOriginal('token'));
        self::assertSame(hash('sha256', $legacyPlain), $legacy->getRawOriginal('token_hash'));
        self::assertNotNull($legacy->revoked_at);
        self::assertNull(AdminUserTokenRepo::getAdminUserTokenByToken($legacyPlain));
        self::assertNull(AdminUserTokenRepo::reveal(1, $legacy));

        $issued = AdminUserTokenRepo::issue(
            1,
            'Cloud Mail production',
            [AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND, AdminUserTokenRepo::ABILITY_CLOUD_MAIL_HEALTH],
            now()->addDays(30),
        );
        $plain = $issued['plain_text_token'];
        $model = $issued['model']->fresh();

        self::assertStringStartsWith('longos_it_', $plain);
        self::assertNotSame($plain, $model->getRawOriginal('token'));
        self::assertSame(hash('sha256', $plain), $model->getRawOriginal('token_hash'));
        self::assertNotEmpty($model->getRawOriginal('token_ciphertext'));
        self::assertNotSame($plain, $model->getRawOriginal('token_ciphertext'));
        self::assertStringNotContainsString($plain, $model->getRawOriginal('token_ciphertext'));
        self::assertSame(substr($plain, -8), $model->token_hint);
        self::assertNotContains('token', array_keys($model->toArray()));
        self::assertNotContains('token_hash', array_keys($model->toArray()));
        self::assertNotContains('token_ciphertext', array_keys($model->toArray()));
        self::assertSame($plain, AdminUserTokenRepo::reveal(1, $model));
        self::assertNull(AdminUserTokenRepo::reveal(2, $model));
        self::assertSame(1, $model->fresh()->reveal_count);
        self::assertNotNull($model->fresh()->last_revealed_at);
        self::assertNotNull(AdminUserTokenRepo::getAdminUserTokenByToken($plain, AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND));
        self::assertNull(AdminUserTokenRepo::getAdminUserTokenByToken($plain, 'unrelated:ability'));
        self::assertNotNull($model->fresh()->last_used_at);

        self::assertFalse(AdminUserTokenRepo::revoke(2, $model));
        self::assertTrue(AdminUserTokenRepo::revoke(1, $model));
        self::assertNull(AdminUserTokenRepo::getAdminUserTokenByToken($plain, AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND));
        self::assertSame($plain, AdminUserTokenRepo::reveal(1, $model));
        self::assertSame(2, $model->fresh()->reveal_count);

        DB::table('admin_user_tokens')->where('id', $model->id)->update(['token_ciphertext' => 'corrupted']);
        self::assertNull(AdminUserTokenRepo::reveal(1, $model));
        self::assertSame(2, $model->fresh()->reveal_count);

        $rotated = AdminUserTokenRepo::issue(
            1,
            'Rotated key',
            [AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND],
            now()->addDay(),
        );
        $previousKey   = str_repeat('p', 32);
        $oldCiphertext = (new Encrypter($previousKey, 'AES-256-CBC'))->encryptString($rotated['plain_text_token']);
        DB::table('admin_user_tokens')->where('id', $rotated['model']->id)->update([
            'token_ciphertext' => $oldCiphertext,
        ]);
        Crypt::getFacadeRoot()->previousKeys([$previousKey]);

        self::assertSame($rotated['plain_text_token'], AdminUserTokenRepo::reveal(1, $rotated['model']));
        self::assertNotSame($oldCiphertext, $rotated['model']->fresh()->token_ciphertext);
        self::assertSame($rotated['plain_text_token'], Crypt::decryptString($rotated['model']->fresh()->token_ciphertext));

        $duplicate = AdminUserTokenRepo::issue(
            1,
            'Duplicate hash safety',
            [AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND],
            now()->addDay(),
        );
        $active = $duplicate['model']->fresh();
        $copy   = $active->getAttributes();
        unset($copy['id']);

        $active->forceFill(['revoked_at' => now()])->save();
        $copy['revoked_at'] = null;
        $copy['name']       = 'Active duplicate';
        $activeId           = DB::table('admin_user_tokens')->insertGetId($copy);

        self::assertSame(
            $activeId,
            AdminUserTokenRepo::getAdminUserTokenByToken(
                $duplicate['plain_text_token'],
                AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND,
            )?->id,
        );

        DB::table('admin_users')->where('id', 2)->update(['active' => false]);
        $this->expectException(\InvalidArgumentException::class);
        AdminUserTokenRepo::issue(2, 'Inactive owner', [AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND], now()->addDay());
    }

    public function test_gateway_requires_scoped_bearer_token_and_re_signs_a_tenant_checked_request(): void
    {
        $issued = AdminUserTokenRepo::issue(
            1,
            'Cloud Mail gateway',
            [AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND, AdminUserTokenRepo::ABILITY_CLOUD_MAIL_HEALTH],
            now()->addDays(30),
        );
        $plain = $issued['plain_text_token'];
        Http::fake([
            'http://mail-relay:3000/v1/send' => Http::response([
                'accepted'  => true,
                'messageId' => 'relay-message-1',
            ], 202),
            'http://mail-relay:3000/health' => Http::response(['status' => 'ok']),
        ]);

        $payload = [
            'from'          => ['address' => 'orders@mail.example.com', 'name' => 'Orders'],
            'to'            => [['address' => 'buyer@example.net']],
            'subject'       => 'Order update',
            'text'          => 'Your order is ready.',
            'senderContext' => [
                'source'     => 'attacker',
                'tenantId'   => 'another-id',
                'tenantCode' => 'another-tenant',
                'accountId'  => 'another-account',
                'category'   => 'compose',
            ],
        ];
        $request = Request::create(
            '/_longos/mail-relay/v1/send',
            'POST',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $response = (new CloudMailRelayController)->send($request, new MailSenderProfileService, $this->tenantResolver());

        self::assertSame(202, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        Http::assertSent(function (ClientRequest $request): bool {
            $body = json_decode($request->body(), true, 64, JSON_THROW_ON_ERROR);

            return $request->url() === 'http://mail-relay:3000/v1/send'
                && $request->hasHeader('X-Relay-Client', 'cloud-mail')
                && $request->hasHeader('X-Relay-Timestamp')
                && $request->hasHeader('X-Relay-Nonce')
                && $request->hasHeader('X-Relay-Signature')
                && ! $request->hasHeader('Authorization')
                && data_get($body, 'senderContext.source')     === 'cloud-mail'
                && data_get($body, 'senderContext.tenantId')   === 'single-store'
                && data_get($body, 'senderContext.tenantCode') === 'single-store'
                && data_get($body, 'senderContext.accountId')  === '1'
                && data_get($body, 'senderContext.category')   === 'compose';
        });

        $missingToken = Request::create(
            '/_longos/mail-relay/v1/send',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            401,
            (new CloudMailRelayController)->send($missingToken, new MailSenderProfileService, $this->tenantResolver())->getStatusCode(),
        );

        $health = Request::create(
            '/_longos/mail-relay/health',
            'GET',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain],
        );
        self::assertSame(200, (new CloudMailRelayController)->health($health, $this->tenantResolver())->getStatusCode());
    }

    public function test_reveal_endpoint_is_owner_scoped_and_never_cacheable(): void
    {
        $admin  = AdminUser::query()->findOrFail(1);
        $issued = AdminUserTokenRepo::issue(
            $admin,
            'Permanently retrievable token',
            [AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND],
            now()->addDays(30),
        );

        auth()->guard(AdminUser::AUTH_GUARD)->setUser($admin);
        $response = (new AccountController)->revealToken(
            Request::create('https://admin.example.com/admin/account/tokens/1/reveal', 'POST'),
            $issued['model'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(
            $issued['plain_text_token'],
            json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR)['data']['plain_text_token'],
        );
        self::assertSame(1, json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR)['data']['reveal_count']);

        auth()->guard(AdminUser::AUTH_GUARD)->setUser(AdminUser::query()->findOrFail(2));
        self::assertSame(404, (new AccountController)->revealToken(
            Request::create('https://admin.example.com/admin/account/tokens/1/reveal', 'POST'),
            $issued['model'],
        )->getStatusCode());
    }

    public function test_one_control_site_token_serves_all_active_tenants_after_storefront_domain_changes(): void
    {
        [$control, $shopA, $shopB] = $this->enableSaasFixture();
        $issued                    = AdminUserTokenRepo::issue(
            1,
            'Platform Cloud Mail gateway',
            [AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND],
            now()->addDays(30),
        );
        Http::fake([
            'http://mail-relay:3000/v1/send' => Http::response(['accepted' => true, 'messageId' => 'accepted'], 202),
        ]);

        foreach (['orders+shop-a@mail.example.com', 'orders+shop-b@mail.example.com'] as $address) {
            $response = (new CloudMailRelayController)->send(
                $this->sendRequest($issued['plain_text_token'], $address, 'https://admin.example.com/_longos/mail-relay/v1/send'),
                new MailSenderProfileService,
                $this->tenantResolver(),
            );
            self::assertSame(202, $response->getStatusCode());
        }

        // Storefront domains are routing metadata, not the mail identity. A
        // tenant keeps using the same stable +site-code sender after a rename.
        Domain::query()->where('site_id', $shopA->id)->update(['host' => 'shop-a-new.example.net']);
        $afterRename = (new CloudMailRelayController)->send(
            $this->sendRequest(
                $issued['plain_text_token'],
                'orders+shop-a@mail.example.com',
                'https://admin.example.com/_longos/mail-relay/v1/send',
            ),
            new MailSenderProfileService,
            $this->tenantResolver(),
        );
        self::assertSame(202, $afterRename->getStatusCode());

        $contexts = Http::recorded()->map(static function (array $record): array {
            return (array) data_get(json_decode($record[0]->body(), true), 'senderContext', []);
        })->all();
        self::assertSame(['shop-a', 'shop-b', 'shop-a'], array_column($contexts, 'tenantCode'));
        self::assertSame([$shopA->uuid, $shopB->uuid, $shopA->uuid], array_column($contexts, 'tenantId'));
        self::assertSame(['1', '1', '1'], array_column($contexts, 'accountId'));

        $shopB->forceFill(['status' => Site::STATUS_SUSPENDED])->save();
        $suspendedResponse = (new CloudMailRelayController)->send(
            $this->sendRequest(
                $issued['plain_text_token'],
                'orders+shop-b@mail.example.com',
                'https://admin.example.com/_longos/mail-relay/v1/send',
            ),
            new MailSenderProfileService,
            $this->tenantResolver(),
        );
        self::assertSame(403, $suspendedResponse->getStatusCode());

        // The very same credential is intentionally unusable through a child
        // domain: every Worker must bind only the control-site relay URL.
        $this->app->instance(config('multitenancy.current_tenant_container_key'), $shopA);
        $tenantHostResponse = (new CloudMailRelayController)->send(
            $this->sendRequest(
                $issued['plain_text_token'],
                'orders+shop-a@mail.example.com',
                'https://shop-a-new.example.net/_longos/mail-relay/v1/send',
            ),
            new MailSenderProfileService,
            $this->tenantResolver(),
        );
        self::assertSame(403, $tenantHostResponse->getStatusCode());
        Http::assertSentCount(3);

        $this->app->instance(config('multitenancy.current_tenant_container_key'), $control);
    }

    public function test_tenant_can_switch_sender_to_a_pre_signed_assigned_domain_without_a_new_token(): void
    {
        [$control, $shopA, $shopB] = $this->enableSaasFixture();
        config(['mail.relay.allowed_sender_domains' => [
            'mail.example.com',
            'shop-a.example.net',
            'shop-a-new.example.net',
            'brand-a.example.org',
            'mail.brand-a.example.org',
            'mail.nested.brand-a.example.org',
            'route-only.example.net',
        ]]);

        Domain::query()->create([
            'site_id'    => $shopA->id,
            'host'       => 'shop-a-new.example.net',
            'scheme'     => 'https',
            'is_primary' => false,
            'status'     => Domain::STATUS_ACTIVE,
            'origin'     => Domain::ORIGIN_PLATFORM,
        ]);
        Domain::query()->create([
            'site_id'    => $shopA->id,
            'host'       => 'www.brand-a.example.org',
            'scheme'     => 'https',
            'is_primary' => false,
            'status'     => Domain::STATUS_ACTIVE,
            'origin'     => Domain::ORIGIN_TENANT,
        ]);
        Domain::query()->create([
            'site_id'    => $shopA->id,
            'host'       => 'route-only.example.net',
            'scheme'     => 'https',
            'is_primary' => false,
            'status'     => Domain::STATUS_ACTIVE,
            'origin'     => Domain::ORIGIN_TENANT,
        ]);
        $assignments = [];
        foreach (['shop-a.example.net', 'shop-a-new.example.net', 'brand-a.example.org', 'mail.brand-a.example.org'] as $domain) {
            $assignments[$domain] = MailSenderDomain::query()->create([
                'site_id' => $shopA->id,
                'domain'  => $domain,
            ]);
        }
        MailSenderDomain::query()->create([
            'site_id' => $shopB->id,
            'domain'  => 'mail.nested.brand-a.example.org',
        ]);

        $this->app->instance(config('multitenancy.current_tenant_container_key'), $shopA);
        $senders = new MailSenderProfileService;
        self::assertSame([
            'mail.example.com',
            'shop-a.example.net',
            'shop-a-new.example.net',
            'brand-a.example.org',
            'mail.brand-a.example.org',
        ], $senders->availableDomains());
        self::assertSame([
            'orders+shop-a@mail.example.com',
            'orders@shop-a.example.net',
            'orders@shop-a-new.example.net',
            'orders@brand-a.example.org',
            'orders@mail.brand-a.example.org',
        ], $senders->suggestedAddresses(MailSenderProfileService::ORDERS));
        $senders->assertAuthorized('orders@shop-a-new.example.net');
        // The shop uses www.brand-a.example.org while its independent From
        // domain is the explicitly assigned brand-a.example.org apex.
        $senders->assertAuthorized('orders@brand-a.example.org');
        $senders->assertAuthorized('orders@mail.brand-a.example.org');
        self::assertNotContains('mail.nested.brand-a.example.org', $senders->availableDomains());
        self::assertNotContains('route-only.example.net', $senders->availableDomains());
        $senders->assertAuthorizedForSite('orders@mail.nested.brand-a.example.org', $shopB);

        try {
            $senders->assertAuthorizedForSite('orders@brand-a.example.org', $shopB);
            self::fail('A tenant must not claim another tenant sender domain.');
        } catch (\App\Mail\Exceptions\MailSenderAuthorizationException $exception) {
            self::assertStringContainsString('分配给当前租户', $exception->getMessage());
        }

        // Storefront routing and sender authorization are separate. Retiring
        // a shop host does not silently rewrite a working From identity.
        Domain::query()
            ->where('site_id', $shopA->id)
            ->where('host', 'shop-a.example.net')
            ->update(['status' => Domain::STATUS_RETIRED]);
        self::assertContains('shop-a.example.net', $senders->availableDomains());
        self::assertContains('brand-a.example.org', $senders->availableDomains());
        $assignments['shop-a.example.net']->delete();
        $senders = new MailSenderProfileService;
        self::assertNotContains('shop-a.example.net', $senders->availableDomains());

        $issued = AdminUserTokenRepo::issue(
            1,
            'Single platform Cloud Mail token',
            [AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND],
            now()->addDays(30),
        );
        Http::fake([
            'http://mail-relay:3000/v1/send' => Http::response(['accepted' => true, 'messageId' => 'domain-switch'], 202),
        ]);
        $this->app->instance(config('multitenancy.current_tenant_container_key'), $control);

        foreach (['orders@shop-a-new.example.net', 'orders@mail.brand-a.example.org'] as $address) {
            $response = (new CloudMailRelayController)->send(
                $this->sendRequest($issued['plain_text_token'], $address, 'https://admin.example.com/_longos/mail-relay/v1/send'),
                new MailSenderProfileService,
                $this->tenantResolver(),
            );
            self::assertSame(202, $response->getStatusCode());
        }
        Http::assertSent(static function (ClientRequest $request) use ($shopA): bool {
            $payload = json_decode($request->body(), true, 64, JSON_THROW_ON_ERROR);

            return data_get($payload, 'senderContext.tenantId')   === $shopA->uuid
                && data_get($payload, 'senderContext.tenantCode') === $shopA->code;
        });

        $assignments['brand-a.example.org']->delete();
        $assignments['mail.brand-a.example.org']->delete();
        $senders = new MailSenderProfileService;
        self::assertNotContains('brand-a.example.org', $senders->availableDomains($shopA));
        self::assertNotContains('mail.brand-a.example.org', $senders->availableDomains($shopA));
    }

    public function test_control_site_assigns_each_signed_sender_domain_to_only_one_tenant(): void
    {
        [$control, $shopA, $shopB] = $this->enableSaasFixture();
        config([
            'saas.http_management'                  => true,
            'mail.relay.allowed_sender_domains'     => ['mail.example.com', 'example-store.com'],
            'mail.relay.platform_sender_domain'     => 'mail.example.com',
        ]);
        auth()->guard(AdminUser::AUTH_GUARD)->setUser(AdminUser::query()->findOrFail(1));
        $controller = new SaasSiteController;
        $tenants    = $this->app->make(TenantManager::class);
        $senders    = new MailSenderProfileService;

        $assign = $controller->assignSenderDomain(
            Request::create(
                'https://admin.example.com/admin/saas/sites/' . $shopA->id . '/sender-domains',
                'POST',
                ['domain' => 'EXAMPLE-STORE.COM.'],
            ),
            $tenants,
            $senders,
            $shopA,
        );
        self::assertSame(201, $assign->getStatusCode());
        $assignment = MailSenderDomain::query()->where('domain', 'example-store.com')->firstOrFail();
        self::assertSame($shopA->id, $assignment->site_id);

        $duplicate = $controller->assignSenderDomain(
            Request::create(
                'https://admin.example.com/admin/saas/sites/' . $shopB->id . '/sender-domains',
                'POST',
                ['domain' => 'example-store.com'],
            ),
            $tenants,
            $senders,
            $shopB,
        );
        self::assertSame(422, $duplicate->getStatusCode());
        self::assertStringContainsString(
            '其他租户',
            (string) data_get(json_decode($duplicate->getContent(), true), 'message'),
        );

        $remove = $controller->removeSenderDomain(
            Request::create(
                'https://admin.example.com/admin/saas/sites/' . $shopA->id . '/sender-domains/' . $assignment->id,
                'DELETE',
            ),
            $tenants,
            $shopA,
            $assignment,
        );
        self::assertSame(200, $remove->getStatusCode());
        self::assertFalse(MailSenderDomain::query()->where('domain', 'example-store.com')->exists());

        $this->app->instance(config('multitenancy.current_tenant_container_key'), $control);
    }

    public function test_integration_token_can_only_be_created_on_the_control_domain(): void
    {
        [$control, $shopA] = $this->enableSaasFixture();
        auth()->guard(AdminUser::AUTH_GUARD)->setUser(AdminUser::query()->findOrFail(1));
        $controller = new AccountController;
        $payload    = ['name' => 'Control only token', 'expires_days' => 365];
        $existing   = AdminUserTokenRepo::issue(
            1,
            'Existing control token',
            [AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND],
            now()->addDay(),
        )['model'];
        $before     = AdminUserToken::query()->count();

        $this->app->instance(config('multitenancy.current_tenant_container_key'), $shopA);
        $tenantResponse = $controller->storeToken(Request::create(
            'https://shop-a.example.net/admin/account/tokens',
            'POST',
            $payload,
        ));
        self::assertSame(403, $tenantResponse->getStatusCode());
        self::assertSame($before, AdminUserToken::query()->count());

        self::assertSame(403, $controller->revealToken(
            Request::create('https://shop-a.example.net/admin/account/tokens/' . $existing->id . '/reveal', 'POST'),
            $existing,
        )->getStatusCode());
        self::assertSame(403, $controller->destroyToken(
            Request::create('https://shop-a.example.net/admin/account/tokens/' . $existing->id, 'DELETE'),
            $existing,
        )->getStatusCode());

        $this->app->instance(config('multitenancy.current_tenant_container_key'), $control);
        $controlResponse = $controller->storeToken(Request::create(
            'https://admin.example.com/admin/account/tokens',
            'POST',
            $payload,
        ));
        self::assertSame(200, $controlResponse->getStatusCode());
        self::assertSame($before + 1, AdminUserToken::query()->count());
        self::assertSame(
            'https://admin.example.com/_longos/mail-relay',
            data_get(json_decode($controlResponse->getContent(), true), 'data.relay_url'),
        );
    }

    public function test_integration_token_dialog_uses_html_safe_vue_attributes(): void
    {
        $template = file_get_contents(resource_path('beike/admin/views/pages/account/index.blade.php'));

        self::assertNotFalse($template);
        self::assertDoesNotMatchRegularExpression('/:[\\w-]+="[^"\\r\\n]*@json\\(/', $template);
        self::assertStringContainsString(
            "<el-dialog title=\"{{ __('admin/account.create_cloud_mail_token') }}\"",
            $template,
        );
    }

    public function test_expired_token_and_unauthorized_sender_fail_before_private_relay(): void
    {
        $issued = AdminUserTokenRepo::issue(
            AdminUser::query()->findOrFail(1),
            'Short lived',
            [AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND],
            now()->addDay(),
        );
        $plain = $issued['plain_text_token'];
        Http::fake();

        $request = Request::create(
            '/_longos/mail-relay/v1/send',
            'POST',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'from'    => ['address' => 'orders@unsigned.example.net'],
                'to'      => [['address' => 'buyer@example.net']],
                'subject' => 'Blocked',
                'text'    => 'Blocked',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(
            403,
            (new CloudMailRelayController)->send($request, new MailSenderProfileService, $this->tenantResolver())->getStatusCode(),
        );
        Http::assertNothingSent();

        $internalFailureRequest = Request::create(
            '/_longos/mail-relay/v1/send',
            'POST',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'from'    => ['address' => 'orders@mail.example.com'],
                'to'      => ['buyer@example.net'],
                'subject' => 'Failure privacy',
                'text'    => 'Body',
            ], JSON_THROW_ON_ERROR),
        );
        $failingSenders = new class extends MailSenderProfileService
        {
            public function assertAuthorizedForSite(string $address, ?\App\Saas\Models\Site $site): void
            {
                throw new \RuntimeException('database-password=must-not-leak');
            }
        };
        $failureResponse = (new CloudMailRelayController)->send($internalFailureRequest, $failingSenders, $this->tenantResolver());

        self::assertSame(503, $failureResponse->getStatusCode());
        self::assertStringNotContainsString('must-not-leak', $failureResponse->getContent());
        self::assertStringContainsString('Unable to verify', $failureResponse->getContent());

        DB::table('admin_user_tokens')
            ->where('id', $issued['model']->id)
            ->update(['expires_at' => now()->subMinute()]);
        self::assertNull(AdminUserTokenRepo::getAdminUserTokenByToken($plain, AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND));
    }

    public function test_rollback_drops_unrecoverable_hashed_tokens_instead_of_exposing_them_to_legacy_code(): void
    {
        AdminUserTokenRepo::issue(
            1,
            'Rollback safety',
            [AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND],
            now()->addDay(),
        );

        (require base_path('database/migrations/2026_10_16_040000_add_reveal_audit_to_admin_user_tokens.php'))->down();
        (require base_path('database/migrations/2026_10_16_030000_add_encrypted_value_to_admin_user_tokens.php'))->down();
        (require base_path('database/migrations/2026_10_16_020000_harden_admin_user_tokens_for_integrations.php'))->down();

        self::assertSame(0, DB::table('admin_user_tokens')->count());
        self::assertFalse(Schema::hasColumn('admin_user_tokens', 'token_hash'));
        self::assertFalse(Schema::hasColumn('admin_user_tokens', 'token_ciphertext'));
        self::assertFalse(Schema::hasColumn('admin_user_tokens', 'last_revealed_at'));
        self::assertFalse(Schema::hasColumn('admin_user_tokens', 'reveal_count'));
        self::assertFalse(Schema::hasIndex('admin_user_tokens', ['admin_user_id']));
        self::assertFalse(Schema::hasColumn('admin_user_tokens', 'abilities'));
        self::assertTrue(Schema::hasColumn('admin_user_tokens', 'token'));
    }

    private function tenantResolver(): CloudMailTenantResolver
    {
        return $this->app->make(CloudMailTenantResolver::class);
    }

    /** @return array{0: Site, 1: Site, 2: Site} */
    private function enableSaasFixture(): array
    {
        config([
            'saas.enabled'              => true,
            'saas.control_domains'      => ['admin.example.com'],
            'database.connections.saas' => config('database.connections.integration_token_test'),
        ]);
        DB::purge('saas');
        $schema = Schema::connection('saas');
        $schema->create('saas_sites', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('database_name')->unique();
            $table->string('status', 24)->index();
            $table->string('locale', 16)->default('zh_cn');
            $table->string('timezone', 64)->default('Asia/Shanghai');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        $schema->create('saas_domains', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('host')->unique();
            $table->string('scheme', 8)->default('https');
            $table->boolean('is_primary')->default(false);
            $table->string('routing_behavior')->nullable();
            $table->string('status', 24)->index();
            $table->string('origin', 24)->default(Domain::ORIGIN_PLATFORM);
            $table->timestamps();
        });
        $schema->create('saas_mail_sender_domains', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('domain')->unique();
            $table->timestamps();
        });

        $control = Site::query()->create([
            'uuid'          => '04cf0115-cc86-4262-88e9-c7100872b5da',
            'code'          => 'control',
            'name'          => 'LONGOS Control',
            'database_name' => 'control',
            'status'        => Site::STATUS_ACTIVE,
            'meta'          => ['control_site' => true],
        ]);
        $shopA = Site::query()->create([
            'uuid'          => '3fe3414f-4b16-43d5-9658-23ddb08e0b28',
            'code'          => 'shop-a',
            'name'          => 'Shop A',
            'database_name' => 'shop_a',
            'status'        => Site::STATUS_ACTIVE,
            'meta'          => ['control_site' => false],
        ]);
        $shopB = Site::query()->create([
            'uuid'          => 'bc4650b5-870a-487a-82b0-cb675252ca28',
            'code'          => 'shop-b',
            'name'          => 'Shop B',
            'database_name' => 'shop_b',
            'status'        => Site::STATUS_ACTIVE,
            'meta'          => ['control_site' => false],
        ]);
        foreach ([
            [$control, 'admin.example.com'],
            [$shopA, 'shop-a.example.net'],
            [$shopB, 'shop-b.example.net'],
        ] as [$site, $host]) {
            Domain::query()->create([
                'site_id'    => $site->id,
                'host'       => $host,
                'scheme'     => 'https',
                'is_primary' => true,
                'status'     => Domain::STATUS_ACTIVE,
                'origin'     => Domain::ORIGIN_PLATFORM,
            ]);
        }

        $this->app->instance(config('multitenancy.current_tenant_container_key'), $control);

        return [$control, $shopA, $shopB];
    }

    private function sendRequest(string $token, string $from, string $url): Request
    {
        return Request::create(
            $url,
            'POST',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'from'          => ['address' => $from, 'name' => 'Orders'],
                'to'            => [['address' => 'buyer@example.net']],
                'subject'       => 'Order update',
                'text'          => 'Your order is ready.',
                'senderContext' => [
                    'tenantId'   => 'caller-controlled-id',
                    'tenantCode' => 'caller-controlled-code',
                    'accountId'  => 'caller-controlled-account',
                    'category'   => 'compose',
                ],
            ], JSON_THROW_ON_ERROR),
        );
    }
}
