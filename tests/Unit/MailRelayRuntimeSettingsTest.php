<?php

namespace Tests\Unit;

use App\Mail\MailRelayNodeProfile;
use App\Mail\MailRelayRuntimeSettings;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MailRelayRuntimeSettingsTest extends TestCase
{
    private string $directory;

    private string $path;

    private Container $container;

    /** @var array<string, mixed> */
    private array $environment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container;
        Container::setInstance($this->container);
        Facade::setFacadeApplication($this->container);
        $this->container->instance('config', new Repository);
        $this->container->instance('log', new NullLogger);
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-mail-runtime-' . bin2hex(random_bytes(8));
        $this->path      = $this->directory . DIRECTORY_SEPARATOR . 'relay.json';
        mkdir($this->directory, 0750, true);
        $this->environment = [
            'mode'                       => 'internal',
            'endpoint'                   => 'http://mail-relay:3000',
            'hostname'                   => 'mailout.example.com',
            'public_ips'                 => [],
            'auto_detect_public_ips'     => true,
            'domains'                    => ['example.com'],
            'allowed_sender_domains'     => ['example.com'],
            'platform_sender_domain'     => 'example.com',
            'dkim_selector'              => 'mail2026',
            'node'                       => MailRelayNodeProfile::defaults(),
        ];
        config([
            'mail.relay.longos_client_configured'     => true,
            'mail.relay.cloud_mail_client_configured' => true,
            'mail.relay.client_secrets_distinct'      => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_it_persists_and_applies_a_valid_external_topology_without_secrets(): void
    {
        $service = new MailRelayRuntimeSettings($this->path, $this->environment);
        $saved   = $service->save($this->externalInput() + [
            'longos_secret'     => 'must-not-be-written',
            'cloud_mail_secret' => 'must-not-be-written-either',
        ]);

        self::assertSame('external', $saved['mode']);
        self::assertSame(['8.8.8.8', '1.1.1.1'], $saved['public_ips']);
        self::assertFalse($saved['auto_detect_public_ips']);
        self::assertSame(8788, $saved['node']['relay_http_port']);
        self::assertSame('cloudflare/cloudflared:2026.10.5', $saved['node']['cloudflared_image']);
        self::assertSame('https://relay-api.example.net', config('mail.mailers.self_hosted.endpoint'));
        self::assertSame(['example.com', 'mail.brand.test'], config('mail.relay.allowed_sender_domains'));

        $contents = (string) file_get_contents($this->path);
        self::assertStringNotContainsString('must-not-be-written', $contents);
        self::assertStringNotContainsString('secret', strtolower($contents));

        $reloaded = new MailRelayRuntimeSettings($this->path, $this->environment);
        self::assertSame($saved, $reloaded->current());
        self::assertSame('control_panel', $reloaded->status()['source']);
    }

    public function test_it_rejects_unsafe_endpoints_private_ips_and_unsigned_sender_domains(): void
    {
        $service = new MailRelayRuntimeSettings($this->path, $this->environment);

        foreach ([
            ['endpoint' => 'http://relay-api.example.net'],
            ['endpoint'               => 'https://user:pass@relay-api.example.net'],
            ['endpoint'               => 'https://relay-api.example.net:8788'],
            ['public_ips'             => '10.0.0.8'],
            ['allowed_sender_domains' => "example.com\nunsigned.example.net"],
            ['node'                   => ['relay_http_port' => 443]],
            ['node'                   => ['cloudflared_image' => 'cloudflare/cloudflared:latest']],
            ['node'                   => ['relay_memory_limit' => '128m', 'relay_memory_reservation' => '256m']],
        ] as $override) {
            try {
                $service->save(array_replace($this->externalInput(), $override));
                self::fail('Unsafe topology should have been rejected.');
            } catch (InvalidArgumentException) {
                self::assertFileDoesNotExist($this->path);
            }
        }
    }

    public function test_reset_removes_the_override_and_restores_deployment_defaults(): void
    {
        $service = new MailRelayRuntimeSettings($this->path, $this->environment);
        $service->save($this->externalInput());

        $service->reset();

        self::assertFileDoesNotExist($this->path);
        self::assertSame($this->environment, $service->current());
        self::assertSame('http://mail-relay:3000', config('mail.mailers.self_hosted.endpoint'));
        self::assertSame('deployment', $service->status()['source']);
    }

    public function test_internal_mode_keeps_compose_identity_but_allows_a_public_ip_override(): void
    {
        $service = new MailRelayRuntimeSettings($this->path, $this->environment);
        $saved   = $service->save([
            'mode'                       => 'internal',
            'hostname'                   => 'attacker.example.net',
            'public_ips'                 => '8.8.8.8',
            'auto_detect_public_ips'     => false,
            'domains'                    => ['attacker.example.net'],
            'allowed_sender_domains'     => ['attacker.example.net'],
            'platform_sender_domain'     => 'attacker.example.net',
            'dkim_selector'              => 'changed',
        ]);

        self::assertSame('mailout.example.com', $saved['hostname']);
        self::assertSame(['example.com'], $saved['domains']);
        self::assertSame('mail2026', $saved['dkim_selector']);
        self::assertSame(['8.8.8.8'], $saved['public_ips']);
        self::assertFalse($saved['auto_detect_public_ips']);
    }

    public function test_a_corrupt_override_fails_back_to_environment_without_bricking_mail(): void
    {
        file_put_contents($this->path, '{broken');
        $service = new MailRelayRuntimeSettings($this->path, $this->environment);

        self::assertSame($this->environment, $service->current());
        self::assertSame('invalid', $service->status()['source']);
        self::assertNotNull($service->status()['error']);
    }

    public function test_it_migrates_schema_one_profiles_and_renders_a_secret_free_node_environment(): void
    {
        file_put_contents($this->path, json_encode([
            'schema' => 1,
            'values' => $this->externalInput(),
        ], JSON_THROW_ON_ERROR));

        $service = new MailRelayRuntimeSettings($this->path, $this->environment);
        $status  = $service->status();

        self::assertSame('control_panel', $status['source']);
        self::assertSame('ipv4', $status['values']['node']['inet_protocols']);
        self::assertContains('MAIL_NODE_PROFILE_VERSION=1', $status['node_environment']);
        self::assertContains('RELAY_HTTP_PORT=8788', $status['node_environment']);
        self::assertStringNotContainsString('SECRET', implode("\n", $status['node_environment']));
    }

    /** @return array<string, mixed> */
    private function externalInput(): array
    {
        return [
            'mode'                       => 'external',
            'endpoint'                   => 'https://relay-api.example.net/',
            'hostname'                   => 'mailout.example.com',
            'public_ips'                 => '8.8.8.8, 1.1.1.1',
            'auto_detect_public_ips'     => true,
            'domains'                    => "example.com\nmail.brand.test",
            'allowed_sender_domains'     => "example.com\nmail.brand.test",
            'platform_sender_domain'     => 'example.com',
            'dkim_selector'              => 'mail2026',
        ];
    }
}
