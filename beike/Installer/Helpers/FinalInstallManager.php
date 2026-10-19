<?php

namespace Beike\Installer\Helpers;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class FinalInstallManager
{
    /**
     * Run final commands.
     *
     * @return string
     */
    public function runFinal(): string
    {
        $outputLog = new BufferedOutput;

        $this->generateKey($outputLog);
        $this->publishVendorAssets($outputLog);

        return $outputLog->fetch();
    }

    /** Generate an application key only when the environment has none. */
    private static function generateKey(BufferedOutput $outputLog): void
    {
        try {
            // EnvironmentManager already generated and persisted a valid key
            // before the encrypted installer session was created. Rotating it
            // on the final request would invalidate that session and any data
            // encrypted during seeding.
            if (config('installer.final.key') && ! self::hasValidApplicationKey()) {
                $exitCode = Artisan::call('key:generate', ['--force' => true], $outputLog);
                if ($exitCode !== 0) {
                    throw new RuntimeException('Application key generation failed.');
                }
            }
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }
    }

    /** Publish optional installer assets and fail closed on errors. */
    private static function publishVendorAssets(BufferedOutput $outputLog): void
    {
        try {
            if (config('installer.final.publish')) {
                $exitCode = Artisan::call('vendor:publish', ['--all' => true], $outputLog);
                if ($exitCode !== 0) {
                    throw new RuntimeException('Vendor asset publication failed.');
                }
            }
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }
    }

    private static function hasValidApplicationKey(): bool
    {
        $key = trim((string) config('app.key'));
        if (str_starts_with($key, 'base64:')) {
            return strlen((string) base64_decode(substr($key, 7), true)) === 32;
        }

        return strlen($key) >= 32;
    }
}
