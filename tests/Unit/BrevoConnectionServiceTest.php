<?php

namespace Tests\Unit;

use App\Mail\BrevoConnectionService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BrevoConnectionServiceTest extends TestCase
{
    public function test_it_verifies_the_account_and_sender_domain(): void
    {
        $service = new BrevoConnectionService($this->client([
            new Response(200, ['content-type' => 'application/json'], json_encode(['email' => 'owner@example.com'])),
            new Response(200, ['content-type' => 'application/json'], json_encode([
                'verified'      => true,
                'authenticated' => true,
            ])),
        ]));

        $result = $service->inspect('xkeysib-valid', 15, 'shop@example.com');

        self::assertTrue($result['connected']);
        self::assertSame('owner@example.com', $result['account_email']);
        self::assertSame('example.com', $result['sender_domain']);
        self::assertSame('ok', $result['sender_status']);
    }

    public function test_it_surfaces_a_safe_actionable_authentication_error(): void
    {
        $service = new BrevoConnectionService($this->client([
            new Response(401, ['content-type' => 'application/json'], json_encode([
                'code'    => 'unauthorized',
                'message' => 'Key not found',
            ])),
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Brevo 连接验证失败 [401]：Key not found');

        $service->inspect('invalid-key', 15);
    }

    /** @param array<int, Response> $responses */
    private function client(array $responses): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    }
}
