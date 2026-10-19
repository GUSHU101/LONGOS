<?php

/**
 * ProcessOrder.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-07-20 16:30:20
 * @modified   2023-07-20 16:30:20
 */

namespace Beike\Console\Commands;

use Beike\Models\OrderHistory;
use Beike\Repositories\OrderRepo;
use Beike\Services\StateMachineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

class ProcessOrder extends Command
{
    protected $signature = 'process:order';

    protected $description = '超时自动取消未支付订单, 超时自动完成已发货订单';

    protected LoggerInterface $logger;

    /**
     * @throws \Exception
     */
    public function handle()
    {
        $this->logger = $this->buildLogger();
        $this->cancelOrders();
        $this->completeOrders();
    }

    /**
     * 自动取消未支付订单
     *
     * @throws \Exception
     */
    private function cancelOrders()
    {
        $this->logInfo('====== 自动取消未支付订单 ======');

        $cancelHours = (int) system_setting('base.order_auto_cancel');
        if ($cancelHours) {
            $minDatetime = now()->subHours($cancelHours);
        } else {
            $minDatetime = now()->subWeek();
        }

        $this->logInfo("Min Datetime: $minDatetime");

        $query = OrderRepo::getListBuilder(['status' => StateMachineService::UNPAID])
            ->where('created_at', '<', $minDatetime);
        $orderTotal = (clone $query)->count();
        $processed  = 0;

        foreach ($query->lazyById(100) as $order) {
            $processed++;
            $this->logInfo("处理 $processed/$orderTotal: ID:$order->id - Number: $order->number - $order->created_at");

            try {
                (new StateMachineService($order))->changeStatus(StateMachineService::CANCELLED);
            } catch (Throwable $e) {
                // A payment callback may win the row lock while this worker
                // is waiting. Isolate that race (and malformed legacy rows)
                // so one order never prevents other reservations releasing.
                $this->logger->warning('Unable to auto-cancel order; continuing with the next order.', [
                    'order_id'     => $order->id,
                    'order_number' => $order->number,
                    'exception'    => $e::class,
                    'message'      => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 自动完成已发货订单
     *
     * @throws \Exception
     */
    private function completeOrders()
    {
        $this->logInfo('====== 自动完成已发货订单 ======');

        $completeHours = (int) system_setting('base.order_auto_complete');
        if ($completeHours) {
            $minDatetime = now()->subHours($completeHours);
        } else {
            $minDatetime = now()->subWeek();
        }

        $this->logInfo("Min Datetime: $minDatetime");

        $query      = OrderRepo::getListBuilder(['status' => StateMachineService::SHIPPED]);
        $orderTotal = (clone $query)->count();
        $processed  = 0;

        foreach ($query->lazyById(100) as $order) {
            $processed++;
            $this->logInfo("处理 $processed/$orderTotal: ID:$order->id - Number: $order->number - $order->created_at");

            try {
                $shipHistory = OrderHistory::query()->where('order_id', $order->id)->orderByDesc('id')->first();
                if (empty($shipHistory)) {
                    continue;
                }
                $shipDatetime = $shipHistory->created_at;

                $this->logInfo("Shipped Datetime: $shipDatetime");
                if ($shipDatetime < $minDatetime) {
                    (new StateMachineService($order))->changeStatus(StateMachineService::COMPLETED);
                }
            } catch (Throwable $e) {
                $this->logger->warning('Unable to auto-complete order; continuing with the next order.', [
                    'order_id'     => $order->id,
                    'order_number' => $order->number,
                    'exception'    => $e::class,
                    'message'      => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Build current logger
     *
     * @return LoggerInterface
     */
    private function buildLogger(): LoggerInterface
    {
        return Log::build([
            'driver' => 'single',
            'path'   => storage_path('logs/process_order.log'),
        ]);
    }

    /**
     * Log information
     *
     * @param ...$messages
     */
    private function logInfo(...$messages)
    {
        foreach ($messages as $message) {
            if (PHP_SAPI == 'cli') {
                $this->line((string) $message);
            }
            $this->logger->info($message);
        }
    }
}
