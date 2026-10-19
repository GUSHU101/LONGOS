<?php

namespace App\Updates;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubReleaseClient
{
    public function __construct(private readonly UpdateSettingsStore $settings) {}

    /** @return array<string, mixed> */
    public function latest(): array
    {
        $settings = $this->settings->get();
        $repo     = $settings['repository'];
        if (! $this->validRepository($repo)) {
            throw new RuntimeException('请先配置 GitHub 仓库。');
        }

        $base = rtrim((string) config('longos.updater.github_api', 'https://api.github.com'), '/');
        if ($settings['channel'] === 'prerelease') {
            $response = $this->request($settings['token'])
                ->get("{$base}/repos/{$repo}/releases", ['per_page' => 20])
                ->throw()
                ->json();
            $release = collect(is_array($response) ? $response : [])->first(
                static fn ($item): bool => is_array($item) && ! ($item['draft'] ?? true)
            );
        } else {
            $release = $this->request($settings['token'])
                ->get("{$base}/repos/{$repo}/releases/latest")
                ->throw()
                ->json();
        }

        if (! is_array($release) || ($release['draft'] ?? true)) {
            throw new RuntimeException('GitHub 仓库没有可用的正式发布。');
        }

        $assetName = (string) config('longos.updater.asset_name', 'longos-release.zip');
        $asset     = collect((array) ($release['assets'] ?? []))->first(
            static fn ($item): bool => is_array($item)
                && ($item['name'] ?? null)  === $assetName
                && ($item['state'] ?? null) === 'uploaded'
        );
        if (! is_array($asset)) {
            throw new RuntimeException("发布缺少 {$assetName} 资产。");
        }

        $releaseId = (int) ($release['id'] ?? 0);
        $assetId   = (int) ($asset['id'] ?? 0);
        if ($releaseId < 1 || $assetId < 1) {
            throw new RuntimeException('GitHub Release 或发布资产编号无效。');
        }
        $assetUrl         = (string) ($asset['url'] ?? '');
        $expectedAssetUrl = "{$base}/repos/{$repo}/releases/assets/{$assetId}";
        if (! hash_equals(strtolower($expectedAssetUrl), strtolower($assetUrl))) {
            throw new RuntimeException('GitHub 发布资产地址与已配置仓库不匹配。');
        }

        $digest = strtolower((string) ($asset['digest'] ?? ''));
        if (! preg_match('/^sha256:([a-f0-9]{64})$/', $digest, $matches)) {
            throw new RuntimeException('GitHub 未返回发布包的 SHA-256，已拒绝不完整更新。');
        }

        $maximum = max(1, (int) config('longos.updater.maximum_bytes', 1073741824));
        $size    = (int) ($asset['size'] ?? 0);
        if ($size < 1 || $size > $maximum) {
            throw new RuntimeException('发布包大小无效或超过服务器允许上限。');
        }

        $version = ltrim(trim((string) ($release['tag_name'] ?? '')), 'vV');
        if ($version === '' || ! preg_match('/^\d+(?:\.\d+){2,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new RuntimeException('GitHub Release 标签不是有效的 LONGOS 版本号。');
        }

        $htmlUrl = (string) ($release['html_url'] ?? '');
        if (! str_starts_with(strtolower($htmlUrl), 'https://github.com/' . strtolower($repo) . '/releases/')) {
            $htmlUrl = '';
        }

        return [
            'id'            => $releaseId,
            'version'       => $version,
            'tag'           => (string) ($release['tag_name'] ?? ''),
            'name'          => (string) ($release['name'] ?? $release['tag_name'] ?? $version),
            'notes'         => mb_substr((string) ($release['body'] ?? ''), 0, 20000),
            'published_at'  => (string) ($release['published_at'] ?? ''),
            'html_url'      => $htmlUrl,
            'prerelease'    => (bool) ($release['prerelease'] ?? false),
            'immutable'     => (bool) ($release['immutable'] ?? false),
            'asset'         => [
                'id'      => $assetId,
                'api_url' => $assetUrl,
                'name'    => (string) ($asset['name'] ?? ''),
                'size'    => $size,
                'sha256'  => $matches[1],
            ],
        ];
    }

    /** @param array<string, mixed> $release */
    public function download(array $release, string $destination): void
    {
        $settings = $this->settings->get();
        $repo     = (string) ($settings['repository'] ?? '');
        $url      = (string) data_get($release, 'asset.api_url', '');
        $assetId  = (int) data_get($release, 'asset.id', 0);
        $base     = rtrim((string) config('longos.updater.github_api', 'https://api.github.com'), '/');
        $expected = "{$base}/repos/{$repo}/releases/assets/{$assetId}";
        if (! $this->validRepository($repo) || $assetId < 1
                                            || ! hash_equals(strtolower($expected), strtolower($url))) {
            throw new RuntimeException('GitHub 发布资产地址无效。');
        }

        $directory = dirname($destination);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('无法创建发布包下载目录。');
        }

        $this->request($settings['token'])
            ->withHeaders(['Accept' => 'application/octet-stream'])
            ->timeout(900)
            ->sink($destination)
            ->get($url)
            ->throw();

        $expectedSize = (int) data_get($release, 'asset.size', 0);
        $actualSize   = is_file($destination) ? (int) filesize($destination) : 0;
        if ($expectedSize < 1 || $actualSize !== $expectedSize) {
            @unlink($destination);

            throw new RuntimeException('发布包下载不完整，文件未被应用。');
        }

        $expected = (string) data_get($release, 'asset.sha256', '');
        $actual   = hash_file('sha256', $destination);
        if (! is_string($actual) || ! hash_equals($expected, strtolower($actual))) {
            @unlink($destination);

            throw new RuntimeException('发布包 SHA-256 校验失败，文件未被应用。');
        }
    }

    private function request(string $token): PendingRequest
    {
        $request = Http::acceptJson()
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(2, 500, throw: false)
            ->withHeaders([
                'X-GitHub-Api-Version' => (string) config('longos.updater.github_api_version', '2022-11-28'),
                'User-Agent'           => 'LONGOS-Updater/' . config('beike.version', 'unknown'),
            ]);

        return $token !== '' ? $request->withToken($token) : $request;
    }

    private function validRepository(string $repository): bool
    {
        $segments = explode('/', $repository);

        return count($segments)                                                              === 2
            && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/', $segments[0]) === 1
            && preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $segments[1])                         === 1
            && ! in_array($segments[1], ['.', '..'], true);
    }
}
