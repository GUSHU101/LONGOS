<?php

namespace Beike\Admin\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RemoteImageImporter
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    private ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client([
            'connect_timeout' => 5,
            'timeout'         => 12,
            'verify'          => config('beike.http_verify_ssl', true),
            'allow_redirects' => false,
            'http_errors'     => false,
        ]);
    }

    /**
     * Import remote editor images without allowing the application server to
     * become a proxy for private network resources.
     *
     * @return array<int, array{url: string, path: string, origin: string}>
     */
    public function import(array $sources, string $type = 'image'): array
    {
        return $this->importSources($sources, $type, $type);
    }

    /**
     * Store a group of remote images in a dedicated directory on the current
     * tenant upload disk. This is used by importers that own their files and
     * need to remove superseded images without touching File Manager uploads.
     *
     * @return array<int, array{url: string, path: string, origin: string}>
     */
    public function importInto(array $sources, string $directory, string $type = 'image'): array
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');
        if ($directory === ''
            || strlen($directory) > 80
            || str_contains($directory, '..')
            || ! preg_match('#^[a-z0-9][a-z0-9/_-]*$#i', $directory)
        ) {
            throw new RuntimeException(trans('admin/file_manager.invalid_upload_type'));
        }

        return $this->importSources($sources, $type, $directory);
    }

    /**
     * @return array<int, array{url: string, path: string, origin: string}>
     */
    private function importSources(array $sources, string $type, string $directory): array
    {
        if ($type !== 'image') {
            throw new RuntimeException(trans('admin/file_manager.invalid_upload_type'));
        }

        $images = [];
        foreach (array_values(array_unique($sources)) as $source) {
            try {
                $images[] = $this->importOne((string) $source, $directory);
            } catch (Throwable $e) {
                Log::warning('Remote editor image was not imported.', [
                    'source'    => $this->safeSourceForLog((string) $source),
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
            }
        }

        return $images;
    }

    /**
     * @return array{url: string, path: string, origin: string}
     */
    protected function importOne(string $source, string $directory): array
    {
        [$url, $host, $port, $address] = $this->validateSource($source);

        $options = [
            'stream'          => true,
            'allow_redirects' => false,
            'headers'         => [
                'User-Agent' => platform_brand_name() . '/remote-image-importer',
                'Accept'     => 'image/jpeg,image/png,image/gif,image/webp',
            ],
        ];

        // Keep TLS SNI and certificate verification on the original hostname,
        // while pinning the already validated public address to close the DNS
        // rebinding window between validation and connection.
        $pinnedAddress   = str_contains($address, ':') ? "[{$address}]" : $address;
        $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinnedAddress}"]];

        $response = $this->client->request('GET', $url, $options);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('Remote server returned a non-success status.');
        }

        $maximumBytes  = max(1024, (int) config('longos.remote_image_max_bytes', 10 * 1024 * 1024));
        $contentLength = (int) $response->getHeaderLine('Content-Length');
        if ($contentLength > $maximumBytes) {
            throw new RuntimeException('Remote image exceeds the configured size limit.');
        }

        $contents = '';
        $body     = $response->getBody();
        while (! $body->eof()) {
            $chunk = $body->read(min(8192, $maximumBytes + 1 - strlen($contents)));
            if ($chunk === '') {
                throw new RuntimeException('Remote image stream ended unexpectedly.');
            }
            $contents .= $chunk;
            if (strlen($contents) > $maximumBytes) {
                throw new RuntimeException('Remote image exceeds the configured size limit.');
            }
        }

        if ($contents === '') {
            throw new RuntimeException('Remote image response was empty.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (! is_string($mime) || ! isset(self::MIME_EXTENSIONS[$mime])) {
            throw new RuntimeException('Remote response is not a supported image.');
        }

        $dimensions = @getimagesizefromstring($contents);
        $width      = is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : 0;
        $height     = is_array($dimensions) ? (int) ($dimensions[1] ?? 0) : 0;
        if ($width < 1 || $height < 1 || $width > 16000 || $height > 16000 || ($width * $height) > 80000000) {
            throw new RuntimeException('Remote response contains an invalid image.');
        }

        $filename = Str::random(40) . '.' . self::MIME_EXTENSIONS[$mime];
        $relative = $directory . '/' . $filename;
        if (! Storage::disk('upload')->put($relative, $contents)) {
            throw new RuntimeException('Remote image could not be stored.');
        }

        $publicPath = tenant_upload_prefix() . '/' . $relative;

        return [
            'url'    => asset($publicPath),
            'path'   => $publicPath,
            'origin' => $source,
        ];
    }

    /**
     * @return array{string, string, int, string}
     */
    protected function validateSource(string $source): array
    {
        $source = trim($source);
        if ($source === '' || strlen($source) > 2048 || filter_var($source, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Remote image URL is invalid.');
        }

        $parts  = parse_url($source);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = trim(strtolower(rtrim((string) ($parts['host'] ?? ''), '.')), '[]');
        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! preg_match('/^[a-z0-9.:-]+$/i', $host)
        ) {
            throw new RuntimeException('Remote image URL is not allowed.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! in_array($port, [80, 443], true)) {
            throw new RuntimeException('Remote image port is not allowed.');
        }

        $addresses = $this->resolvePublicAddresses($host);
        if ($addresses === []) {
            throw new RuntimeException('Remote image host does not resolve to a public address.');
        }

        return [$source, $host, $port, $addresses[0]];
    }

    /**
     * Resolve every answer and fail closed if DNS exposes any non-public IP.
     *
     * @return array<int, string>
     */
    protected function resolvePublicAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicAddress($host) ? [$host] : [];
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($address) || ! $this->isPublicAddress($address)) {
                return [];
            }
            $addresses[] = $address;
        }

        // Prefer IPv4 for CURLOPT_RESOLVE compatibility while retaining IPv6
        // validation above.
        usort($addresses, static fn (string $left, string $right): int => str_contains($left, ':') <=> str_contains($right, ':'));

        return array_values(array_unique($addresses));
    }

    protected function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    protected function safeSourceForLog(string $source): string
    {
        $parts = parse_url($source);
        if (! is_array($parts) || empty($parts['host'])) {
            return '[invalid-url]';
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . ($parts['path'] ?? '');
    }
}
