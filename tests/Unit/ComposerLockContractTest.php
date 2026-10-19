<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ComposerLockContractTest extends TestCase
{
    public function test_composer_lock_content_hash_matches_composer_json(): void
    {
        $root     = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $lock     = json_decode((string) file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $keys     = [
            'name', 'version', 'require', 'require-dev', 'conflict', 'replace',
            'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra',
        ];
        $relevant = [];
        foreach (array_intersect($keys, array_keys($composer)) as $key) {
            $relevant[$key] = $composer[$key];
        }
        if (isset($composer['config']['platform'])) {
            $relevant['config']['platform'] = $composer['config']['platform'];
        }
        ksort($relevant);

        self::assertSame(
            $lock['content-hash'] ?? null,
            md5((string) json_encode($relevant, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
            'composer.json and composer.lock are inconsistent; regenerate the lock file before deployment.',
        );
    }
}
