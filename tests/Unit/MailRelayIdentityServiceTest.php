<?php

namespace Tests\Unit;

use App\Mail\MailRelayIdentityService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MailRelayIdentityServiceTest extends TestCase
{
    private Container $container;

    private Factory $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container;
        Container::setInstance($this->container);
        $this->container->instance('config', new Repository([
            'mail' => [
                'mailers' => ['self_hosted' => [
                    'endpoint' => 'https://relay-api.example.net',
                    'secret'   => str_repeat('s', 64),
                    'timeout'  => 10,
                ]],
                'relay' => [
                    'hostname'      => 'mailout.example.com',
                    'dkim_selector' => 'mail2026',
                    'domains'       => 'example.com,mail.brand.test',
                    'public_ips'    => [],
                ],
            ],
        ]));
        $this->http = new Factory;
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_it_accepts_a_matching_signed_public_identity_and_reuses_it(): void
    {
        $this->http->fake([
            'https://relay-api.example.net/v1/identity' => $this->http->response($this->identity()),
        ]);
        $service = new MailRelayIdentityService($this->http);

        $identity = $service->identity();

        self::assertSame(['8.8.8.8'], $identity['public_ips']);
        self::assertSame('v=DKIM1; h=sha256; p=RVhBTVBMRQ==', $service->dkimValue('example.com', 'mail2026'));
        self::assertSame('v=DKIM1; h=sha256; p=QlJBTkQ=', $service->dkimValue('mail.brand.test', 'mail2026'));
        $this->http->assertSentCount(1);
        $this->http->assertSent(static function (Request $request): bool {
            $timestamp = $request->header('X-Relay-Timestamp')[0] ?? '';
            $nonce     = $request->header('X-Relay-Nonce')[0]     ?? '';
            $signature = $request->header('X-Relay-Signature')[0] ?? '';

            return $request->url() === 'https://relay-api.example.net/v1/identity'
                && $request->hasHeader('X-Relay-Client', 'longos')
                && hash_equals(hash_hmac('sha256', "{$timestamp}.{$nonce}.", str_repeat('s', 64)), $signature);
        });
    }

    public function test_it_fails_closed_when_the_remote_node_reports_a_different_identity(): void
    {
        $payload             = $this->identity();
        $payload['hostname'] = 'wrong.example.com';
        $this->http->fake([
            'https://relay-api.example.net/v1/identity' => $this->http->response($payload),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MAIL_HOSTNAME');
        (new MailRelayIdentityService($this->http))->identity();
    }

    public function test_it_rejects_private_or_missing_remote_egress_addresses(): void
    {
        $payload              = $this->identity();
        $payload['publicIps'] = ['127.0.0.1', '10.0.0.2', '203.0.113.10'];
        $this->http->fake([
            'https://relay-api.example.net/v1/identity' => $this->http->response($payload),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MAIL_PUBLIC_IPS');
        (new MailRelayIdentityService($this->http))->identity();
    }

    /** @return array<string, mixed> */
    private function identity(): array
    {
        return [
            'schema'         => 1,
            'status'         => 'ok',
            'hostname'       => 'mailout.example.com',
            'publicIps'      => ['8.8.8.8'],
            'dkimSelector'   => 'mail2026',
            'signingDomains' => ['example.com', 'mail.brand.test'],
            'records'        => [[
                'type'  => 'TXT',
                'name'  => 'mail2026._domainkey.example.com',
                'value' => 'v=DKIM1; h=sha256; p=RVhBTVBMRQ==',
            ], [
                'type'  => 'TXT',
                'name'  => 'mail2026._domainkey.mail.brand.test',
                'value' => 'v=DKIM1; h=sha256; p=QlJBTkQ=',
            ]],
        ];
    }
}
