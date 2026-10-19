<?php

namespace Plugin\BeikeShopTestSandbox\Services;

use App\Saas\Support\TenantDeferred;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Reliable, non-sensitive Telegram notifications for TEST sandbox orders. */
class TelegramOrderNotificationService
{
    private const DEFAULT_ORDER_TEMPLATE = "🔔 店铺新订单\n订单号：{{order_id}}\n商品：{{product_name}}\n请及时处理。";

    public function queueNewOrder(string $orderId): void
    {
        if (! $this->enabled()) {
            return;
        }

        // Network delivery must never slow checkout or the 3DS state machine.
        TenantDeferred::terminating(function () use ($orderId): void {
            $this->deliverNewOrder($orderId);
        });
    }

    /** @return array{success: bool, message: string, status: array} */
    public function sendTestNotification(): array
    {
        if (! $this->configured()) {
            return $this->result(false, '请先保存有效的 Bot Token 和至少一个 Chat ID，再发送测试消息。');
        }

        return $this->deliver(
            "✅ Telegram 通知测试\n时间：" . now()->format('Y-m-d H:i:s') . "\n配置已生效，可以正常接收店铺新订单提醒。",
            'test'
        );
    }

    /** @return array{success: bool, message: string, status: array, chats?: list<array{id: string, label: string}>} */
    public function discoverChats(): array
    {
        if ($this->botToken() === '') {
            return $this->result(false, '请先保存 Bot Token，再识别 Chat ID。');
        }

        try {
            $response = $this->http()->get($this->endpoint('getUpdates'), [
                'limit'   => 100,
                'timeout' => 0,
            ]);
            $body = $response->json();
            if (! $response->successful() || ! is_array($body) || ($body['ok'] ?? false) !== true) {
                return $this->result(false, '无法读取机器人更新。请确认 Bot Token 正确，然后先向机器人或所在群组发送一条消息。');
            }

            $chats = [];
            foreach ($body['result'] ?? [] as $update) {
                $chat = $update['message']['chat'] ?? $update['channel_post']['chat'] ?? null;
                if (! is_array($chat) || empty($chat['id'])) {
                    continue;
                }

                $id   = (string) $chat['id'];
                $type = match ((string) ($chat['type'] ?? '')) {
                    'private'    => '私聊',
                    'group'      => '群组',
                    'supergroup' => '超级群',
                    'channel'    => '频道',
                    default      => '会话',
                };
                $name = trim((string) ($chat['title'] ?? implode(' ', array_filter([
                    $chat['first_name'] ?? '', $chat['last_name'] ?? '', $chat['username'] ?? '',
                ]))));
                $chats[$id] = ['id' => $id, 'label' => $type . ($name !== '' ? ' · ' . $name : '')];
            }

            $chats = array_slice(array_values($chats), 0, 20);
            if ($chats === []) {
                return $this->result(false, '暂未发现会话。请先向机器人发送 /start，或将机器人加入群组/频道后发送一条消息。');
            }

            $summary = implode('；', array_map(
                static fn (array $chat): string => "{$chat['label']}：{$chat['id']}",
                $chats
            ));

            return [
                'success' => true,
                'message' => '已识别可用 Chat ID，请复制到“接收方 Chat ID”：' . $summary,
                'status'  => ['count' => count($chats)],
                'chats'   => $chats,
            ];
        } catch (\Throwable $exception) {
            Log::warning('LONGOS TEST Sandbox Telegram chat discovery failed.', [
                'exception' => $exception::class,
            ]);

            return $this->result(false, '识别 Chat ID 时发生网络错误，请稍后重试。');
        }
    }

    /** @return array{enabled: bool, configured: bool, recipientCount: int, templateReady: bool, lastDelivery: array|null} */
    public function status(): array
    {
        $last = Cache::get('beikeshop_test:telegram:last_delivery');

        return [
            'enabled'        => $this->enabled(),
            'configured'     => $this->configured(),
            'recipientCount' => count($this->recipients()),
            'templateReady'  => $this->orderTemplate() !== '',
            'lastDelivery'   => is_array($last) ? $last : null,
        ];
    }

    private function deliverNewOrder(string $orderId): void
    {
        if (! $this->configured()) {
            Log::warning('LONGOS TEST Sandbox Telegram notification is enabled but incomplete.');

            return;
        }

        $dedupeKey = 'beikeshop_test:telegram:delivered:' . hash('sha256', $orderId);
        if (Cache::has($dedupeKey)) {
            return;
        }

        $result = $this->deliver($this->renderOrderMessage($orderId), 'new_order', $orderId);
        if ($result['success']) {
            Cache::put($dedupeKey, true, now()->addDays(30));
        }
    }

    /** @return array{success: bool, message: string, status: array} */
    private function deliver(string $message, string $kind, ?string $orderId = null): array
    {
        $recipients = $this->recipients();
        if ($recipients === []) {
            return $this->result(false, '未找到可用的 Chat ID。');
        }

        $delivered = 0;
        $statuses  = [];
        foreach ($recipients as $chatId) {
            $outcome    = $this->sendToRecipient($chatId, $message);
            $statuses[] = $outcome['status'];
            $delivered += $outcome['success'] ? 1 : 0;
        }

        $success = $delivered === count($recipients);
        $report  = [
            'kind'       => $kind,
            'success'    => $success,
            'delivered'  => $delivered,
            'recipients' => count($recipients),
            'statuses'   => $statuses,
            'at'         => now()->toIso8601String(),
        ];
        Cache::put('beikeshop_test:telegram:last_delivery', $report, now()->addDays(7));

        if (! $success) {
            Log::warning('LONGOS TEST Sandbox Telegram notification delivery failed.', [
                'kind'       => $kind,
                'order_id'   => $orderId,
                'delivered'  => $delivered,
                'recipients' => count($recipients),
                'statuses'   => $statuses,
            ]);
        }

        return $this->result(
            $success,
            $success
                ? "通知已发送至 {$delivered} 个接收方。"
                : "发送失败：仅成功发送至 {$delivered}/" . count($recipients) . ' 个接收方。请检查 Token、Chat ID 与机器人权限。',
            $report
        );
    }

