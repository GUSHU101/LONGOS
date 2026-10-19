<?php

namespace Beike\Installer\Helpers;

use Throwable;

class InstallationStateManager
{
    private const STAGES = [
        'database_validated',
        'schema_migrated',
        'database_migrated',
        'admin_created',
        'saas_registered',
        'completed',
    ];

    public function matches(string $databaseSignature): bool
    {
        $state = $this->read();
        $saved = (string) ($state['database_signature'] ?? '');

        return preg_match('/^[a-f0-9]{64}$/', $saved) === 1
            && hash_equals($saved, $databaseSignature);
    }

    public function hasReached(string $databaseSignature, string $stage): bool
    {
        if (! in_array($stage, self::STAGES, true) || ! $this->matches($databaseSignature)) {
            return false;
        }

        $state = $this->read();

        return $this->stageIndex((string) ($state['stage'] ?? '')) >= $this->stageIndex($stage);
    }

    /**
     * Persist only recovery metadata. Credentials and administrator passwords
     * must remain in the encrypted browser session and are never written here.
     *
     * @param array<string, mixed> $completion
     */
    public function write(string $databaseSignature, string $stage, array $completion = []): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $databaseSignature) !== 1
            || ! in_array($stage, self::STAGES, true)) {
            return false;
        }

        try {
            $directory = dirname($this->path());
            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                return false;
            }

            $lock = @fopen($this->path() . '.lock', 'c+');
            if ($lock === false || ! flock($lock, LOCK_EX)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }

                return false;
            }

            try {
                $current = $this->read();
                if (($current['database_signature'] ?? null) === $databaseSignature
                    && $this->stageIndex((string) ($current['stage'] ?? '')) > $this->stageIndex($stage)) {
                    // Concurrent/repeated installer requests must never move a
                    // verified installation backwards to an earlier stage.
                    return true;
                }

                $payload = [
                    'database_signature' => $databaseSignature,
                    'stage'              => $stage,
                    'updated_at'         => now()->toIso8601String(),
                ];
                if ($stage === 'completed') {
                    $payload['completion'] = $this->sanitizeCompletion($completion);
                }

                return $this->writeAtomically(json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ) . PHP_EOL);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        try {
            if (! is_file($this->path()) || filesize($this->path()) > 4096) {
                return [];
            }

            $state = json_decode((string) file_get_contents($this->path()), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($state)
                || preg_match('/^[a-f0-9]{64}$/', (string) ($state['database_signature'] ?? '')) !== 1
                || ! in_array($state['stage'] ?? null, self::STAGES, true)
                || ! $this->isFresh((string) ($state['updated_at'] ?? ''))) {
                return [];
            }

            return $state;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    public function completion(string $databaseSignature): array
    {
        $state = $this->read();
        if (($state['stage'] ?? null) !== 'completed' || ! $this->matches($databaseSignature)) {
            return [];
        }

        $completion = $state['completion'] ?? [];

        return is_array($completion) ? $this->sanitizeCompletion($completion) : [];
    }

    public function currentDatabaseSignature(): string
    {
        $connection = (string) config('database.default');
        $database   = (array) config("database.connections.{$connection}", []);

        return hash('sha256', json_encode([
            $connection,
            strtolower((string) ($database['host'] ?? '')),
            (string) ($database['port'] ?? ''),
            (string) ($database['database'] ?? ''),
            (string) ($database['username'] ?? ''),
        ], JSON_THROW_ON_ERROR));
    }

    public function clear(): void
    {
        $lockPath = $this->path() . '.lock';
        $lock     = @fopen($lockPath, 'c+');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return;
        }

        try {
            if (is_file($this->path())) {
                @unlink($this->path());
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function path(): string
    {
        return storage_path('app/installer-state.json');
    }

    /** @param array<string, mixed> $completion */
    private function sanitizeCompletion(array $completion): array
    {
        $email      = filter_var((string) ($completion['admin_email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $baseDomain = strtolower(rtrim((string) ($completion['base_domain'] ?? ''), '.'));
        $dnsTargets = array_values(array_filter(array_map(
            static fn (mixed $target): string => rtrim(strtolower(trim((string) $target)), '.'),
            is_array($completion['dns_targets'] ?? null) ? $completion['dns_targets'] : [],
        )));

        return [
            'admin_email' => is_string($email) ? $email : '',
            'saas'        => (bool) ($completion['saas'] ?? false),
            'base_domain' => mb_substr($baseDomain, 0, 253),
            'dns_targets' => array_slice($dnsTargets, 0, 20),
        ];
    }

    private function isFresh(string $updatedAt): bool
    {
        $timestamp = strtotime($updatedAt);

        // Recovery metadata contains no credentials and is bound to the
        // exact database connection signature. Keep it until installation
        // completes; expiring it after 24 hours made a partially installed,
        // non-empty database impossible to resume after delayed maintenance.
        return $timestamp !== false
            && $timestamp <= time() + 300;
    }

    private function stageIndex(string $stage): int
    {
        $index = array_search($stage, self::STAGES, true);

        return is_int($index) ? $index : -1;
    }

    private function writeAtomically(string $contents): bool
    {
        $path      = $this->path();
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        $backup    = $path . '.replace-backup-' . bin2hex(random_bytes(4));
        $hadFile   = is_file($path);

        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporary, 0600);

        if ($hadFile && ! @rename($path, $backup)) {
            @unlink($temporary);

            return false;
        }
        if (! @rename($temporary, $path)) {
            if ($hadFile) {
                @rename($backup, $path);
            }
            @unlink($temporary);

            return false;
        }
        if ($hadFile) {
            @unlink($backup);
        }
        @chmod($path, 0600);

        return true;
    }
}
