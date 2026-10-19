<?php

namespace Tests\Feature;

use Beike\Listeners\MailEventListener;
use Beike\Models\MailLog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage as LaravelSentMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Email;

class MailDeliveryAuditReliabilityTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->databasePath = tempnam(sys_get_temp_dir(), 'longos-mail-audit-');
        config([
            'database.default'                     => 'mail_audit_test',
            'database.connections.mail_audit_test' => [
                'driver'                  => 'sqlite',
                'database'                => $this->databasePath,
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'mail.default' => 'self_hosted',
        ]);
        DB::purge('mail_audit_test');
        DB::setDefaultConnection('mail_audit_test');

        foreach ([
            '2025_07_22_083341_create_mail_logs_table.php',
            '2025_07_22_095733_add_transport_to_mail_logs_table.php',
            '2026_10_15_020000_harden_mail_delivery_audit.php',
        ] as $migration) {
            (require base_path('database/migrations/' . $migration))->up();
        }
    }

    protected function tearDown(): void
    {
        DB::disconnect('mail_audit_test');
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_retries_reuse_one_audit_row_and_transport_acceptance_updates_it(): void
    {
        $listener = new MailEventListener;
        $first    = $this->email('delivery:tenant-7:order-12');
        $listener->handleMessageSending(new MessageSending($first));

        $mailLog = MailLog::query()->sole();
        self::assertSame(1, $mailLog->attempts);
        self::assertSame(MailLog::STATUS_PENDING, $mailLog->status);
        self::assertSame((string) $mailLog->id, $first->getHeaders()->get('X-Mail-Log-ID')->getBodyAsString());

        $retry = $this->email('delivery:tenant-7:order-12');
        $listener->handleMessageSending(new MessageSending($retry));

        self::assertSame(1, MailLog::query()->count());
        $mailLog->refresh();
        self::assertSame(2, $mailLog->attempts);
        self::assertSame((string) $mailLog->id, $retry->getHeaders()->get('X-Mail-Log-ID')->getBodyAsString());

        $sent = new LaravelSentMessage(new SymfonySentMessage($retry, Envelope::create($retry)));
        $listener->handleMessageSent(new MessageSent($sent));

        $mailLog->refresh();
        self::assertSame(MailLog::STATUS_SENT, $mailLog->status);
        self::assertNotNull($mailLog->sent_at);
        self::assertNull($mailLog->error_message);
    }

    public function test_retention_marks_abandoned_attempts_failed_and_prunes_only_expired_terminal_logs(): void
    {
        $stalePending = $this->log([
            'delivery_id'     => 'delivery:stale',
            'status'          => MailLog::STATUS_PENDING,
            'created_at'      => now()->subDays(2),
            'updated_at'      => now()->subDays(2),
            'last_attempt_at' => now()->subDays(2),
        ]);
        $expired = $this->log([
            'delivery_id' => 'delivery:expired',
            'status'      => MailLog::STATUS_SENT,
            'created_at'  => now()->subDays(40),
            'updated_at'  => now()->subDays(40),
            'sent_at'     => now()->subDays(40),
        ]);
        $recent = $this->log([
            'delivery_id' => 'delivery:recent',
            'status'      => MailLog::STATUS_SENT,
            'created_at'  => now()->subDays(2),
            'updated_at'  => now()->subDays(2),
            'sent_at'     => now()->subDays(2),
        ]);

        self::assertSame(0, Artisan::call('longos:mail-logs:prune', ['--days' => 30]));

        self::assertNull(MailLog::query()->find($expired->id));
        self::assertNotNull(MailLog::query()->find($recent->id));
        $stalePending->refresh();
        self::assertSame(MailLog::STATUS_FAILED, $stalePending->status);
        self::assertStringContainsString('未收到传输层确认', (string) $stalePending->error_message);
    }

    public function test_failure_sanitizer_redacts_credentials_and_caps_storage(): void
    {
        $error = 'POST https://alice:super-secret@example.com failed token=abc123 password=hunter2 Authorization: Bearer bearer-value {"signature":"signed-value"} ' . str_repeat('x', 2000);
        $safe  = MailLog::sanitizeError($error);

        self::assertStringNotContainsString('super-secret', $safe);
        self::assertStringNotContainsString('abc123', $safe);
        self::assertStringNotContainsString('hunter2', $safe);
        self::assertStringNotContainsString('bearer-value', $safe);
        self::assertStringNotContainsString('signed-value', $safe);
        self::assertLessThanOrEqual(1000, mb_strlen($safe));
    }

    private function email(string $deliveryId): Email
    {
        $email = (new Email)
            ->from('orders@example.com')
            ->to('buyer@example.net')
            ->subject('Order update')
            ->html('<p>Your order is ready.</p>');
        $email->getHeaders()->addTextHeader('X-BK-Mail-Type', 'order_update');
        $email->getHeaders()->addTextHeader('X-Longos-Delivery-Id', $deliveryId);

        return $email;
    }

    /** @param array<string, mixed> $overrides */
    private function log(array $overrides): MailLog
    {
        return MailLog::query()->forceCreate(array_merge([
            'to_email'        => 'buyer@example.net',
            'from_email'      => 'orders@example.com',
            'subject'         => 'Audit test',
            'content'         => '<p>Audit test</p>',
            'mail_type'       => 'system_test',
            'transport'       => 'self_hosted',
            'status'          => MailLog::STATUS_PENDING,
            'attempts'        => 1,
            'headers'         => [],
            'attachments'     => [],
            'last_attempt_at' => now(),
        ], $overrides));
    }
}
