<?php

namespace Tests\Feature;

use App\Mail\CloudflareMailDnsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

class CloudflareMailDnsSyncTest extends TestCase
{
    private Application $app;

    private string $recordsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
        $this->recordsPath = storage_path('framework/testing/mail-dns-' . bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->recordsPath);
        config([
            'mail.relay.auto_configure_dns'   => true,
            'mail.relay.cloudflare_api_base'  => 'https://api.cloudflare.test/client/v4',
            'mail.relay.dns_records_path'     => $this->recordsPath,
            'mail.relay.dkim_selector'        => 'mail2026',
            'mail.relay.hostname'             => 'mailout.brand-a.com',
            'mail.relay.domains'              => 'brand-a.com,mail.brand-b.net',
            'mail.relay.public_ips'           => ['8.8.8.8', '127.0.0.1', '2001:db8::1'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->recordsPath);
        restore_error_handler();
        restore_exception_handler();
        Facade::clearResolvedInstances();
        $this->app->flush();
        parent::tearDown();
    }

    public function test_it_publishes_each_public_dkim_policy_and_unproxied_mail_host_record(): void
    {
        $this->writeDkim('brand-a.com');
        $this->writeDkim('mail.brand-b.net');

        Http::fake(function (ClientRequest $request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if ($request->method() === 'GET' && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/zones')) {
                $zones = [
                    'brand-a.com' => [['id' => 'zone-a', 'name' => 'brand-a.com']],
                    'brand-b.net' => [['id' => 'zone-b', 'name' => 'brand-b.net']],
                ];

                return Http::response(['success' => true, 'result' => $zones[$query['name'] ?? ''] ?? []]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => []]);
            }

            return Http::response(['success' => true, 'result' => ['id' => 'created']]);
        });

        $result = (new CloudflareMailDnsService('cloudflare-test-token'))->sync();

        self::assertCount(7, $result['created']);
        self::assertSame([], $result['updated']);
        self::assertSame([], $result['warnings']);
        self::assertContains('TXT mail2026._domainkey.brand-a.com', $result['created']);
        self::assertContains('TXT mail2026._domainkey.mail.brand-b.net', $result['created']);
        self::assertContains('A mailout.brand-a.com', $result['created']);

        Http::assertSent(static function (ClientRequest $request): bool {
            $payload = $request->data();

            return $request->method() === 'POST'
                && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/zones/zone-a/dns_records')
                && ($payload['name'] ?? null) === 'mail2026._domainkey.brand-a.com'
                && str_starts_with((string) ($payload['content'] ?? ''), 'v=DKIM1;')
                && ($payload['proxied'] ?? null) === false
                && $request->hasHeader('Authorization', 'Bearer cloudflare-test-token');
        });
    }

    public function test_expected_records_expose_public_values_without_cloudflare_or_private_keys(): void
    {
        $this->writeDkim('brand-a.com', 'v=DKIM1; h=sha256; k=rsa; p=UFVCTElDS0VZ');

        $records = (new CloudflareMailDnsService)->expectedRecords();

        self::assertCount(7, $records);
        self::assertContains([
            'type'      => 'TXT',
            'name'      => 'mail2026._domainkey.brand-a.com',
            'value'     => 'v=DKIM1; h=sha256; k=rsa; p=UFVCTElDS0VZ',
            'mode'      => 'managed',
            'available' => true,
        ], $records);
        self::assertContains([
            'type'      => 'TXT',
            'name'      => 'brand-a.com',
            'value'     => 'v=spf1 a:mailout.brand-a.com -all',
            'mode'      => 'if_missing',
            'available' => true,
        ], $records);
        self::assertContains([
            'type'      => 'A',
            'name'      => 'mailout.brand-a.com',
            'value'     => '8.8.8.8',
            'mode'      => 'managed',
            'available' => true,
        ], $records);

        $pending = collect($records)->firstWhere('name', 'mail2026._domainkey.mail.brand-b.net');
        self::assertIsArray($pending);
        self::assertFalse($pending['available']);
        self::assertSame('', $pending['value']);
        Http::assertNothingSent();
    }

