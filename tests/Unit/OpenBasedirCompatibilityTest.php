<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class OpenBasedirCompatibilityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 2);
    }

    public function test_cloudflare_config_only_reads_an_explicit_secret_path(): void
    {
        $config = (string) file_get_contents($this->root . '/config/longos.php');

        self::assertStringContainsString('$cloudflareTokenPaths = [];', $config);
        self::assertStringNotContainsString('$cloudflareTokenPaths = [\'/run/secrets/cloudflare_api_token\'];', $config);
        self::assertStringContainsString('! @is_readable($cloudflareTokenPath)', $config);
    }

    public function test_optional_external_paths_are_silent_under_open_basedir(): void
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';

use Beike\Services\VisitorCurrencyService;
use Beike\Shop\Services\AddressAutocompleteService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

$container = new Container;
Container::setInstance($container);
$container->instance('config', new Repository([
    'services' => ['address_lookup' => ['ca_bundle' => '/etc/ssl/certs/ca-certificates.crt']],
    'currency' => [
        'geolite2_path' => '/var/lib/GeoIP/GeoLite2-Country.mmdb',
        'trust_cdn_country_headers' => false,
        'country_currency' => [],
    ],
]));

$config = require 'config/longos.php';
$addressService = new AddressAutocompleteService;
$clientProperty = new ReflectionProperty($addressService, 'client');
$addressClient = $clientProperty->getValue($addressService);
$diagnostics = (new VisitorCurrencyService)->diagnostics();

echo json_encode([
    'token' => $config['cloudflare']['api_token_file_configured'],
    'geoip' => $diagnostics['local_database_installed'],
    'ca_bundle' => $addressClient->getConfig('verify'),
], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([
            PHP_BINARY,
            '-d',
            'display_errors=1',
            '-d',
            'error_reporting=32767',
            '-d',
            'open_basedir=' . $this->root,
            '-r',
            $script,
        ], $this->root, [
            'CLOUDFLARE_API_TOKEN_FILE' => '/run/secrets/cloudflare_api_token',
        ], null, 10);

        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($result['token']);
        self::assertFalse($result['geoip']);
        if (is_string($result['ca_bundle'])) {
            self::assertStringStartsWith(
                str_replace('\\', '/', $this->root),
                str_replace('\\', '/', $result['ca_bundle'])
            );
        } else {
            self::assertTrue($result['ca_bundle']);
        }
        self::assertStringNotContainsString('open_basedir restriction', $process->getOutput() . $process->getErrorOutput());
    }
}
