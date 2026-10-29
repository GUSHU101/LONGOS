<?php

/**
 * CustomerUpdateOrder.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-12-22 14:09:37
 * @modified   2022-12-22 14:09:37
 */

namespace Beike\Mail;

use Beike\Models\Order;
use Beike\Services\StateMachineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

class CustomerUpdateOrder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    private Order $order;

    private string $fromCode;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(Order $order, string $fromCode)
    {
        $this->order    = $order;
        $this->fromCode = $fromCode;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $orderLocale = $this->order->locale ?: config('app.locale');
        App::setLocale($orderLocale);

        $subjectKey = match ((string) $this->order->status) {
            StateMachineService::PAID      => 'mail.subject_order_paid',
            StateMachineService::SHIPPED   => 'mail.subject_order_shipped',
            StateMachineService::COMPLETED => 'mail.subject_order_completed',
            StateMachineService::CANCELLED => 'mail.subject_order_cancelled',
            StateMachineService::REFUNDING => 'mail.subject_order_refunding',
            default                        => 'mail.subject_order_updated',
        };
        $mailType = match ((string) $this->order->status) {
            StateMachineService::PAID      => 'order_paid',
            StateMachineService::SHIPPED   => 'order_shipped',
            StateMachineService::COMPLETED => 'order_completed',
            StateMachineService::CANCELLED => 'order_cancelled',
            StateMachineService::REFUNDING => 'order_refunding',
            default                        => 'order_update',
        };

        return $this
            ->subject(__($subjectKey, ['number' => $this->order->number]))
            ->view('mails.order_update', ['order' => $this->order, 'from_code' => $this->fromCode])
            ->withSymfonyMessage(static function ($message) use ($mailType): void {
                $message->getHeaders()->addTextHeader('X-BK-Mail-Type', $mailType);
            });
    }
}
