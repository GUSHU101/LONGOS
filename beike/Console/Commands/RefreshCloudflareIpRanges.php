<?php

namespace Beike\Console\Commands;

use Beike\Services\CloudflareIpRangeService;
use Illuminate\Console\Command;

class RefreshCloudflareIpRanges extends Command
{
    protected $signature = 'cloudflare:refresh-ip-ranges';

    protected $description = 'Refresh the official Cloudflare proxy IP range cache';

    public function handle(CloudflareIpRangeService $ranges): int
    {
        if (! $ranges->refresh()) {
            $this->error('Cloudflare IP ranges could not be refreshed; the existing cache was kept.');

            return self::FAILURE;
        }

        $current = $ranges->ranges();
        $missing = array_values(array_diff($current, CloudflareIpRangeService::bundledRanges()));
        if ($missing !== []) {
            $message = 'The running Caddy strict-origin snapshot does not cover new Cloudflare ranges: '
                . implode(', ', $missing)
                . '. Rebuild LONGOS with an updated range snapshot before enforcing strict origin mode.';
            if (strtolower((string) config('longos.cloudflare.mode', 'optional')) === 'required') {
                $this->error($message);

                return self::FAILURE;
            }

            $this->warn($message);
        }

        $this->info(sprintf('Cloudflare IP ranges refreshed (%d ranges).', count($current)));

        return self::SUCCESS;
    }
}
