<?php

namespace Beike\Installer\Helpers;

use RuntimeException;

class InstalledFileManager
{
    /** Create the installed marker atomically. */
    public function create(): string
    {
        $installedLogFile = storage_path('installed');

        $dateStamp = date('Y/m/d h:i:sa');

        if (! file_exists($installedLogFile)) {
            $message = trans('installer::installer_messages.installed.success_log_message') . $dateStamp . "\n";
        } else {
            $message = trans('installer::installer_messages.updater.log.success_message') . $dateStamp;
        }

        $contents = is_file($installedLogFile) ? (string) file_get_contents($installedLogFile) : '';
        $contents .= rtrim($message) . PHP_EOL;
        $temporary = $installedLogFile . '.tmp-' . bin2hex(random_bytes(4));
        $backup    = $installedLogFile . '.replace-backup-' . bin2hex(random_bytes(4));
        $hadFile   = is_file($installedLogFile);
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            @unlink($temporary);

            throw new RuntimeException('Unable to create the installation completion marker.');
        }
        if ($hadFile && ! @rename($installedLogFile, $backup)) {
            @unlink($temporary);

            throw new RuntimeException('Unable to replace the installation completion marker.');
        }
        if (! @rename($temporary, $installedLogFile)) {
            if ($hadFile) {
                @rename($backup, $installedLogFile);
            }
            @unlink($temporary);

            throw new RuntimeException('Unable to create the installation completion marker.');
        }
        if ($hadFile) {
            @unlink($backup);
        }
        @chmod($installedLogFile, 0600);

        return $message;
    }

    /** Append a new installation completion entry atomically. */
    public function update(): string
    {
        return $this->create();
    }
}
