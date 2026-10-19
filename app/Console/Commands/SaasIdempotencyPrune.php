<?php

namespace App\Console\Commands;

use App\Saas\Models\IdempotencyRecord;
use Illuminate\Console\Command;

class SaasIdempotencyPrune extends Command
{
    protected $signature = 'saas:idempotency:prune';

    protected $description = '删除已过期的 SaaS 控制面幂等记录';

    public function handle(): int
    {
        $deleted = IdempotencyRecord::query()->where('expires_at', '<=', now())->delete();
        $this->components->info("已删除 {$deleted} 条过期幂等记录。");

        return self::SUCCESS;
    }
}
