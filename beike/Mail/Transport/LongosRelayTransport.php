<?php

namespace Beike\Mail\Transport;

use App\Mail\MailSenderProfileService;
use App\Saas\Models\Site;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * HMAC-authenticated transport for the bundled LONGOS relay.
 *
 * Unlike a trusted Docker-network SMTP connection, every submission is
 * authenticated, replay protected and assigned an idempotency key.
 */
class LongosRelayTransport extends AbstractTransport
{
    private Client $client;

    private string $endpoint;

    private string $secret;

    private int $maxRequestBytes;

    private int $maxAttachmentBytes;

    private int $maxAttachments;

    private int $maxRecipients;

    /** @param array<string, mixed> $limits */
    public function __construct(string $endpoint, string $secret, int $timeout = 30, array $limits = [])
    {
        parent::__construct();

        $this->endpoint           = rtrim(trim($endpoint), '/');
        $this->secret             = $secret;
        $this->maxRequestBytes    = $this->positiveLimit($limits['max_request_bytes'] ?? null, 32 * 1024 * 1024, 1024, 100 * 1024 * 1024);
        $this->maxAttachmentBytes = $this->positiveLimit($limits['max_attachment_bytes'] ?? null, 20 * 1024 * 1024, 1, $this->maxRequestBytes);
        $this->maxAttachments     = $this->positiveLimit($limits['max_attachments'] ?? null, 25, 1, 100);
        $this->maxRecipients      = $this->positiveLimit($limits['max_recipients'] ?? null, 50, 1, 500);
        $this->validateConfiguration();
        $this->client = new Client([
            'timeout'         => max(1, min(60, $timeout)),
            'connect_timeout' => 10,
            'verify'          => true,
            'http_errors'     => false,
            'allow_redirects' => false,
        ]);
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (! $email instanceof Email) {
            throw new TransportException('LONGOS relay only supports Symfony Email messages.');
        }

        $payload   = $this->payload($email);
        $rawBody   = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($rawBody) > $this->maxRequestBytes) {
            throw new TransportException('LONGOS relay request exceeds the configured size limit.');
        }
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(24));
        $signature = hash_hmac('sha256', "{$timestamp}.{$nonce}.{$rawBody}", $this->secret);

        try {
            $response = $this->client->post($this->endpoint . '/v1/send', [
                'headers' => [
                    'Accept'            => 'application/json',
                    'Content-Type'      => 'application/json',
                    'X-Relay-Client'    => 'longos',
                    'X-Relay-Timestamp' => $timestamp,
                    'X-Relay-Nonce'     => $nonce,
                    'X-Relay-Signature' => $signature,
                ],
                'body' => $rawBody,
            ]);
        } catch (GuzzleException $exception) {
            throw new TransportException('LONGOS relay connection failed.', 0, $exception);
        }

        $status = $response->getStatusCode();
        $result = json_decode((string) $response->getBody(), true);
        if ($status < 200 || $status >= 300 || ! is_array($result) || ($result['accepted'] ?? false) !== true) {
            $reason = is_array($result) ? trim((string) ($result['message'] ?? '')) : '';

            throw new TransportException('LONGOS relay rejected the message' . ($reason !== '' ? ": {$reason}" : '.'));
        }

        $this->recordRelayReceipt($email, $result);
    }

    /** @return array<string, mixed> */
    private function payload(Email $email): array
    {
        $from = $email->getFrom()[0] ?? null;
        if (! $from instanceof Address) {
            throw new TransportException('LONGOS relay requires a From address.');
        }

        try {
            app(MailSenderProfileService::class)->assertAuthorized($from->getAddress());
        } catch (\Throwable $exception) {
            throw new TransportException('LONGOS sender identity is not authorized: ' . $exception->getMessage(), 0, $exception);
        }

        $to  = $this->addresses($email->getTo());
        $cc  = $this->addresses($email->getCc());
        $bcc = $this->addresses($email->getBcc());
        if ($to === [] && $cc === [] && $bcc === []) {
            throw new TransportException('LONGOS relay requires at least one recipient.');
        }
        if (count(array_unique([...$to, ...$cc, ...$bcc])) > $this->maxRecipients) {
            throw new TransportException("LONGOS relay recipient count exceeds {$this->maxRecipients}.");
        }

        $headers = [];
        foreach (['In-Reply-To', 'References'] as $name) {
            if ($email->getHeaders()->has($name)) {
                $headers[strtolower($name)] = $email->getHeaders()->get($name)?->getBodyAsString();
            }
        }

        $replyTo = $email->getReplyTo()[0] ?? null;
        if ($replyTo instanceof Address) {
            $headers['reply-to'] = $replyTo->toString();
        }

        $site = Site::current();

        $attachments = $email->getAttachments();
        if (count($attachments) > $this->maxAttachments) {
            throw new TransportException("LONGOS relay attachment count exceeds {$this->maxAttachments}.");
        }
        $attachmentBytes    = 0;
        $encodedAttachments = [];
        foreach ($attachments as $attachment) {
            $content = (string) $attachment->getBody();
            $attachmentBytes += strlen($content);
            if ($attachmentBytes > $this->maxAttachmentBytes) {
                throw new TransportException('LONGOS relay attachments exceed the configured size limit.');
            }
            $encodedAttachments[] = [
                'filename'    => $attachment->getFilename() ?: 'attachment',
                'contentType' => $attachment->getContentType(),
                'content'     => base64_encode($content),
                'disposition' => $attachment->getDisposition(),
            ];
        }

        $payload = [
            'requestId'     => $this->deliveryId($email),
            'from'          => ['address' => $from->getAddress(), 'name' => $from->getName()],
            'to'            => $to,
            'cc'            => $cc,
            'bcc'           => $bcc,
            'subject'       => (string) $email->getSubject(),
            'text'          => $email->getTextBody(),
            'html'          => $email->getHtmlBody(),
            'headers'       => array_filter($headers, static fn (mixed $value): bool => is_string($value) && $value !== ''),
            'attachments'   => $encodedAttachments,
            'senderContext' => [
                'source'       => 'longos',
                'tenantId'     => $site?->getKey() ? (string) $site->getKey() : null,
                'tenantCode'   => $site?->code,
                'category'     => $this->senderCategory($email),
            ],
        ];

        // Symfony represents a missing alternative body as null. Keep the
        // relay contract unambiguous by omitting absent bodies instead of
        // serializing them as JSON null (plain-text test messages are a
        // normal and supported case).
        if ($payload['text'] === null) {
            unset($payload['text']);
        }
        if ($payload['html'] === null) {
            unset($payload['html']);
        }

        return $payload;
    }

    /** @param array<int, Address> $addresses
     *  @return array<int, string>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(static fn (Address $address): string => $address->getAddress(), $addresses));
    }

    private function senderCategory(Email $email): ?string
    {
        if (! $email->getHeaders()->has('X-Longos-Sender-Category')) {
            return null;
        }

        $value = $email->getHeaders()->get('X-Longos-Sender-Category')?->getBodyAsString();

        return is_string($value) ? $value : null;
    }

    private function deliveryId(Email $email): string
    {
        if ($email->getHeaders()->has('X-Longos-Delivery-Id')) {
            $value = $email->getHeaders()->get('X-Longos-Delivery-Id')?->getBodyAsString();
            if (is_string($value) && preg_match('/^[a-zA-Z0-9._:-]{1,128}$/', $value)) {
                return $value;
            }
        }

        return bin2hex(random_bytes(16));
    }

    private function validateConfiguration(): void
    {
        if (strlen($this->secret) < 32) {
            throw new TransportException('MAIL_RELAY_SHARED_SECRET must contain at least 32 characters.');
        }
        if (! filter_var($this->endpoint, FILTER_VALIDATE_URL)) {
            throw new TransportException('MAIL_RELAY_ENDPOINT must be a valid URL.');
        }

        if (parse_url($this->endpoint, PHP_URL_USER)        !== null
            || parse_url($this->endpoint, PHP_URL_PASS)     !== null
            || parse_url($this->endpoint, PHP_URL_QUERY)    !== null
            || parse_url($this->endpoint, PHP_URL_FRAGMENT) !== null) {
            throw new TransportException('MAIL_RELAY_ENDPOINT must not contain credentials, a query, or a fragment.');
        }

        $scheme = strtolower((string) parse_url($this->endpoint, PHP_URL_SCHEME));
        $host   = strtolower((string) parse_url($this->endpoint, PHP_URL_HOST));
        $local  = in_array($host, ['mail-relay', '127.0.0.1', 'localhost'], true);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $local)) {
            throw new TransportException('MAIL_RELAY_ENDPOINT must use HTTPS unless it targets the private mail-relay container.');
        }
    }

    public function __toString(): string
    {
        return 'longos-relay';
    }

    /** @param array<string, mixed> $result */
    private function recordRelayReceipt(Email $email, array $result): void
    {
        $submissionId = trim((string) ($result['submissionId'] ?? $result['queueId'] ?? ''));
        $messageId    = trim((string) ($result['messageId'] ?? ''));

        if (preg_match('/^[a-zA-Z0-9._:-]{1,128}$/', $submissionId) === 1) {
            $email->getHeaders()->remove('X-Longos-Relay-Submission-ID');
            $email->getHeaders()->addTextHeader('X-Longos-Relay-Submission-ID', $submissionId);
        }
        if ($messageId !== '' && strlen($messageId) <= 255 && preg_match('/[\x00-\x1F\x7F]/', $messageId) !== 1) {
            $email->getHeaders()->remove('X-Longos-Relay-Message-ID');
            $email->getHeaders()->addTextHeader('X-Longos-Relay-Message-ID', $messageId);
        }
    }

    private function positiveLimit(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new TransportException('LONGOS relay limits must be integers.');
        }

        $value = (int) $value;
        if ($value < $minimum || $value > $maximum) {
            throw new TransportException("LONGOS relay limit must be between {$minimum} and {$maximum}.");
        }

        return $value;
    }
}
