<?php

/**
 * StateMachineService.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-08 18:21:47
 * @modified   2022-08-08 18:21:47
 */

namespace Beike\Services;

use Beike\Admin\Services\UniPushService;
use Beike\Exceptions\CartException;
use beike\Models\Customer;
use Beike\Models\Order;
use Beike\Models\OrderHistory;
use Beike\Models\Product;
use Beike\Models\ProductSku;
use Beike\Repositories\OrderPaymentRepo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class StateMachineService
{
    private Order $order;

    private int $orderId;

    private string $comment;

    private bool $notify;

    private array $shipment;

    private array $payment;

    public const CREATED = 'created';                  // 已创建

    public const UNPAID = 'unpaid';                    // 待支付

    public const PAID = 'paid';                        // 已支付

    public const SHIPPED = 'shipped';                  // 已发货

    public const COMPLETED = 'completed';              // 已完成

    public const CANCELLED = 'cancelled';              // 已取消

    public const REFUNDING = 'refunding';              // 已取消

    public const ORDER_STATUS = [
        self::CREATED,
        self::UNPAID,
        self::PAID,
        self::SHIPPED,
        self::COMPLETED,
        self::CANCELLED,
        self::REFUNDING,
    ];

    public const MACHINES = [
        self::CREATED => [
            self::UNPAID => ['reserveStock', 'updateStatus', 'addHistory', 'notifyNewOrder'],
        ],
        self::UNPAID  => [
            self::PAID      => ['subStock', 'updateStatus', 'addHistory', 'updateSales', 'notifyUpdateOrder'],
            self::CANCELLED => ['revertStock', 'updateStatus', 'addHistory', 'notifyUpdateOrder'],
        ],
        self::PAID    => [
            self::REFUNDING => ['updateStatus', 'addHistory', 'notifyUpdateOrder', 'revertStock', 'uniPushMessage'],
            self::SHIPPED   => ['updateStatus', 'addHistory', 'addShipment', 'notifyUpdateOrder', 'uniPushMessage'],
            self::COMPLETED => ['updateStatus', 'addHistory', 'notifyUpdateOrder'],
        ],
        self::REFUNDING    => [
            self::CANCELLED => ['updateStatus', 'addHistory', 'notifyUpdateOrder', 'uniPushMessage'],
        ],
        self::SHIPPED => [
            self::COMPLETED => ['updateStatus', 'addHistory', 'notifyUpdateOrder'],
        ],
    ];

    public function __construct(Order $order)
    {
        $this->order   = $order;
        $this->orderId = $order->id;
    }

    public static function getInstance($order): self
    {
        return new self($order);
    }

    /**
     * 设置订单备注
     * @param $comment
     * @return $this
     */
    public function setComment($comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * 设置是否通知
     * @param $flag
     * @return $this
     */
    public function setNotify($flag): self
    {
        $this->notify = (bool) $flag;

        return $this;
    }

    /**
     * 设置发货信息
     *
     * @param array $shipment
     * @return $this
     */
    public function setShipment(array $shipment = []): self
    {
        $this->shipment = $shipment;

        return $this;
    }

    /**
     * 设置支付信息
     *
     * @param array $payment
     * @return $this
     */
    public function setPayment(array $payment = []): self
    {
        $this->payment = $payment;

        return $this;
    }

    /**
     * 获取所有订单状态列表
     *
     * @return array
     * @throws \Exception
     */
    public static function getAllStatuses(): array
    {
        $result   = [];
        $statuses = self::ORDER_STATUS;
        foreach ($statuses as $status) {
            if ($status == self::CREATED) {
                continue;
            }
            $result[] = [
                'status' => $status,
                'name'   => trans("order.{$status}"),
            ];
        }

        return hook_filter('service.state_machine.all_statuses', $result);
    }

    /**
     * 获取所有有效订单状态(从订单已支付到订单已完成的所有状态)
     * @return string[]
     */
    public static function getValidStatuses(): array
    {
        $statuses = [
            self::PAID,
            self::SHIPPED,
            self::COMPLETED,
        ];

        return $statuses;
    }

    /**
     * 获取当前订单可以变为的状态
     *
     * @return array
     * @throws \Exception
     */
    public function nextBackendStatuses(): array
    {
        $machines = $this->getMachines();

        $currentStatusCode = $this->order->status;
        $nextStatus        = $machines[$currentStatusCode] ?? [];

        if (empty($nextStatus)) {
            return [];
        }
        $nextStatusCodes = array_keys($nextStatus);
        $result          = [];
        foreach ($nextStatusCodes as $status) {
            $result[] = [
                'status' => $status,
                'name'   => trans("order.{$status}"),
            ];
        }

        return $result;
    }

    /**
     * @param             $status
     * @param  string     $comment
     * @param  bool       $notify
     * @throws \Exception
     */
    public function changeStatus($status, string $comment = '', bool $notify = true)
    {
        $this->setComment($comment)->setNotify($notify);

        DB::transaction(function () use ($status, $comment, $notify) {
            // Serialize every transition for this order. Payment callbacks,
            // customer cancellation and the timeout worker may otherwise all
            // validate the same stale status before any one of them commits.
            $order         = Order::query()->whereKey($this->orderId)->lockForUpdate()->firstOrFail();
            $this->order   = $order;
            $oldStatusCode = $order->status;

            $this->validStatusCode($status);
            $functions = $this->getFunctions($oldStatusCode, $status);
            if ($functions) {
                foreach ($functions as $function) {
                    if ($function instanceof \Closure) {
                        $function();

                        continue;
                    }

                    if (! method_exists($this, $function)) {
                        throw new \Exception("{$function} not exist in StateMachine!");
                    }
                    $this->{$function}($oldStatusCode, $status);
                }
            }

            hook_filter('service.state_machine.change_status.after', ['order' => $order, 'status' => $status, 'comment' => $comment, 'notify' => $notify]);
        }, 3);

        if (! $this->order->shipping_method_code && $status == self::PAID) {
            $this->changeStatus(self::COMPLETED, $comment, $notify);
        }
    }

    /**
     * Apply a successful gateway result exactly once.
     *
     * Payment providers legitimately retry browser captures and webhooks. A
     * retry that observes paid/shipped/completed is success, while cancelled
     * and refund states still fail closed for manual reconciliation.
     */
    public function markPaid(string $comment = '', bool $notify = true): bool
    {
        try {
            $this->changeStatus(self::PAID, $comment, $notify);

            return true;
        } catch (Throwable $e) {
            $order = Order::query()->find($this->orderId);
            if ($order && in_array((string) $order->status, self::getValidStatuses(), true)) {
                $this->order = $order;

                return false;
            }

            throw $e;
        }
    }

    /**
     * 检测当前订单是否可以变更为某个状态
     *
     * @param             $statusCode
     * @throws \Exception
     */
    private function validStatusCode($statusCode)
    {
        $orderId           = $this->orderId;
        $orderNumber       = $this->order->number;
        $currentStatusCode = $this->order->status;

        $nextStatusCodes = collect($this->nextBackendStatuses())->pluck('status')->toArray();
        if (! in_array($statusCode, $nextStatusCodes)) {
            throw new \Exception("Order {$orderId}({$orderNumber}) is {$currentStatusCode}, cannot be changed to $statusCode");
        }
    }

    /**
     * 获取状态机流程, 此处通过 filter hook 可以被外部插件修改
     * @return mixed
     */
    private function getMachines()
    {
        $data = [
            'order'    => $this->order,
            'machines' => self::MACHINES,
        ];

        $data = hook_filter('service.state_machine.machines', $data);

        return $data['machines'] ?? [];
    }

    /**
     * 通过订单当前状态以及即将变为的状态获取需要触发的事件
     *
     * @param $oldStatus
     * @param $newStatus
     * @return array
     */
    private function getFunctions($oldStatus, $newStatus): array
    {
        $machines = $this->getMachines();

        return $machines[$oldStatus][$newStatus] ?? [];
    }

    /**
     * 更新订单状态
     *
     * @param             $oldCode
     * @param             $newCode
     * @throws \Throwable
     */
    private function updateStatus($oldCode, $newCode)
    {
        $this->order->status = $newCode;
        $this->order->saveOrFail();
    }

    /**
     * 更新订单商品销量
     * @return void
     */
    private function updateSales()
    {
        $this->order->loadMissing([
            'orderProducts',
        ]);
        $orderProducts = $this->order->orderProducts;
        foreach ($orderProducts as $orderProduct) {
            Product::query()->where('id', $orderProduct->product_id)->increment('sales', $orderProduct->quantity);
        }
    }

    /**
     * 添加更改记录
     *
     * @param            $oldCode
     * @param            $newCode
     * @throws Throwable
     */
    private function addHistory($oldCode, $newCode)
    {
        $history = new OrderHistory([
            'order_id' => $this->orderId,
            'status'   => $newCode,
            'notify'   => (int) $this->notify,
            'comment'  => (string) $this->comment,
        ]);
        $history->saveOrFail();
    }

    /**
     * 减扣库存
     *
     * @param $oldCode
     * @param $newCode
     */
    private function subStock($oldCode, $newCode)
    {
        $this->reserveStock($oldCode, $newCode);
    }

    /**
     * Atomically reserve inventory before an unpaid order is exposed to a
     * payment provider. This prevents two checkouts from selling the same
     * final unit and lets cancelled/expired orders release their reservation.
     */
    private function reserveStock($oldCode, $newCode): void
    {
        DB::transaction(function () {
            $order = Order::query()->whereKey($this->orderId)->lockForUpdate()->firstOrFail();
            if ($order->stock_reserved) {
                $this->order->stock_reserved = true;

                return;
            }

            $this->order->loadMissing(['orderProducts']);
            $quantities = $this->order->orderProducts
                ->groupBy('product_sku')
                ->map(fn ($items) => $items->sum('quantity'));
            $lockedSkus = [];

            foreach ($quantities as $skuCode => $quantity) {
                $productSku = ProductSku::query()->where('sku', $skuCode)->lockForUpdate()->first();
                if (! $productSku || (int) $productSku->quantity < (int) $quantity) {
                    throw new CartException(trans('cart.stock_out'));
                }
                $lockedSkus[$skuCode] = $productSku;
            }

            foreach ($this->order->orderProducts as $orderProduct) {
                $productSku = $lockedSkus[$orderProduct->product_sku] ?? null;
                if (! $productSku) {
                    continue;
                }
                $productSku->decrement('quantity', $orderProduct->quantity);
                hook_action('service.state_machine.sub_stock.after', ['order_product' => $orderProduct, 'order_number' => $this->order->number]);
            }

            $order->update(['stock_reserved' => true]);
            $this->order->stock_reserved = true;
        });
    }

    /**
     * 添加发货单号
     */
    private function addShipment($oldCode, $newCode)
    {
        ShipmentService::addShipment($this->orderId, $this->shipment);
    }

    /**
     * 添加发货单号
     * @throws Throwable
     */
    private function addPayment($oldCode, $newCode)
    {
        if (empty($this->payment)) {
            return;
        }
        OrderPaymentRepo::createOrUpdatePayment($this->orderId, $this->payment);
    }

    /**
     * 发送新订单通知
     */
    private function notifyNewOrder($oldCode, $newCode)
    {
        if (! $this->notify) {
            return;
        }

        $order = $this->order;
        DB::afterCommit(function () use ($order) {
            try {
                $order->notifyNewOrder();
            } catch (Throwable $e) {
                Log::error('New order notification failed after the order was committed.', [
                    'order_id'  => $order->id,
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * 发送订单状态更新通知
     */
    private function notifyUpdateOrder($oldCode, $newCode)
    {
        if (! $this->notify) {
            return;
        }

        $order = $this->order;
        DB::afterCommit(function () use ($order, $oldCode) {
            try {
                $order->notifyUpdateOrder($oldCode);
            } catch (Throwable $e) {
                Log::error('Order status notification failed after the transition was committed.', [
                    'order_id'  => $order->id,
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * 恢复库存
     */
    private function revertStock($oldCode, $newCode)
    {
        DB::transaction(function () {
            $order = Order::query()->whereKey($this->orderId)->lockForUpdate()->firstOrFail();
            if (! $order->stock_reserved) {
                $this->order->stock_reserved = false;

                return;
            }

            $this->order->loadMissing(['orderProducts.productSku']);
            foreach ($this->order->orderProducts as $orderProduct) {
                $productSku = $orderProduct->productSku;
                if (empty($productSku)) {
                    continue;
                }
                $productSku->increment('quantity', $orderProduct->quantity);
                hook_action('service.state_machine.revert_stock.after', ['order_product' => $orderProduct, 'order_number' => $this->order->number]);
            }

            $order->update(['stock_reserved' => false]);
            $this->order->stock_reserved = false;
        });
    }

    /**
     * 采用UniPush 推送消息到手机APP
     *
     * @param            $oldCode
     * @param            $newCode
     * @throws Exception
     */
    private function uniPushMessage($oldCode, $newCode)
    {
        $order = $this->order;
        DB::afterCommit(function () use ($order, $newCode) {
            try {
                if (! system_setting('base.unipush_api_url') || ! system_setting('base.order_auto_app_push')) {
                    return;
                }

                $customer = Customer::find($order->customer_id);
                $cid      = $customer->cid ?? 0;
                if (! $cid) {
                    return;
                }

                $message = [
                    'push_clientid' => $cid,
                    'customer_id'   => $order->customer_id,
                    'title'         => trans('admin/app_push.order_status_update_title'),
                    'content'       => trans('admin/app_push.order_status_update_info') . trans('order.' . $newCode),
                    'link'          => [
                        'type'  => 'order',
                        'value' => $order->id,
                    ],
                ];

                UniPushService::getInstance()->pushOrderStatus($message);
            } catch (Throwable $e) {
                Log::error('Order push notification failed after the transition was committed.', [
                    'order_id'  => $order->id,
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
            }
        });
    }
}
