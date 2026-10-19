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

    public function __construct(string $endpoint, string $secret, int $timeout = 30)
    {
        parent::__construct();

        $this->endpoint = rtrim(trim($endpoint), '/');
        $this->secret   = $secret;
        $this->validateConfiguration();
        $this->client = new Client([
            'timeout'         => max(1, min(60, $timeout)),
            'connect_timeout' => 10,
            'verify'          => true,
            'http_errors'     => false,
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

        return [
            'requestId'   => $this->deliveryId($email),
            'from'        => ['address' => $from->getAddress(), 'name' => $from->getName()],
            'to'          => $to,
            'cc'          => $cc,
            'bcc'         => $bcc,
            'subject'     => (string) $email->getSubject(),
            'text'        => $email->getTextBody(),
            'html'        => $email->getHtmlBody(),
            'headers'     => array_filter($headers, static fn (mixed $value): bool => is_string($value) && $value !== ''),
            'attachments' => array_map(static function ($attachment): array {
                return [
                    'filename'    => $attachment->getFilename() ?: 'attachment',
                    'contentType' => $attachment->getContentType(),
                    'content'     => base64_encode((string) $attachment->getBody()),
                    'disposition' => $attachment->getDisposition(),
                ];
            }, $email->getAttachments()),
            'senderContext' => [
                'source'       => 'longos',
                'tenantId'     => $site?->getKey() ? (string) $site->getKey() : null,
                'tenantCode'   => $site?->code,
                'category'     => $this->senderCategory($email),
            ],
        ];
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
}
