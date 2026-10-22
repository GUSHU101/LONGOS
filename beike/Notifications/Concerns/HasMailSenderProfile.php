<?php

namespace Beike\Notifications\Concerns;

use App\Mail\MailSenderProfileService;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Str;

trait HasMailSenderProfile
{
    /** Relay/network failures get bounded retries; the delivery id deduplicates accepted attempts. */
    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300, 900];

    /** Persisted with the queued notification, so retries remain idempotent. */
    protected ?string $mailDeliveryId = null;

    protected function initializeMailDeliveryId(): void
    {
        $this->mailDeliveryId = (string) Str::uuid();
    }

    protected function withSenderProfile(Mailable $mailable, string $category): Mailable
    {
        // The fallback supports jobs serialized before this field was added.
        $this->mailDeliveryId ??= (string) Str::uuid();
        $recipients = array_map(static function (mixed $recipient): string {
            if (is_array($recipient)) {
                return strtolower((string) ($recipient['address'] ?? array_key_first($recipient) ?? ''));
            }

            return strtolower((string) $recipient);
        }, (array) $mailable->to);
        sort($recipients);
        // A notification instance can technically target more than one
        // notifiable. Scope the stable notification id by recipients so one
        // accepted delivery cannot deduplicate a different recipient's job.
        $deliveryId = $this->deliveryIdForRecipients($recipients);

        return app(MailSenderProfileService::class)->apply($mailable, $category, $deliveryId);
    }

    /** Let the failure channel find the exact audit row without subject races. */
    public function mailDeliveryIdFor(mixed $notifiable): ?string
    {
        $recipient = is_object($notifiable) ? ($notifiable->email ?? null) : null;
        if (! is_string($recipient) || trim($recipient) === '') {
            return null;
        }

        return $this->deliveryIdForRecipients([strtolower(trim($recipient))]);
    }

    /** @param array<int, string> $recipients */
    private function deliveryIdForRecipients(array $recipients): string
    {
        $this->mailDeliveryId ??= (string) Str::uuid();
        $recipients = array_values(array_unique(array_filter(array_map(
            static fn (string $recipient): string => strtolower(trim($recipient)),
            $recipients,
        ))));
        sort($recipients);

        return $this->mailDeliveryId . ':' . substr(hash('sha256', implode(',', $recipients)), 0, 16);
    }
}
