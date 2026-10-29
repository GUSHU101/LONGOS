<?php

namespace App\Mail;

use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Adapts the stable Cloud Mail gateway contract to the Brevo transport so the
 * Worker can keep its existing LONGOS URL/token while SMTP infrastructure is
 * removed from the deployment.
 */
class CloudMailBrevoGateway
{
    public function __construct(private readonly MailManager $mail) {}

    /** @return array{accepted:bool,messageId:string,provider:string} */
    public function send(array $payload): array
    {
        $from        = $this->address($payload['from'] ?? null, 'from');
        $to          = $this->addresses($payload['to'] ?? null, 'to', true);
        $cc          = $this->addresses($payload['cc'] ?? [], 'cc');
        $bcc         = $this->addresses($payload['bcc'] ?? [], 'bcc');
        $subject     = $this->header($payload['subject'] ?? '', 'subject', 998);
        $text        = $this->body($payload['text'] ?? null, 'text');
        $html        = $this->body($payload['html'] ?? null, 'html');
        $requestId   = $this->requestId($payload['requestId'] ?? null);
        $context     = is_array($payload['senderContext'] ?? null) ? $payload['senderContext'] : [];
        $category    = $this->header($context['category'] ?? 'cloud-mail', 'category', 64);
        $attachments = $this->attachments($payload['attachments'] ?? []);

        if ($subject === '') {
            throw new InvalidArgumentException('Message subject is required.');
        }
        if ($text === '' && $html === '') {
            throw new InvalidArgumentException('Message text or HTML is required.');
        }
        if (count($to) + count($cc) + count($bcc) > max(1, (int) config('mail.mailers.self_hosted.max_recipients', 50))) {
            throw new InvalidArgumentException('Recipient count exceeds the configured limit.');
        }

        $view = $html !== '' ? ['html' => new HtmlString($html)] : ['raw' => $text];
        $sent = $this->mail->mailer('brevo')->send($view, [], function (Message $message) use (
            $from,
            $to,
            $cc,
            $bcc,
            $subject,
            $text,
            $html,
            $requestId,
            $category,
            $attachments,
        ): void {
            $message->from($from['address'], $from['name']);
            foreach ($to as $recipient) {
                $message->to($recipient['address'], $recipient['name']);
            }
            foreach ($cc as $recipient) {
                $message->cc($recipient['address'], $recipient['name']);
            }
            foreach ($bcc as $recipient) {
                $message->bcc($recipient['address'], $recipient['name']);
            }
            $message->subject($subject);
            if ($html !== '' && $text !== '') {
                $message->getSymfonyMessage()->text($text);
            }
            $headers = $message->getSymfonyMessage()->getHeaders();
            $headers->addTextHeader('X-Longos-Delivery-ID', $requestId);
            $headers->addTextHeader('X-BK-Mail-Type', 'cloud_mail');
            $headers->addTextHeader('X-Longos-Sender-Category', $category ?: 'cloud-mail');
            foreach ($attachments as $attachment) {
                $message->attachData($attachment['content'], $attachment['name'], ['mime' => $attachment['mime']]);
            }
        });

        if (! $sent) {
            throw new RuntimeException('Brevo did not accept the message.');
        }
        $messageId = trim((string) $sent->getOriginalMessage()->getHeaders()->get('X-Longos-Brevo-Message-ID')?->getBodyAsString());

        return [
            'accepted'  => true,
            'messageId' => $messageId !== '' ? $messageId : $requestId,
            'provider'  => 'brevo',
        ];
    }

    /** @return array{status:string,provider:string} */
    public function health(): array
    {
        $apiKey = trim((string) config('mail.mailers.brevo.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('Brevo API Key is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['api-key' => $apiKey])
                ->connectTimeout(5)
                ->timeout(min(15, max(3, (int) config('mail.mailers.brevo.timeout', 15))))
                ->withoutRedirecting()
                ->get('https://api.brevo.com/v3/account');
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to connect to Brevo over HTTPS.', previous: $exception);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Brevo rejected the configured API Key.');
        }

        return ['status' => 'ok', 'provider' => 'brevo'];
    }

    /** @return array{address:string,name:string} */
    private function address(mixed $value, string $field): array
    {
        $item    = is_string($value) ? ['address' => $value] : (is_array($value) ? $value : []);
        $address = strtolower(trim((string) ($item['address'] ?? $item['email'] ?? '')));
        if (! filter_var($address, FILTER_VALIDATE_EMAIL) || strlen($address) > 320) {
            throw new InvalidArgumentException("Invalid {$field} address.");
        }

        return [
            'address' => $address,
            'name'    => $this->header($item['name'] ?? '', "{$field} name", 200),
        ];
    }

    /** @return array<int, array{address:string,name:string}> */
    private function addresses(mixed $value, string $field, bool $required = false): array
    {
        if (! is_array($value) || ($required && $value === [])) {
            throw new InvalidArgumentException("Invalid {$field} recipient list.");
        }
        $addresses = [];
        foreach ($value as $item) {
            $address                        = $this->address($item, $field);
            $addresses[$address['address']] = $address;
        }

        return array_values($addresses);
    }

    private function body(mixed $value, string $field): string
    {
        if ($value === null) {
            return '';
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("Message {$field} must be a string.");
        }

        return $value;
    }

    private function requestId(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            $value = (string) \Illuminate\Support\Str::uuid();
        }
        if (preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid requestId.');
        }

        return $value;
    }

    private function header(mixed $value, string $field, int $length): string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) > $length || preg_match('/[\r\n\x00]/', $value)) {
            throw new InvalidArgumentException("Invalid {$field}.");
        }

        return $value;
    }

    /** @return array<int, array{name:string,mime:string,content:string}> */
    private function attachments(mixed $value): array
    {
        if (! is_array($value) || count($value) > max(0, (int) config('mail.mailers.self_hosted.max_attachments', 25))) {
            throw new InvalidArgumentException('Invalid attachment list.');
        }
        $maximum = max(0, (int) config('mail.mailers.self_hosted.max_attachment_bytes', 20971520));
        $total   = 0;
        $result  = [];
        foreach ($value as $index => $item) {
            if (! is_array($item) || ! is_string($item['content'] ?? null)) {
                throw new InvalidArgumentException("Invalid attachment at index {$index}.");
            }
            $encoded = preg_replace('/\s+/', '', $item['content']);
            $content = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (! is_string($content) || base64_encode($content) !== $encoded) {
                throw new InvalidArgumentException("Invalid base64 attachment at index {$index}.");
            }
            $total += strlen($content);
            if ($total > $maximum) {
                throw new InvalidArgumentException('Attachments are too large.');
            }
            $name     = basename(str_replace('\\', '/', trim((string) ($item['filename'] ?? "attachment-{$index}"))));
            $mime     = $this->header($item['contentType'] ?? 'application/octet-stream', 'attachment content type', 200);
            $result[] = [
                'name'    => mb_substr($name !== '' ? $name : "attachment-{$index}", 0, 255),
                'mime'    => $mime !== '' ? $mime : 'application/octet-stream',
                'content' => $content,
            ];
        }

        return $result;
    }
}