    public function test_it_updates_only_longos_dkim_and_preserves_existing_spf_and_dmarc(): void
    {
        config([
            'mail.relay.domains'    => 'brand-a.com',
            'mail.relay.public_ips' => [],
        ]);
        $this->writeDkim('brand-a.com', 'v=DKIM1; h=sha256; k=rsa; p=TkVX');

        Http::fake(function (ClientRequest $request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_ends_with($path, '/zones')) {
                return Http::response(['success' => true, 'result' => [['id' => 'zone-a', 'name' => 'brand-a.com']]]);
            }
            if ($request->method() === 'GET' && ($query['name'] ?? '') === 'mail2026._domainkey.brand-a.com') {
                return Http::response(['success' => true, 'result' => [[
                    'id'      => 'dkim-old',
                    'content' => 'v=DKIM1; p=T0xE',
                    'proxied' => false,
                ]]]);
            }
            if ($request->method() === 'GET' && ($query['name'] ?? '') === 'brand-a.com') {
                return Http::response(['success' => true, 'result' => [[
                    'id'      => 'spf-existing',
                    'content' => 'v=spf1 include:_spf.example.net -all',
                ], [
                    'id'      => 'spf-duplicate',
                    'content' => 'v=spf1 a -all',
                ]]]);
            }
            if ($request->method() === 'GET' && ($query['name'] ?? '') === '_dmarc.brand-a.com') {
                return Http::response(['success' => true, 'result' => [[
                    'id'      => 'dmarc-existing',
                    'content' => 'v=DMARC1; p=reject',
                ]]]);
            }

            return Http::response(['success' => true, 'result' => ['id' => 'updated']]);
        });

        $result = (new CloudflareMailDnsService('cloudflare-test-token'))->sync();

        self::assertSame(['TXT mail2026._domainkey.brand-a.com'], $result['updated']);
        self::assertContains('TXT brand-a.com', $result['preserved']);
        self::assertContains('TXT _dmarc.brand-a.com', $result['preserved']);
        self::assertCount(2, $result['warnings']);
        self::assertStringContainsString('多条 v=spf1', implode('\n', $result['warnings']));
        Http::assertSentCount(5);
        Http::assertNotSent(static function (ClientRequest $request): bool {
            return $request->method() === 'POST';
        });
    }

    public function test_command_skips_without_credentials_and_fails_safely_until_public_key_exists(): void
    {
        config(['longos.cloudflare.api_token_file' => '']);
        self::assertSame(0, Artisan::call('mail:sync-cloudflare-dns', ['--if-configured' => true]));

        $service = new CloudflareMailDnsService('cloudflare-test-token');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DKIM 公钥');
        $service->sync();
        Http::assertNothingSent();
    }

    public function test_command_serializes_startup_and_scheduler_syncs_across_containers(): void
    {
        $this->app->instance(
            CloudflareMailDnsService::class,
            new CloudflareMailDnsService('cloudflare-test-token'),
        );
        $lock = Cache::lock('longos:cloudflare-mail-dns-sync', 240);
        self::assertTrue($lock->get());

        try {
            self::assertSame(0, Artisan::call('mail:sync-cloudflare-dns', ['--json' => true]));
            self::assertStringContainsString('"status":"locked"', Artisan::output());
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_mutating_cloudflare_requests_are_not_retried_after_an_uncertain_timeout(): void
    {
        config([
            'mail.relay.domains'    => 'brand-a.com',
            'mail.relay.public_ips' => [],
        ]);
        $this->writeDkim('brand-a.com');
        Http::fakeSequence()
            ->push(['success' => true, 'result' => [['id' => 'zone-a', 'name' => 'brand-a.com']]])
            ->push(['success' => true, 'result' => []])
            ->pushFailedConnection('simulated uncertain POST timeout');

        try {
            (new CloudflareMailDnsService('cloudflare-test-token'))->sync();
            self::fail('An uncertain Cloudflare mutation must be reported for scheduled reconciliation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('暂时无法连接', $exception->getMessage());
        }

        Http::assertSentCount(3);
    }

    private function writeDkim(string $domain, string $value = 'v=DKIM1; h=sha256; k=rsa; p=UVVYRA=='): void
    {
        $directory = $this->recordsPath . DIRECTORY_SEPARATOR . $domain;
        File::ensureDirectoryExists($directory);
        File::put($directory . DIRECTORY_SEPARATOR . 'mail2026.txt', $value . PHP_EOL);
    }
}
