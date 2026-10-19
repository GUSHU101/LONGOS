<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        $addedStockReservation = false;
        if (! Schema::hasColumn('orders', 'stock_reserved')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->boolean('stock_reserved')->default(false)->after('status');
            });
            $addedStockReservation = true;
        }

        if ($addedStockReservation) {
            DB::table('orders')
                ->whereIn('status', ['paid', 'shipped', 'completed'])
                ->update(['stock_reserved' => true]);
        }

        $this->ensureUniqueOrderPayments();

        if (Schema::hasIndex('orders', ['number'], 'unique')) {
            return;
        }

        $duplicates = DB::table('orders')
            ->select('number')
            ->groupBy('number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('number');

        foreach ($duplicates as $number) {
            $orders = DB::table('orders')
                ->where('number', $number)
                ->orderBy('id')
                ->get(['id', 'created_at']);

            foreach ($orders->skip(1) as $order) {
                $createdAt = $order->created_at ? Carbon::parse($order->created_at) : now();
                do {
                    $replacement = $createdAt->format('ymdHis') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                } while (DB::table('orders')->where('number', $replacement)->exists());

                DB::table('orders')->where('id', $order->id)->update(['number' => $replacement]);
                if (Schema::hasTable('order_products')) {
                    DB::table('order_products')->where('order_id', $order->id)->update(['order_number' => $replacement]);
                }
            }
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unique('number', 'orders_number_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('order_payments') && Schema::hasIndex('order_payments', 'order_payments_order_id_unique')) {
            Schema::table('order_payments', function (Blueprint $table) {
                $table->dropUnique('order_payments_order_id_unique');
            });
        }

        if (Schema::hasTable('orders') && Schema::hasIndex('orders', 'orders_number_unique')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique('orders_number_unique');
            });
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'stock_reserved')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('stock_reserved');
            });
        }
    }

    private function ensureUniqueOrderPayments(): void
    {
        if (! Schema::hasTable('order_payments') || Schema::hasIndex('order_payments', ['order_id'], 'unique')) {
            return;
        }

        $duplicates = DB::table('order_payments')
            ->select('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('order_id');

        foreach ($duplicates as $orderId) {
            $payments = DB::table('order_payments')->where('order_id', $orderId)->orderBy('id')->get();
            $keeper   = $payments->first();
            if (! $keeper) {
                continue;
            }

            $merged = [];
            foreach (['transaction_id', 'request', 'response', 'callback', 'receipt'] as $column) {
                $value = $payments->pluck($column)->filter(fn ($item) => $item !== null && $item !== '')->last();
                if ($value !== null) {
                    $merged[$column] = $value;
                }
            }
            if ($merged) {
                DB::table('order_payments')->where('id', $keeper->id)->update($merged);
            }

            DB::table('order_payments')->where('order_id', $orderId)->where('id', '<>', $keeper->id)->delete();
        }

        Schema::table('order_payments', function (Blueprint $table) {
            $table->unique('order_id', 'order_payments_order_id_unique');
        });
    }
};
