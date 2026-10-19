<?php

namespace Tests\Feature;

use Beike\Shop\Providers\PluginServiceProvider;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Plugin\LongosLiveChat\Models\Conversation;
use Plugin\LongosLiveChat\Models\Message;
use Plugin\LongosLiveChat\Services\ChatService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LongosLiveChatServiceTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        app('translator')->addNamespace('LongosLiveChat', base_path('plugins/LongosLiveChat/Lang'));

        $this->databasePath = tempnam(sys_get_temp_dir(), 'longos-live-chat-');
        config([
            'database.default'                    => 'live_chat_test',
            'database.connections.live_chat_test' => [
                'driver'                  => 'sqlite',
                'database'                => $this->databasePath,
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('live_chat_test');
        DB::setDefaultConnection('live_chat_test');

        foreach ([
            '2026_08_30_000000_create_longos_live_chat_tables.php',
            '2026_08_30_000001_add_message_query_indexes.php',
            '2026_10_09_000002_harden_longos_live_chat.php',
            '2026_10_15_000003_add_live_chat_lifecycle_fields.php',
            '2026_10_15_000004_add_live_chat_retention_index.php',
        ] as $migration) {
            (require base_path('plugins/LongosLiveChat/Migrations/' . $migration))->up();
        }
    }

    protected function tearDown(): void
    {
        DB::disconnect('live_chat_test');
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_initialization_reuses_the_private_token_and_creates_one_welcome_message(): void
    {
        $service = new ChatService;
        $first   = $service->initialize('', null, " Welcome\r\nmessage ");
        $replay  = $service->initialize($first['token'], null, 'Different welcome');

        self::assertTrue($first['is_new']);
        self::assertFalse($replay['is_new']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{64}$/', $first['token']);
        self::assertSame($first['conversation']->id, $replay['conversation']->id);
        self::assertSame(1, Conversation::query()->count());
        self::assertSame(1, Message::query()->count());
        self::assertSame("Welcome\nmessage", Message::query()->value('body'));
        self::assertNotSame($first['token'], Conversation::query()->value('visitor_token'));
    }

    public function test_client_message_ids_make_visitor_and_admin_retries_idempotent(): void
    {
        $service      = new ChatService;
        $initialized  = $service->initialize('', null, '');
        $conversation = $initialized['conversation'];
        $visitorId    = (string) Str::uuid();
        $adminId      = (string) Str::uuid();

        $first = $service->sendVisitor($conversation, " Hello\0 world ", ' Alice ', 'ALICE@EXAMPLE.COM', null, $visitorId);
        $retry = $service->sendVisitor($conversation, 'Must not be duplicated', null, null, null, $visitorId);
        $reply = $service->sendAdmin($conversation, 'We are here.', 7, $adminId);
        $again = $service->sendAdmin($conversation, 'Must not be duplicated', 7, $adminId);

        self::assertTrue($first['created']);
        self::assertFalse($retry['created']);
        self::assertTrue($reply['created']);
        self::assertFalse($again['created']);
        self::assertSame(2, Message::query()->count());
        self::assertSame('Hello world', $first['message']->body);
        self::assertSame($first['message']->id, $retry['message']->id);
        self::assertSame($reply['message']->id, $again['message']->id);
        self::assertSame('Alice', $conversation->refresh()->visitor_name);
        self::assertSame('alice@example.com', $conversation->visitor_email);

        $service->close($conversation);
        self::assertSame(Conversation::STATUS_CLOSED, $conversation->refresh()->status);
        self::assertNotNull($conversation->closed_at);
        $service->sendVisitor($conversation, 'Hello world', null, null, null, $visitorId);
        self::assertSame(Conversation::STATUS_CLOSED, $conversation->refresh()->status);
        $service->sendVisitor($conversation, 'A new question', null, null, null, (string) Str::uuid());
        self::assertSame(Conversation::STATUS_OPEN, $conversation->refresh()->status);
        self::assertNull($conversation->closed_at);

        $service->close($conversation);
        $service->reopen($conversation);
        self::assertSame(Conversation::STATUS_OPEN, $conversation->refresh()->status);
        self::assertNull($conversation->closed_at);
    }

    public function test_authenticated_customer_change_rotates_the_browser_chat_identity(): void
    {
        $service       = new ChatService;
        $firstCustomer = (object) ['id' => 11, 'name' => 'First Customer', 'email' => 'first@example.com'];
        $nextCustomer  = (object) ['id' => 22, 'name' => 'Next Customer', 'email' => 'next@example.com'];
        $first         = $service->initialize('', $firstCustomer, 'Welcome');
        $next          = $service->initialize($first['token'], $nextCustomer, 'Welcome');

        self::assertNotSame($first['token'], $next['token']);
        self::assertNotSame($first['conversation']->id, $next['conversation']->id);
        self::assertSame(2, Conversation::query()->count());
        self::assertSame(11, $first['conversation']->customer_id);
        self::assertSame(22, $next['conversation']->customer_id);

        $guest = $service->initialize($next['token'], null, 'Welcome');
        self::assertNotSame($next['token'], $guest['token']);
        self::assertNotSame($next['conversation']->id, $guest['conversation']->id);
    }

    public function test_history_pages_and_read_receipts_only_cover_delivered_messages(): void
    {
        $service      = new ChatService;
        $initialized  = $service->initialize('', null, '');
        $conversation = $initialized['conversation'];

        foreach (range(1, 6) as $index) {
            $conversation->messages()->create([
                'sender_type' => Message::SENDER_VISITOR,
                'body'        => 'Message ' . $index,
            ]);
        }

        $latest = $service->messagePage($conversation, 0, 0, 3);
        self::assertTrue($latest['has_older']);
        self::assertSame(['Message 4', 'Message 5', 'Message 6'], $latest['messages']->pluck('body')->all());

        $service->markDelivered($conversation, $latest['messages'], Message::SENDER_ADMIN);
        self::assertSame(3, Message::query()->whereNotNull('read_at')->count());
        self::assertSame(3, Message::query()->whereNull('read_at')->count());

        $older = $service->messagePage($conversation, 0, (int) $latest['messages']->first()->id, 3);
        self::assertFalse($older['has_older']);
        self::assertSame(['Message 1', 'Message 2', 'Message 3'], $older['messages']->pluck('body')->all());

        $conversation->messages()->create([
            'sender_type' => Message::SENDER_ADMIN,
            'body'        => 'Unread reply',
        ]);
        $service->markConversationRead($conversation, Message::SENDER_VISITOR);
        self::assertSame(4, Message::query()->whereNotNull('read_at')->count());
        self::assertNotNull(Message::query()->where('body', 'Unread reply')->value('read_at'));
    }

    public function test_presence_and_rate_limit_scope_are_tenant_specific_and_fail_safe(): void
    {
        $service = new ChatService;

        self::assertFalse($service->adminOnline());
        $service->touchAdminPresence(9);
        self::assertTrue($service->adminOnline());
        self::assertSame(64, strlen($service->cacheScope()));
    }

    public function test_retention_command_only_prunes_expired_closed_conversations(): void
    {
        $expired = Conversation::query()->create([
            'public_id'       => (string) Str::uuid(),
            'visitor_token'   => hash('sha256', Str::random(64)),
            'status'          => Conversation::STATUS_CLOSED,
            'closed_at'       => now()->subDays(400),
            'last_message_at' => now()->subDays(400),
        ]);
        $expired->messages()->create(['sender_type' => Message::SENDER_VISITOR, 'body' => 'Expired']);

        $recent = Conversation::query()->create([
            'public_id'       => (string) Str::uuid(),
            'visitor_token'   => hash('sha256', Str::random(64)),
            'status'          => Conversation::STATUS_CLOSED,
            'closed_at'       => now()->subDays(30),
            'last_message_at' => now()->subDays(30),
        ]);
        $active = Conversation::query()->create([
            'public_id'       => (string) Str::uuid(),
            'visitor_token'   => hash('sha256', Str::random(64)),
            'status'          => Conversation::STATUS_OPEN,
            'closed_at'       => null,
            'last_message_at' => now(),
        ]);
        $staleOpen = Conversation::query()->create([
            'public_id'       => (string) Str::uuid(),
            'visitor_token'   => hash('sha256', Str::random(64)),
            'status'          => Conversation::STATUS_OPEN,
            'last_message_at' => now()->subDays(400),
        ]);

        self::assertSame(0, Artisan::call('longos:live-chat:prune', ['--days' => 365]));
        self::assertNull(Conversation::query()->find($expired->id));
        self::assertNotNull(Conversation::query()->find($recent->id));
        self::assertNotNull(Conversation::query()->find($active->id));
        self::assertNull(Conversation::query()->find($staleOpen->id));
        self::assertSame(0, Message::query()->where('conversation_id', $expired->id)->count());
    }

    public function test_visitor_access_requires_both_public_id_and_valid_private_token(): void
    {
        $service     = new ChatService;
        $initialized = $service->initialize('', null, '');

        self::assertSame(
            $initialized['conversation']->id,
            $service->visitorConversation($initialized['conversation']->public_id, $initialized['token'])->id,
        );

        foreach (['short', Str::random(64)] as $invalidToken) {
            try {
                $service->visitorConversation($initialized['conversation']->public_id, $invalidToken);
                self::fail('Expected invalid visitor credentials to be rejected.');
            } catch (NotFoundHttpException|ModelNotFoundException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_plugin_routes_are_post_initialized_and_tenant_guarded(): void
    {
        $provider = new PluginServiceProvider(app());
        $register = Closure::bind(function (): void {
            $this->pluginBasePath = base_path('plugins');
            $this->registerRoutes('LongosLiveChat', 'longos_live_chat');
        }, $provider, PluginServiceProvider::class);
        $register();

        $routes    = collect(Route::getRoutes()->getRoutes());
        $init      = $routes->first(static fn ($route): bool => $route->uri() === 'live-chat/init');
        $admin     = $routes->first(static fn ($route): bool => $route->uri() === admin_name() . '/live-chat/conversations');
        $read      = $routes->first(static fn ($route): bool => $route->uri() === 'live-chat/{conversation}/read');
        $heartbeat = $routes->first(static fn ($route): bool => $route->uri() === admin_name() . '/live-chat/heartbeat');

        self::assertNotNull($init);
        self::assertSame(['POST'], $init->methods());
        self::assertContains('shop', $init->gatherMiddleware());
        self::assertContains('plugin.enabled:longos_live_chat', $init->gatherMiddleware());
        self::assertContains('throttle:120,1', $init->gatherMiddleware());
        self::assertNotNull($admin);
        self::assertContains('plugin.enabled:longos_live_chat', $admin->gatherMiddleware());
        self::assertContains('throttle:240,1', $admin->gatherMiddleware());
        self::assertNotNull($read);
        self::assertSame(['POST'], $read->methods());
        self::assertNotNull($heartbeat);
        self::assertSame(['POST'], $heartbeat->methods());
    }

    public function test_hardening_migration_is_reentrant_and_reversible(): void
    {
        $migration = require base_path('plugins/LongosLiveChat/Migrations/2026_10_09_000002_harden_longos_live_chat.php');

        $migration->up();
        self::assertTrue(Schema::hasColumn('longos_live_chat_messages', 'client_message_id'));
        $migration->down();
        self::assertFalse(Schema::hasColumn('longos_live_chat_messages', 'client_message_id'));

        $legacyService = new ChatService;
        $initialized   = $legacyService->initialize('', null, '');
        $legacyMessage = $legacyService->sendVisitor(
            $initialized['conversation'],
            'Works during a rolling deployment',
            null,
            null,
            null,
            (string) Str::uuid(),
        );
        self::assertTrue($legacyMessage['created']);
        self::assertSame('Works during a rolling deployment', $legacyMessage['message']->body);

        $migration->up();
        self::assertTrue(Schema::hasColumn('longos_live_chat_messages', 'client_message_id'));

        $lifecycle = require base_path('plugins/LongosLiveChat/Migrations/2026_10_15_000003_add_live_chat_lifecycle_fields.php');
        $lifecycle->up();
        self::assertTrue(Schema::hasColumn('longos_live_chat_conversations', 'closed_at'));

        $retention = require base_path('plugins/LongosLiveChat/Migrations/2026_10_15_000004_add_live_chat_retention_index.php');
        $retention->up();
        $indexes = collect(Schema::getIndexes('longos_live_chat_conversations'))->pluck('name');
        self::assertContains('llc_conversations_created_index', $indexes);
        $retention->down();
        $indexes = collect(Schema::getIndexes('longos_live_chat_conversations'))->pluck('name');
        self::assertNotContains('llc_conversations_created_index', $indexes);
        $retention->up();
        $lifecycle->down();
        self::assertFalse(Schema::hasColumn('longos_live_chat_conversations', 'closed_at'));
        $lifecycle->up();
        self::assertTrue(Schema::hasColumn('longos_live_chat_conversations', 'closed_at'));
    }
}
