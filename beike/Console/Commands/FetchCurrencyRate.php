<?php

namespace Beike\Console\Commands;

use Beike\Services\CurrencyService;
use Illuminate\Console\Command;

class FetchCurrencyRate extends Command
{
    protected $signature = 'fetch:currency-rate {--force : Bypass the one-refresh-per-day guard}';

    protected $description = '更新货币汇率';

    public function handle()
    {
        $today = date('Y-m-d');

        $this->info(sprintf('获取 %s 汇率数据开始', $today));

        try {
            $tableRows = [];
            $service   = CurrencyService::getInstance();
            $data      = $service->refreshRatesIfDue((bool) $this->option('force'));
            $outcome   = $service->lastRefreshOutcome();

            if (! $outcome['success']) {
                $this->warn('汇率刷新失败，已保留上一次可用快照；请检查 ExchangeRate-API Key 和网络连接。');

                return self::FAILURE;
            }

            foreach ($data as $key => $val) {
                $tableRows[] = [$key, $val];
            }

            $this->table(['货币', '汇率'], $tableRows);

            $message = $outcome['reason'] === 'refreshed'
                ? '本地 USD 基准汇率库已更新。'
                : '现有 USD 基准汇率快照仍然有效，无需重复刷新。';
            $this->info(sprintf('获取 %s 汇率数据完成，%s', $today, $message));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error(sprintf('获取 %s 汇率数据失败', $today));

            return self::FAILURE;
        }
    }
}
