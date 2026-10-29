<?php

namespace App\Http\Controllers;

use App\Mail\BrevoSettings;
use Beike\Models\MailLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BrevoWebhookController extends Controller
{
    public function __invoke(Request $request, BrevoSettings $settings): Response
    {
        try {
            $expected = $settings->webhookToken();
        } catch (Throwable $exception) {
            Log::warning('Brevo webhook authentication is not available.', [
                'exception' => $exception::class,
            ]);

            return response('', 401);
        }
        $provided = trim((string) $request->bearerToken());
        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response('', 401);
        }

        $payload   = $request->json()->all();
        $event     = strtolower(trim($this->scalar($payload['event'] ?? '')));
        $messageId = trim($this->scalar($payload['message-id'] ?? $payload['messageId'] ?? ''), " <>\t\n\r\0\x0B");
        $delivery  = $this->deliveryId($this->scalar($payload['X-Mailin-custom'] ?? $payload['x-mailin-custom'] ?? ''));

        if ($event === '' || ($messageId === '' && $delivery === '')) {
            return response('', 204);
        }

        $status = $this->statusFor($event);
        if ($status === null) {
            return response('', 204);
        }

        $matched = DB::transaction(function () use ($messageId, $delivery, $status, $event, $payload): bool {
            $query = MailLog::query()->where('transport', 'brevo');
            $log   = $messageId !== ''
                ? (clone $query)->where('transport_message_id', $messageId)->latest('id')->lockForUpdate()->first()
                : null;
            if (! $log && $delivery !== '') {
                $log = (clone $query)->where('delivery_id', $delivery)->latest('id')->lockForUpdate()->first();
            }
            if (! $log) {
                return false;
            }

            $headers    = is_array($log->headers) ? $log->headers : [];
            $eventAt    = $this->eventTimestamp($payload);
            $previousAt = (int) data_get($headers, '_brevo.event_at', 0);
            $isCurrent  = $previousAt === 0 || $eventAt >= $previousAt;
            $transition = $isCurrent && $this->mayTransition((string) $log->status, $status);
            if ($transition) {
                $log->status = $status;
            }
            if ($messageId !== '' && ! $log->transport_message_id) {
                $log->transport_message_id = mb_substr($messageId, 0, 255);
            }

            if ($transition) {
                $headers['_brevo'] = [
                    'event'     => mb_substr($event, 0, 64),
                    'event_at'  => $eventAt,
                    'recipient' => mb_substr($this->scalar($payload['email'] ?? ''), 0, 255),
                ];
                $log->headers       = $headers;
                $failure            = in_array($status, [MailLog::STATUS_DEFERRED, MailLog::STATUS_BOUNCED, MailLog::STATUS_BLOCKED], true);
                $failureReason      = $this->scalar($payload['reason'] ?? $payload['message'] ?? $payload['subject'] ?? '');
                $log->error_message = $failure
                    ? MailLog::sanitizeError($failureReason !== '' ? $failureReason : $event)
                    : null;
            }
            $log->save();

            return true;
        });

        if (! $matched) {
            Log::info('Brevo webhook did not match a local mail log.', ['event' => $event]);
        }

        return response('', 204);
    }

    private function deliveryId(string $custom): string
    {
        parse_str(str_replace(';', '&', $custom), $values);
        $delivery = trim((string) ($values['delivery_id'] ?? ''));
        if ($delivery === '' && preg_match('/(?:^|[|;,&])\s*delivery_id\s*[:=]\s*([a-zA-Z0-9._:-]{1,128})/i', $custom, $matches) === 1) {
            $delivery = $matches[1];
        }

        return preg_match('/^[a-zA-Z0-9._:-]{1,128}$/', $delivery) === 1 ? $delivery : '';
    }

    private function statusFor(string $event): ?string
    {
        return match ($event) {
            'request', 'sent'                     => MailLog::STATUS_SENT,
            'delivered'                           => MailLog::STATUS_DELIVERED,
            'deferred', 'soft_bounce', 'softbounce', 'error' => MailLog::STATUS_DEFERRED,
            'hard_bounce', 'hardbounce', 'invalid', 'invalid_email' => MailLog::STATUS_BOUNCED,
            'blocked', 'spam'                     => MailLog::STATUS_BLOCKED,
            default                               => null,
        };
    }

    private function mayTransition(string $current, string $next): bool
    {
        if ($current === $next) {
            return true;
        }
        if ($current === MailLog::STATUS_DELIVERED) {
            return false;
        }
        if (in_array($current, [MailLog::STATUS_BOUNCED, MailLog::STATUS_BLOCKED], true)) {
            return false;
        }
        if ($current === MailLog::STATUS_DEFERRED && $next === MailLog::STATUS_SENT) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function eventTimestamp(array $payload): int
    {
        foreach (['ts_event', 'ts', 'ts_epoch'] as $key) {
            $value = $payload[$key] ?? null;
            if (! is_scalar($value) || ! is_numeric($value)) {
                continue;
            }
            $timestamp = (int) $value;
            if ($timestamp > 10_000_000_000) {
                $timestamp = (int) floor($timestamp / 1000);
            }
            if ($timestamp > 0) {
                return $timestamp;
            }
        }

        return time();
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
