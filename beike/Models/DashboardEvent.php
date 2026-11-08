<?php

namespace Beike\Models;

use Illuminate\Support\Facades\Cache;
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
     * Records a verified checkout-page visit for the commerce funnel.
     *
     * This is intentionally a server-side signal. Browser pixels can be
     * blocked or delayed, but opening a checkout that passed CheckoutService
     * validation is the authoritative point at which the visitor initiated
     * checkout. No address, product, or payment data is written here.
     */
    public static function recordCheckoutInitiated($customer = null): void
    {
        $deduplicationKey = '';

        try {
            if (! Schema::hasTable('dashboard_events')) {
                return;
            }

            $site       = current_site();
            $customerId = (int) ($customer->id ?? current_customer()?->id ?? 0);
            $identity   = $customerId > 0
                ? 'customer:' . $customerId
                : 'session:' . (string) get_session_id();
            $scope = implode('|', [
                (string) ($site?->uuid ?? $site?->id ?? config('app.url', 'longos')),
                'checkout_initiated',
                $identity,
                now()->toDateString(),
            ]);
            $deduplicationKey = 'dashboard:event:checkout-initiated:' . hash('sha256', $scope);
            if (! Cache::add($deduplicationKey, true, now()->endOfDay())) {
                return;
            }

            static::query()->create([
                'event_type'  => 'checkout_initiated',
                'customer_id' => $customerId,
                'session_id'  => get_session_id(),
                'quantity'    => 1,
                'ip'          => request()->ip(),
            ]);
        } catch (\Throwable) {
            if ($deduplicationKey !== '') {
                Cache::forget($deduplicationKey);
            }
            // Reporting must never prevent a valid shopper from checking out.
        }
    }

    /**
     * Records the point at which a shopper has supplied payment information.
     *
     * The event deliberately stores no payment fields or card data.  It is
     * used only as a de-duplicated funnel signal in the administrator dashboard.
     */
    public static function recordPaymentInformationAdded($customer = null, $order = null): void
    {
        $deduplicationKey = '';

        try {
            if (! Schema::hasTable('dashboard_events')) {
                return;
            }

            $site  = current_site();
            $scope = implode('|', [
                (string) ($site?->uuid ?? $site?->id ?? config('app.url', 'longos')),
                (string) ($order?->id ?? $order?->number ?? get_session_id()),
            ]);
            $deduplicationKey = 'dashboard:event:payment-information-added:' . hash('sha256', $scope);
            if (! Cache::add($deduplicationKey, true, now()->addDays(7))) {
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
            if ($deduplicationKey !== '') {
                Cache::forget($deduplicationKey);
            }
            // Analytics must never interrupt a checkout or payment attempt.
        }
    }
}
