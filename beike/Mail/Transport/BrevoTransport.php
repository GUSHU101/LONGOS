<?php

namespace Beike\Mail\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class BrevoTransport extends AbstractTransport
{
    private const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    private ClientInterface $client;

    public function __construct(
        private readonly string $apiKey,
        int $timeout = 15,
        ?ClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);

        $this->client = $client ?? new Client([
            'connect_timeout' => min(10, max(3, $timeout)),
            'timeout'         => max(3, min(60, $timeout)),
            'verify'          => true,
            'http_errors'     => false,
            'allow_redirects' => false,
        ]);
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (! $email instanceof Email) {
            throw new \InvalidArgumentException('Brevo message must be a Symfony Email instance.');
        }
        if (trim($this->apiKey) === '') {
            throw new RuntimeException('Brevo API Key 未配置。');
        }

        try {
            $requestHeaders = [
                'accept'       => 'application/json',
                'api-key'      => $this->apiKey,
                'content-type' => 'application/json',
            ];
            $response   = $this->client->request('POST', self::ENDPOINT, [
                'http_errors'     => false,
                'allow_redirects' => false,
                'headers'         => $requestHeaders,
                'json'            => $this->payload($email),
            ]);
            $statusCode = $response->getStatusCode();
            $body       = json_decode((string) $response->getBody(), true);

            if ($statusCode === 400 && is_array($body) && ($body['code'] ?? '') === 'duplicate_parameter') {
                // A previous identical attempt was already accepted but its
                // HTTP response was lost. Treat provider-side de-duplication
                // as success; the signed webhook will reconcile final state.
                $this->getLogger()->info('Brevo suppressed a duplicate transactional email request.');

                return;
            }

            if ($statusCode !== 201 || ! is_array($body) || trim((string) ($body['messageId'] ?? '')) === '') {
                $reason = is_array($body) ? trim((string) ($body['message'] ?? $body['code'] ?? '')) : '';
                $reason = $reason !== '' ? mb_substr($reason, 0, 300) : 'provider rejected the request';

                throw new RuntimeException("Brevo API 发送失败 [{$statusCode}]: {$reason}");
            }

            $messageId = trim((string) $body['messageId'], " <>\t\n\r\0\x0B");
            $email->getHeaders()->remove('X-Longos-Brevo-Message-ID');
            $email->getHeaders()->addTextHeader('X-Longos-Brevo-Message-ID', $messageId);

            $this->getLogger()->info('Brevo accepted a transactional email.', [
                'message_id'      => $messageId,
                'recipient_count' => count($email->getTo()) + count($email->getCc()) + count($email->getBcc()),
            ]);
        } catch (\Throwable $exception) {
            $this->getLogger()->warning('Brevo transactional email submission failed.', [
                'exception'       => $exception::class,
                'recipient_count' => count($email->getTo()) + count($email->getCc()) + count($email->getBcc()),
            ]);

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function payload(Email $email): array
    {
        $from = $email->getFrom()[0] ?? null;
        if (! $from instanceof Address || ! filter_var($from->getAddress(), FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Brevo 发件人地址无效。');
        }
        if ($email->getTo() === []) {
            throw new \InvalidArgumentException('Brevo 收件人不能为空。');
        }

        $payload = [
            'sender'  => $this->address($from),
            'to'      => $this->addresses($email->getTo()),
            'subject' => (string) $email->getSubject(),
        ];

        if ($email->getCc() !== []) {
            $payload['cc'] = $this->addresses($email->getCc());
        }
        if ($email->getBcc() !== []) {
            $payload['bcc'] = $this->addresses($email->getBcc());
        }
        if (($replyTo = $email->getReplyTo()[0] ?? null) instanceof Address) {
            $payload['replyTo'] = $this->address($replyTo);
        }

        $html = $this->body($email->getHtmlBody());
        $text = $this->body($email->getTextBody());
        // Brevo's transactional endpoint accepts one inline body variant per
        // request. Laravel Markdown messages commonly contain both variants;
        // prefer HTML and retain text as the fallback for plain messages.
        if ($html !== '') {
            $payload['htmlContent'] = $html;
        } elseif ($text !== '') {
            $payload['textContent'] = $text;
        }
        if (! isset($payload['htmlContent']) && ! isset($payload['textContent'])) {
            throw new \InvalidArgumentException('Brevo 邮件正文不能为空。');
        }

        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $attachments[] = [
                'content' => base64_encode($this->body($attachment->getBody())),
                'name'    => $attachment->getFilename() ?: 'attachment',
            ];
        }
        if ($attachments !== []) {
            $payload['attachment'] = $attachments;
        }

        $deliveryId = $this->header($email, 'X-Longos-Delivery-ID');
        $mailType   = $this->header($email, 'X-BK-Mail-Type');
        $category   = $this->header($email, 'X-Longos-Sender-Category');
        $custom     = array_filter([
            $deliveryId !== '' ? 'delivery_id:' . $deliveryId : '',
            $mailType   !== '' ? 'mail_type:' . $mailType : '',
        ]);
        if ($custom !== []) {
            $payload['headers']                    = [];
            $payload['headers']['X-Mailin-custom'] = implode('|', $custom);
        }
        if ($deliveryId !== '') {
            // Brevo defines IdempotencyKey as a transactional-message header
            // inside the JSON payload, not as an HTTP request header. Reuse a
            // deterministic UUID for queue retries of the same logical mail.
            $payload['headers'] ??= [];
            $payload['headers']['IdempotencyKey'] = $this->idempotencyKey(
                $deliveryId . '|' . strtolower($from->getAddress()),
            );
        }
        $tags = array_values(array_filter(array_map([$this, 'tag'], [$mailType, $category])));
        if ($tags !== []) {
            $payload['tags'] = $tags;
        }

        return $payload;
    }

    /** @param Address[] $addresses */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $address): array => $this->address($address), $addresses);
    }

    private function address(Address $address): array
    {
        $result = ['email' => $address->getAddress()];
        if (trim($address->getName()) !== '') {
            $result['name'] = $address->getName();
        }

        return $result;
    }

    private function header(Email $email, string $name): string
    {
        $value = trim((string) $email->getHeaders()->get($name)?->getBodyAsString());

        return preg_match('/^[a-zA-Z0-9._:-]{1,128}$/', $value) === 1 ? $value : '';
    }

    private function tag(string $value): string
    {
        return trim((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', mb_substr($value, 0, 64)), '-');
    }

    /** @param resource|string|null $body */
    private function body(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }
        if (! is_resource($body)) {
            return '';
        }
        $metadata = stream_get_meta_data($body);
        if (($metadata['seekable'] ?? false) === true) {
            rewind($body);
        }

        return stream_get_contents($body) ?: '';
    }

    private function idempotencyKey(string $deliveryId): string
    {
        $hex     = substr(hash('sha256', 'longos-brevo:' . $deliveryId), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
