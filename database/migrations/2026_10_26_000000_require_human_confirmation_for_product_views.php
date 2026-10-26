<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->invalidatePassiveStorefrontVisits();

        if (! Schema::hasTable('product_views')) {
            return;
        }

        Schema::table('product_views', function (Blueprint $table): void {
            if (! Schema::hasColumn('product_views', 'human_confirmed_at')) {
                $table->timestamp('human_confirmed_at')->nullable()->after('country_code');
            }
            if (! Schema::hasColumn('product_views', 'confirmation_method')) {
                $table->string('confirmation_method', 16)->nullable()->after('human_confirmed_at');
            }
        });

        if (! Schema::hasIndex('product_views', 'product_views_confirmed_created_index')) {
            Schema::table('product_views', function (Blueprint $table): void {
                $table->index(['human_confirmed_at', 'created_at'], 'product_views_confirmed_created_index');
            });
        }

        $this->backfillVerifiedProductViews();
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_views')) {
            return;
        }

        if (Schema::hasIndex('product_views', 'product_views_confirmed_created_index')) {
            Schema::table('product_views', function (Blueprint $table): void {
                $table->dropIndex('product_views_confirmed_created_index');
            });
        }

        Schema::table('product_views', function (Blueprint $table): void {
            if (Schema::hasColumn('product_views', 'confirmation_method')) {
                $table->dropColumn('confirmation_method');
            }
            if (Schema::hasColumn('product_views', 'human_confirmed_at')) {
                $table->dropColumn('human_confirmed_at');
            }
        });
    }

    private function invalidatePassiveStorefrontVisits(): void
    {
        if (! Schema::hasTable('storefront_visits')
            || ! Schema::hasColumn('storefront_visits', 'human_confirmed_at')
            || ! Schema::hasColumn('storefront_visits', 'confirmation_method')) {
            return;
        }

        // The retired `engaged` signal was only a 15-second timer and did not
        // prove user activity. Keep the rows for auditability but remove them
        // from every confirmed-traffic query.
        DB::table('storefront_visits')
            ->where('confirmation_method', 'engaged')
            ->update([
                'human_confirmed_at'  => null,
                'confirmation_method' => null,
            ]);
    }

    private function backfillVerifiedProductViews(): void
    {
        if (! Schema::hasColumn('product_views', 'visitor_key')
            || ! Schema::hasTable('storefront_visits')
            || ! Schema::hasColumn('storefront_visits', 'human_confirmed_at')
            || ! Schema::hasColumn('storefront_visits', 'confirmation_method')) {
            return;
        }

        DB::table('product_views')
            ->whereNull('human_confirmed_at')
            ->whereNotNull('visitor_key')
            ->whereNotNull('created_at')
            ->orderBy('id')
            ->chunkById(500, function (Collection $views): void {
                $keys = $views->pluck('visitor_key')
                    ->filter(static fn ($key): bool => is_string($key) && $key !== '')
                    ->unique()
                    ->values();

                if ($keys->isEmpty()) {
                    return;
                }

                $created = $views->pluck('created_at')->map(static fn ($value): Carbon => Carbon::parse($value));
                $from    = $created->min()->copy()->subMinutes(30)->startOfHour();
                $until   = $created->max()->copy()->addMinutes(30)->endOfHour();
                $visits  = DB::table('storefront_visits')
                    ->whereIn('visitor_key', $keys->all())
                    ->whereNotNull('human_confirmed_at')
                    ->whereBetween('bucket_at', [$from, $until])
                    ->get([
                        'visitor_key',
                        'first_seen_at',
                        'last_seen_at',
                        'human_confirmed_at',
                        'confirmation_method',
                    ])
                    ->groupBy('visitor_key');

                foreach ($views as $view) {
                    $viewCreatedAt = Carbon::parse($view->created_at);
                    $match         = collect($visits->get($view->visitor_key, []))
                        ->filter(function ($visit) use ($viewCreatedAt): bool {
                            $first = Carbon::parse($visit->first_seen_at)->subMinutes(30);
                            $last  = Carbon::parse($visit->last_seen_at)->addMinutes(30);

                            return $viewCreatedAt->betweenIncluded($first, $last);
                        })
                        ->sortBy(function ($visit) use ($viewCreatedAt): int {
                            return abs(Carbon::parse($visit->human_confirmed_at)->getTimestamp() - $viewCreatedAt->getTimestamp());
                        })
                        ->first();

                    if ($match === null) {
                        continue;
                    }

                    DB::table('product_views')->where('id', $view->id)->update([
                        'human_confirmed_at'  => $match->human_confirmed_at,
                        'confirmation_method' => $match->confirmation_method,
                    ]);
                }
            });
    }
};
