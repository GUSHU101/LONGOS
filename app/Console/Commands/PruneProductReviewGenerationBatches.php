<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Plugin\LongosProductReviews\Services\ReviewGenerationBatchService;

class PruneProductReviewGenerationBatches extends Command
{
    protected $signature = 'longos:product-reviews:prune-generation';

    protected $description = 'Recover stalled product-review generation jobs and delete expired encrypted previews';

    public function handle(ReviewGenerationBatchService $batches): int
    {
        if (! Schema::hasTable('longos_product_review_generation_batches')) {
            $this->components->info('Product-review generation storage is not installed; nothing to prune.');

            return self::SUCCESS;
        }

        $recovered = $batches->recoverStale();
        $deleted   = $batches->pruneExpired();
        $this->components->info("Recovered {$recovered} stalled generation job(s) and pruned {$deleted} expired preview(s).");

        return self::SUCCESS;
    }
}
