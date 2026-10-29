<?php

namespace Tests\Unit;

use Beike\Mail\Transport\BrevoTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mime\Email;

class BrevoTransportTest extends TestCase
{
    public function test_it_submits_a_complete_transactional_payload_and_records_message_id(): void
    {
        $history = [];
        $stack   = HandlerStack::create(new MockHandler([
            new Response(201, ['content-type' => 'application/json'], json_encode(['messageId' => '<brevo-message-id>'])),
        ]));
        $stack->push(Middleware::history($history));
        $client    = new Client(['handler' => $stack]);
        $transport = new BrevoTransport('xkeysib-test', 15, $client);
        $html      = fopen('php://memory', 'r+');
        fwrite($html, '<p>Order confirmed</p>');
        rewind($html);
        $email     = (new Email)
            ->from('orders@example.com')
            ->to('customer@example.net')
            ->subject('Order confirmed')
            ->text('Order confirmed')
            ->html($html);
        $email->getHeaders()->addTextHeader('X-Longos-Delivery-ID', 'order:123');
        $email->getHeaders()->addTextHeader('X-BK-Mail-Type', 'order_new');

        $sentMessage = $transport->send($email);

        self::assertCount(1, $history);
        $request = $history[0]['request'];
        $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('https://api.brevo.com/v3/smtp/email', (string) $request->getUri());
        self::assertSame('xkeysib-test', $request->getHeaderLine('api-key'));
        self::assertSame('orders@example.com', $payload['sender']['email']);
        self::assertSame('customer@example.net', $payload['to'][0]['email']);
        self::assertSame('<p>Order confirmed</p>', $payload['htmlContent']);
        self::assertSame('Order confirmed', $payload['textContent']);
        self::assertSame('delivery_id:order:123|mail_type:order_new', $payload['headers']['X-Mailin-custom']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $payload['headers']['IdempotencyKey']);
        self::assertSame(['order_new'], $payload['tags']);
        self::assertSame('brevo-message-id', $sentMessage?->getOriginalMessage()->getHeaders()->get('X-Longos-Brevo-Message-ID')?->getBodyAsString());
        fclose($html);
    }

    public function test_it_surfaces_a_sanitized_provider_error(): void
    {
        $client    = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(401, ['content-type' => 'application/json'], json_encode(['code' => 'unauthorized', 'message' => 'Key not found'])),
        ]))]);
        $transport = new BrevoTransport('invalid-key', 15, $client);
        $email     = (new Email)
            ->from('orders@example.com')
            ->to('customer@example.net')
            ->subject('Order confirmed')
            ->text('Order confirmed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Brevo API 发送失败 [401]: Key not found');

        $transport->send($email);
    }

    public function test_it_accepts_a_provider_suppressed_idempotent_retry(): void
    {
        $client    = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(400, ['content-type' => 'application/json'], json_encode([
                'code'    => 'duplicate_parameter',
                'message' => 'duplicate key',
            ])),
        ]))]);
        $transport = new BrevoTransport('api-key', 15, $client);
        $email     = (new Email)
            ->from('orders@example.com')
            ->to('customer@example.net')
            ->subject('Order confirmed')
            ->text('Order confirmed');
        $email->getHeaders()->addTextHeader('X-Longos-Delivery-ID', 'order:123');

        $sentMessage = $transport->send($email);

        self::assertInstanceOf(\Symfony\Component\Mailer\SentMessage::class, $sentMessage);
    }
}
