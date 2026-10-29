<?php

namespace App\Http\Controllers;

use App\Mail\BrevoSettings;
use Beike\Models\MailLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class BrevoWebhookController extends Controller
{
    public function __invoke(Request $request, BrevoSettings $settings): Response
    {
        $expected = $settings->webhookToken();
        $provided = trim((string) $request->bearerToken());
        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response('', 401);
        }

        $payload   = $request->json()->all();
        $event     = strtolower(trim((string) ($payload['event'] ?? '')));
        $messageId = trim((string) ($payload['message-id'] ?? $payload['messageId'] ?? ''), " <>\t\n\r\0\x0B");
        $delivery  = $this->deliveryId((string) ($payload['X-Mailin-custom'] ?? $payload['x-mailin-custom'] ?? ''));

        if ($event === '' || ($messageId === '' && $delivery === '')) {
            return response('', 204);
        }

        $query = MailLog::query()->where('transport', 'brevo');
        $log   = $messageId !== ''
            ? (clone $query)->where('transport_message_id', $messageId)->latest('id')->first()
            : null;
        if (! $log && $delivery !== '') {
            $log = (clone $query)->where('delivery_id', $delivery)->latest('id')->first();
        }
        if (! $log) {
            Log::info('Brevo webhook did not match a local mail log.', ['event' => $event]);

            return response('', 204);
        }

        $status = $this->statusFor($event);
        if ($status !== null && $this->mayTransition((string) $log->status, $status)) {
            $log->status = $status;
        }
        if ($messageId !== '' && ! $log->transport_message_id) {
            $log->transport_message_id = mb_substr($messageId, 0, 255);
        }

        $headers            = is_array($log->headers) ? $log->headers : [];
        $headers['_brevo']  = [
            'event'    => mb_substr($event, 0, 64),
            'event_at' => (int) ($payload['ts_event'] ?? $payload['ts'] ?? time()),
        ];
        $log->headers       = $headers;
        $failure            = in_array($status, [MailLog::STATUS_DEFERRED, MailLog::STATUS_BOUNCED, MailLog::STATUS_BLOCKED], true);
        if ($status !== null) {
            $log->error_message = $failure
                ? MailLog::sanitizeError($payload['reason'] ?? $payload['message'] ?? $payload['subject'] ?? $event)
                : null;
        }
        $log->save();

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
            'hard_bounce', 'hardbounce', 'invalid' => MailLog::STATUS_BOUNCED,
            'blocked', 'spam'                     => MailLog::STATUS_BLOCKED,
            default                               => null,
        };
    }

    private function mayTransition(string $current, string $next): bool
    {
        if ($current === MailLog::STATUS_DELIVERED) {
            return false;
        }
        if (in_array($current, [MailLog::STATUS_BOUNCED, MailLog::STATUS_BLOCKED], true)) {
            return false;
        }

        return true;
    }
}
