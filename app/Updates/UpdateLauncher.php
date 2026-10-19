<?php

namespace App\Updates;

use Illuminate\Support\Str;

class UpdateLauncher
{
    /**
     * Start a detached local CLI worker. No secret or remote value is placed
     * on the command line; the UUID only selects an already locked state file.
     */
    public function launch(string $jobId): bool
    {
        if (! Str::isUuid($jobId) || PHP_OS_FAMILY === 'Windows' || ! $this->functionAvailable('proc_open')) {
            return false;
        }

        $php   = $this->phpCli();
        $nohup = $this->executable(['/usr/bin/nohup', '/bin/nohup']);
        if ($php === null || $nohup === null || ! is_file('/bin/sh')) {
            return false;
        }

        $command = implode(' ', array_map('escapeshellarg', [
            $nohup,
            $php,
            base_path('artisan'),
            'longos:update:run',
            $jobId,
        ])) . ' >/dev/null 2>&1 </dev/null & echo $!';

        $pipes   = [];
        $process = @proc_open(['/bin/sh', '-c', $command], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, base_path());
        if (! is_resource($process)) {
            return false;
        }

        $pid = trim(is_resource($pipes[1] ?? null) ? (string) stream_get_contents($pipes[1]) : '');
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exit = proc_close($process);

        return $exit === 0 && preg_match('/^[1-9]\d*$/', $pid) === 1;
    }

    public function phpCli(): ?string
    {
        $configured = trim((string) config('longos.updater.php_cli_path', ''));
        $candidates = array_filter([
            $configured,
            PHP_BINDIR . '/php',
            '/www/server/php/' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/bin/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
        ]);

        return $this->executable($candidates);
    }

    /** @param array<int, string> $candidates */
    private function executable(array $candidates): ?string
    {
        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function functionAvailable(string $function): bool
    {
        if (! function_exists($function)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array($function, $disabled, true);
    }
}
