<?php

/**
 * ProductView.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-11-29 20:22:18
 * @modified   2023-11-29 20:22:18
 */

namespace Beike\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductView extends Base
{
    use HasFactory;

    /** @var array<string, bool> */
    private static array $humanConfirmationAvailability = [];

    protected $fillable = [
        'product_id',
        'customer_id',
        'ip',
        'session_id',
        'visitor_key',
        'referer',
        'user_agent',
        'country_code',
        'human_confirmed_at',
        'confirmation_method',
    ];

    protected $casts = [
        'human_confirmed_at' => 'datetime',
    ];

    public function scopeHumanConfirmed(Builder $query): Builder
    {
        if (self::supportsHumanConfirmation()) {
            $query->whereNotNull($query->getModel()->qualifyColumn('human_confirmed_at'));
        }

        return $query;
    }

    public static function supportsHumanConfirmation(): bool
    {
        $connection = DB::connection();
        $scope      = implode('|', [
            $connection->getName(),
            (string) $connection->getDatabaseName(),
            (string) $connection->getTablePrefix(),
        ]);

        // Recheck a negative result so a long-running tenant provisioner can
        // see columns added later in the same process. A positive result is
        // stable for the lifetime of the worker.
        if (! (self::$humanConfirmationAvailability[$scope] ?? false)) {
            self::$humanConfirmationAvailability[$scope] = Schema::hasTable('product_views')
                && Schema::hasColumn('product_views', 'human_confirmed_at')
                && Schema::hasColumn('product_views', 'confirmation_method');
        }

        return self::$humanConfirmationAvailability[$scope];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
