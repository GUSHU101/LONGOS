<?php

/**
 * CustomerNewOrder.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-12-22 14:09:37
 * @modified   2022-12-22 14:09:37
 */

namespace Beike\Mail;

use Beike\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

class CustomerNewOrder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    private Order $order;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
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

        return $this
            ->subject(__('mail.subject_payment_pending', ['number' => $this->order->number]))
            ->view('mails.order_new', ['order' => $this->order])
            ->withSymfonyMessage(static function ($message): void {
                $message->getHeaders()->addTextHeader('X-BK-Mail-Type', 'payment_pending');
            });
    }
}
