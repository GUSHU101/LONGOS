<?php

namespace Tests\Unit;

use App\Mail\BrevoSettings;
use App\Mail\BrevoWebhookManager;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class BrevoWebhookManagerTest extends TestCase
{
    public function test_it_creates_a_supported_authenticated_transactional_webhook(): void
    {
        $history = [];
        $stack   = HandlerStack::create(new MockHandler([
            new Response(200, ['content-type' => 'application/json'], json_encode(['webhooks' => []])),
            new Response(201, ['content-type' => 'application/json'], json_encode(['id' => 42])),
        ]));
        $stack->push(Middleware::history($history));
        $settings = $this->createMock(BrevoSettings::class);
        $settings->method('runtime')->willReturn([
            'api_key'       => 'xkeysib-test',
            'timeout'       => 15,
            'webhook_token' => 'webhook-token',
        ]);
        $manager = new BrevoWebhookManager($settings, new Client(['handler' => $stack]));

        $result = $manager->sync('https://store.example.com/_longos/mail/webhooks/brevo');

        self::assertSame(42, $result['id']);
        self::assertTrue($result['created']);
        self::assertCount(2, $history);
        $payload = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('bearer', $payload['auth']['type']);
        self::assertSame('webhook-token', $payload['auth']['token']);
        self::assertContains('delivered', $payload['events']);
        self::assertContains('hardBounce', $payload['events']);
        self::assertNotContains('error', $payload['events']);
    }
}