    /** @return array{success: bool, status: int} */
    private function sendToRecipient(string $chatId, string $message): array
    {
        $attempts = $this->retryAttempts();
        $status   = 0;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->http()->post($this->endpoint(), [
                    'chat_id'                  => $chatId,
                    'text'                     => $message,
                    'disable_web_page_preview' => true,
                ]);
                $status = $response->status();
                $body   = $response->json();

                if ($response->successful() && ($body['ok'] ?? false) === true) {
                    return ['success' => true, 'status' => $status];
                }

                if (! $this->retryable($status) || $attempt === $attempts) {
                    break;
                }

                $this->backoff($attempt, $status, is_array($body) ? $body : []);
            } catch (\Throwable $exception) {
                // Exception messages may embed the Bot API URL and token.
                $status = 0;
                if ($attempt === $attempts) {
                    Log::warning('LONGOS TEST Sandbox Telegram transport failure.', [
                        'exception' => $exception::class,
                    ]);

                    break;
                }
                $this->backoff($attempt, 0, []);
            }
        }

        return ['success' => false, 'status' => $status];
    }

    private function renderOrderMessage(string $orderId): string
    {
        $message = strtr($this->orderTemplate(), [
            '{{order_id}}'     => $orderId,
            '{{product_name}}' => $this->setting('telegram_product_name', (string) config('beikeshop_test.telegram.product_name', '招牌套餐')),
            '{{created_at}}'   => now()->format('Y-m-d H:i:s'),
        ]);

        return mb_strimwidth(trim($message), 0, 4096, '');
    }

    private function orderTemplate(): string
    {
        return $this->setting(
            'telegram_message_template',
            (string) config('beikeshop_test.telegram.message_template', self::DEFAULT_ORDER_TEMPLATE)
        ) ?: self::DEFAULT_ORDER_TEMPLATE;
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $raw        = $this->setting('telegram_chat_id', (string) config('beikeshop_test.telegram.chat_id', ''));
        $recipients = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            array_map('trim', $recipients),
            static fn (string $chatId): bool => (bool) preg_match('/^(?:-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/', $chatId)
        )));
    }

    private function endpoint(string $method = 'sendMessage'): string
    {
        return 'https://api.telegram.org/bot' . rawurlencode($this->botToken()) . '/' . $method;
    }

    private function http(): PendingRequest
    {
        $configuredBundle = trim((string) config('beikeshop_test.telegram.ca_bundle', ''));
        $caBundle         = $configuredBundle !== '' ? $configuredBundle : CaBundle::getSystemCaRootBundlePath();

        return Http::asJson()
            ->acceptJson()
            ->connectTimeout($this->connectTimeout())
            ->timeout($this->timeout())
            ->withOptions(['verify' => $caBundle]);
    }

    private function retryable(int $status): bool
    {
        return $status === 0 || $status === 429 || $status >= 500;
    }

    private function backoff(int $attempt, int $status, array $body): void
    {
        $serverDelay  = (int) ($body['parameters']['retry_after'] ?? 0);
        $milliseconds = $status === 429 && $serverDelay > 0
            ? min(30000, $serverDelay * 1000)
            : min(3000, 250 * (2 ** ($attempt - 1)));

        usleep($milliseconds * 1000);
    }

    private function enabled(): bool
    {
        $value = function_exists('plugin_setting')
            ? plugin_setting('beike_shop_test_sandbox.telegram_enabled', null)
            : null;

        return $value === null || $value === ''
            ? (bool) config('beikeshop_test.telegram.enabled', false)
            : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function configured(): bool
    {
        return $this->botToken() !== '' && $this->recipients() !== [];
    }

    private function botToken(): string
    {
        return $this->setting('telegram_bot_token', (string) config('beikeshop_test.telegram.bot_token', ''));
    }

    private function setting(string $name, string $fallback): string
    {
        $value = function_exists('plugin_setting')
            ? plugin_setting('beike_shop_test_sandbox.' . $name, null)
            : null;

        return $value === null || trim((string) $value) === ''
            ? trim($fallback)
            : trim((string) $value);
    }

    private function timeout(): int
    {
        return max(1, min(15, (int) config('beikeshop_test.telegram.timeout_seconds', 5)));
    }

    private function connectTimeout(): int
    {
        return max(1, min($this->timeout(), (int) config('beikeshop_test.telegram.connect_timeout_seconds', 3)));
    }

    private function retryAttempts(): int
    {
        return max(1, min(3, (int) config('beikeshop_test.telegram.retry_attempts', 2)));
    }

    /** @return array{success: bool, message: string, status: array} */
    private function result(bool $success, string $message, array $status = []): array
    {
        return ['success' => $success, 'message' => $message, 'status' => $status];
    }
}
