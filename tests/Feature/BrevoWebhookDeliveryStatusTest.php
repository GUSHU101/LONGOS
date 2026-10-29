<?php

namespace Tests\Feature;

use App\Http\Controllers\BrevoWebhookController;
use App\Mail\BrevoSettings;
use Beike\Models\MailLog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

class BrevoWebhookDeliveryStatusTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->databasePath = tempnam(sys_get_temp_dir(), 'longos-brevo-webhook-');
        config([
            'database.default'                        => 'brevo_webhook_test',
            'database.connections.brevo_webhook_test' => [
                'driver'                  => 'sqlite',
                'database'                => $this->databasePath,
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'bk.system.base.brevo' => [
                'api_key'       => 'test-api-key',
                'timeout'       => 15,
                'webhook_token' => 'test-webhook-token',
            ],
        ]);
        DB::purge('brevo_webhook_test');
        DB::setDefaultConnection('brevo_webhook_test');

        foreach ([
            '2025_07_22_083341_create_mail_logs_table.php',
            '2025_07_22_095733_add_transport_to_mail_logs_table.php',
            '2026_10_15_020000_harden_mail_delivery_audit.php',
            '2026_10_22_000000_add_relay_receipts_to_mail_logs.php',
            '2026_10_29_150000_expand_mail_delivery_statuses.php',
            '2026_10_29_160000_repair_sqlite_mail_delivery_status_constraint.php',
        ] as $migration) {
            (require base_path('database/migrations/' . $migration))->up();
        }
    }

    protected function tearDown(): void
    {
        DB::disconnect('brevo_webhook_test');
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_invalid_email_event_marks_the_message_bounced(): void
    {
        $log = $this->log([
            'delivery_id'          => 'delivery:invalid-recipient',
            'transport_message_id' => 'brevo-message-1',
            'status'               => MailLog::STATUS_SENT,
        ]);

        $response = $this->dispatch([
            'event'           => 'invalid_email',
            'message-id'      => 'brevo-message-1',
            'email'           => 'invalid@example.net',
            'reason'          => 'Mailbox does not exist',
            'ts_event'        => 1_800_000_010,
            'X-Mailin-custom' => 'delivery_id=delivery:invalid-recipient',
        ]);

        self::assertSame(204, $response->getStatusCode());
        $log->refresh();
        self::assertSame(MailLog::STATUS_BOUNCED, $log->status);
        self::assertSame('Mailbox does not exist', $log->error_message);
        self::assertSame('invalid_email', $log->headers['_brevo']['event']);
        self::assertSame('invalid@example.net', $log->headers['_brevo']['recipient']);
    }

    public function test_stale_or_weaker_events_cannot_regress_delivery_state(): void
    {
        $log = $this->log([
            'delivery_id'          => 'delivery:ordered-events',
            'transport_message_id' => 'brevo-message-2',
            'status'               => MailLog::STATUS_DEFERRED,
            'error_message'        => 'Temporary provider delay',
            'headers'              => ['_brevo' => ['event' => 'deferred', 'event_at' => 200]],
        ]);

        $this->dispatch([
            'event'      => 'request',
            'message-id' => 'brevo-message-2',
            'ts_event'   => 100,
        ]);
        $log->refresh();
        self::assertSame(MailLog::STATUS_DEFERRED, $log->status);
        self::assertSame('Temporary provider delay', $log->error_message);
        self::assertSame('deferred', $log->headers['_brevo']['event']);

        $this->dispatch([
            'event'      => 'delivered',
            'message-id' => 'brevo-message-2',
            'ts_event'   => 300,
        ]);
        $log->refresh();
        self::assertSame(MailLog::STATUS_DELIVERED, $log->status);
        self::assertNull($log->error_message);

        $this->dispatch([
            'event'      => 'soft_bounce',
            'message-id' => 'brevo-message-2',
            'reason'     => 'late duplicate',
            'ts_event'   => 400,
        ]);
        $log->refresh();
        self::assertSame(MailLog::STATUS_DELIVERED, $log->status);
        self::assertNull($log->error_message);
        self::assertSame('delivered', $log->headers['_brevo']['event']);
    }

    public function test_webhook_rejects_missing_authentication_without_touching_the_log(): void
    {
        $log = $this->log([
            'transport_message_id' => 'brevo-message-3',
            'status'               => MailLog::STATUS_SENT,
        ]);
        $request = Request::create(
            '/_longos/mail/webhooks/brevo',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'event'      => 'delivered',
                'message-id' => 'brevo-message-3',
                'ts_event'   => 1_800_000_020,
            ], JSON_THROW_ON_ERROR),
        );

        $response = (new BrevoWebhookController)($request, app(BrevoSettings::class));

        self::assertSame(401, $response->getStatusCode());
        $log->refresh();
        self::assertSame(MailLog::STATUS_SENT, $log->status);
        self::assertSame([], $log->headers);
    }

    /** @param array<string, mixed> $payload */
    private function dispatch(array $payload): mixed
    {
        $request = Request::create(
            '/_longos/mail/webhooks/brevo',
            'POST',
            server: [
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer test-webhook-token',
            ],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return (new BrevoWebhookController)($request, app(BrevoSettings::class));
    }

    /** @param array<string, mixed> $overrides */
    private function log(array $overrides): MailLog
    {
        return MailLog::query()->forceCreate(array_merge([
            'delivery_id'     => null,
            'to_email'        => 'buyer@example.net',
            'from_email'      => 'orders@example.com',
            'subject'         => 'Webhook test',
            'content'         => '<p>Webhook test</p>',
            'mail_type'       => 'system_test',
            'transport'       => 'brevo',
            'status'          => MailLog::STATUS_PENDING,
            'attempts'        => 1,
            'headers'         => [],
            'attachments'     => [],
            'last_attempt_at' => now(),
        ], $overrides));
    }
}
