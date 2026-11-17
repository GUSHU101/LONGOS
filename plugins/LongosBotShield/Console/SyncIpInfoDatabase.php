<?php

namespace Plugin\LongosBotShield\Console;

use Illuminate\Console\Command;
use Plugin\LongosBotShield\Services\BotShieldSettings;
use Plugin\LongosBotShield\Services\IpInfoLocalDatabase;

final class SyncIpInfoDatabase extends Command
{
    protected $signature = 'longos:bot-shield:sync-ipinfo';

    protected $description = 'Refresh the shared IPInfo Lite MMDB used by LONGOS Bot Shield';

    public function handle(BotShieldSettings $settings, IpInfoLocalDatabase $database): int
    {
        if (! filter_var(plugin_setting('longos_bot_shield.status', false), FILTER_VALIDATE_BOOLEAN)) {
            return self::SUCCESS;
        }

        $result = $database->syncIfStale($settings->ipinfoToken());
        if (! ($result['ready'] ?? false)) {
            $this->components->error('IPInfo Lite local database is not ready: ' . ($result['status'] ?? 'unknown'));

            return self::FAILURE;
        }

        $this->components->info('IPInfo Lite local database is ready.');

        return self::SUCCESS;
    }
}
