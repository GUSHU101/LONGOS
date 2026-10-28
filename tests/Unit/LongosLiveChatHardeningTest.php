<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LongosLiveChatHardeningTest extends TestCase
{
    public function test_live_chat_api_is_retry_safe_rate_limited_and_private(): void
    {
        $root       = dirname(__DIR__, 2);
        $shopRoutes = (string) file_get_contents($root . '/plugins/LongosLiveChat/Routes/shop.php');
        $shop       = (string) file_get_contents($root . '/plugins/LongosLiveChat/Controllers/ShopChatController.php');
        $service    = (string) file_get_contents($root . '/plugins/LongosLiveChat/Services/ChatService.php');
        $migration  = (string) file_get_contents($root . '/plugins/LongosLiveChat/Migrations/2026_10_09_000002_harden_longos_live_chat.php');
        $lifecycle  = (string) file_get_contents($root . '/plugins/LongosLiveChat/Migrations/2026_10_15_000003_add_live_chat_lifecycle_fields.php');
        $bootstrap  = (string) file_get_contents($root . '/plugins/LongosLiveChat/Bootstrap.php');

        self::assertStringContainsString("Route::post('init'", $shopRoutes);
        self::assertStringNotContainsString("Route::get('init'", $shopRoutes);
        self::assertStringContainsString("middleware('throttle:120,1')", $shopRoutes);
        self::assertStringContainsString("'Cache-Control'", $shop);
        self::assertStringContainsString("'no-store, private'", $shop);
        self::assertStringContainsString('RateLimiter::tooManyAttempts', $shop);
        self::assertStringContainsString("'client_message_id' => ['nullable', 'uuid']", $shop);
        self::assertStringContainsString('lockForUpdate()', $service);
        self::assertStringContainsString('firstOrCreate(', $service);
        self::assertStringContainsString('if ($ids->isEmpty())', $service);
        self::assertStringContainsString('if ($updated === 0)', $service);
        self::assertStringContainsString('llc_messages_conversation_client_unique', $migration);
        self::assertStringContainsString('llc_conversations_status_closed_index', $lifecycle);
        self::assertStringContainsString("Route::post('{conversation}/read'", $shopRoutes);
        self::assertStringContainsString('cacheScope()', $shop);
        self::assertStringContainsString("'shop.live_chat.init'", $bootstrap);
        self::assertStringContainsString("'shop.live_chat.messages'", $bootstrap);
        self::assertStringContainsString("'shop.live_chat.messages.store'", $bootstrap);
        self::assertStringContainsString("'shop.live_chat.read'", $bootstrap);
        self::assertStringContainsString("app('router')->has(\$route)", $bootstrap);
    }

    public function test_live_chat_interfaces_expose_recovery_history_and_accessibility_controls(): void
    {
        $root   = dirname(__DIR__, 2);
        $widget = (string) file_get_contents($root . '/plugins/LongosLiveChat/Views/shop/widget.blade.php');
        $admin  = (string) file_get_contents($root . '/plugins/LongosLiveChat/Views/admin/index.blade.php');

        self::assertStringContainsString('class="llc-close"', $widget);
        self::assertStringContainsString('aria-controls="longos-live-chat-panel"', $widget);
        self::assertStringContainsString('class="llc-history"', $widget);
        self::assertStringContainsString('client_message_id', $widget);
        self::assertStringContainsString('visibilitychange', $widget);
        self::assertStringContainsString('Math.pow(2', $widget);
        self::assertStringContainsString('backgroundPollMilliseconds', $widget);
        self::assertStringContainsString('timeout: 12000', $widget);
        self::assertStringContainsString('status(labels.sending)', $widget);
        self::assertStringContainsString('isNearBottom', $widget);
        self::assertStringContainsString('class="llc-unread"', $widget);
        self::assertStringNotContainsString('layer?.', $widget);
        self::assertStringContainsString('--llc-primary: #155eef', $widget);
        self::assertStringContainsString('.llc-panel > .llc-head', $widget);
        self::assertStringContainsString('background: var(--llc-primary) !important', $widget);
        self::assertStringContainsString('color: var(--llc-on-primary) !important', $widget);
        self::assertStringNotContainsString('background: var(--longos-primary', $widget);
        self::assertStringContainsString('loadOlder()', $admin);
        self::assertStringContainsString('conversationRequestVersion', $admin);
        self::assertStringContainsString('client_message_id', $admin);
        self::assertStringContainsString('@keydown.enter.exact.prevent="send"', $admin);
        self::assertStringContainsString('loadMoreConversations()', $admin);
        self::assertStringContainsString('heartbeat()', $admin);
        self::assertStringContainsString('reopenConversation()', $admin);
        self::assertStringContainsString('timeout: 12000', $admin);
        self::assertStringContainsString('schedulePolling', $admin);
        self::assertStringContainsString('Math.pow(2, Math.min(this.pollFailures, 4))', $admin);
        self::assertStringContainsString('this.conversations = merged.slice(0, retainedLimit)', $admin);
        self::assertStringContainsString('this._messageRequest?.abort()', $admin);
        self::assertStringContainsString('retrySend', $admin);
    }

    public function test_configuration_and_translations_cover_every_runtime_setting(): void
    {
        $root    = dirname(__DIR__, 2);
        $columns = require $root . '/plugins/LongosLiveChat/columns.php';
        $english = require $root . '/plugins/LongosLiveChat/Lang/en/chat.php';
        $chinese = require $root . '/plugins/LongosLiveChat/Lang/zh_cn/chat.php';
        $names   = array_column($columns, 'name');

        self::assertContains('poll_seconds', $names);
        self::assertContains('background_poll_seconds', $names);
        self::assertContains('retention_days', $names);
        self::assertSame([], array_diff(array_keys($english), array_keys($chinese)));
        self::assertSame([], array_diff(array_keys($chinese), array_keys($english)));
    }

    public function test_retention_is_one_day_activity_based_batched_and_scheduled_for_every_tenant(): void
    {
        $root    = dirname(__DIR__, 2);
        $command = (string) file_get_contents($root . '/app/Console/Commands/PruneLiveChat.php');
        $kernel  = (string) file_get_contents($root . '/app/Console/Kernel.php');

        self::assertStringContainsString("plugin_setting('longos_live_chat.retention_days', 1)", $command);
        self::assertStringContainsString('max(1, min(3650, $days))', $command);
        self::assertStringContainsString('->where(\'last_message_at\', \'<\', $cutoff)', $command);
        self::assertStringContainsString('->chunkById(500', $command);
        self::assertStringContainsString('saas:run longos:live-chat:prune --continue-on-error', $kernel);
        self::assertStringContainsString("schedule->command('longos:live-chat:prune')", $kernel);
        self::assertStringContainsString('->hourlyAt(15)', $kernel);
    }
}
