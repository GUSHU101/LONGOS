<?php

namespace Beike\Models;

use Illuminate\Support\Facades\Schema;

class DashboardEvent extends Base
{
    protected $table = 'dashboard_events';

    protected $fillable = [
        'event_type', 'product_id', 'customer_id', 'session_id', 'quantity', 'ip',
    ];

    /**
     * Analytics must never prevent a shopper from adding an item to cart.
     * The schema guard keeps rolling upgrades compatible until migrations run.
     */
    public static function recordCartAdded($sku, int $quantity, $customer = null): void
    {
        try {
            if (! Schema::hasTable('dashboard_events')) {
                return;
            }

            static::query()->create([
                'event_type'  => 'cart_added',
                'product_id'  => $sku->product_id,
                'customer_id' => $customer->id ?? 0,
                'session_id'  => get_session_id(),
                'quantity'    => max(1, $quantity),
                'ip'          => request()->ip(),
            ]);
        } catch (\Throwable) {
            // A reporting write is intentionally non-blocking for checkout.
        }
    }

    /**
     * Records the point at which a shopper has supplied payment information.
     *
     * The event deliberately stores no payment fields or card data.  It is
     * used only as a de-duplicated funnel signal in the administrator dashboard.
     */
    public static function recordPaymentInformationAdded($customer = null): void
    {
        try {
            if (! Schema::hasTable('dashboard_events')) {
                return;
            }

            static::query()->create([
                'event_type'  => 'payment_information_added',
                'customer_id' => $customer->id ?? current_customer()->id ?? 0,
                'session_id'  => get_session_id(),
                'quantity'    => 1,
                'ip'          => request()->ip(),
            ]);
        } catch (\Throwable) {
            // Analytics must never interrupt a checkout or payment attempt.
        }
    }
}
