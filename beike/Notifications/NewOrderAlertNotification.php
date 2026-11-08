<?php

/**
 * NewOrderAlertNotification.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-12-22 14:09:37
 * @modified   2022-12-22 14:09:37
 */

namespace Beike\Notifications;

use App\Mail\MailSenderProfileService;
use Beike\Mail\AdminUserNewOrder;
use Beike\Models\Order;
use Beike\Notifications\Concerns\HasMailSenderProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewOrderAlertNotification extends Notification implements ShouldQueue
{
    use HasMailSenderProfile;
    use Queueable;

    private Order $order;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
        $this->initializeMailDeliveryId();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        // A newly created unpaid checkout is not a sale. Persist the admin
        // timeline notification, but do not generate a transactional email
        // until payment has actually been confirmed by the provider.
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return AdminUserNewOrder
     */
    public function toMail($notifiable)
    {
        return $this->withSenderProfile(
            (new AdminUserNewOrder($this->order))->to(system_setting('base.email')),
            MailSenderProfileService::ORDERS
        );
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }

    /**
     * 保存到 DB
     * @return Order[]
     */
    public function toDatabase()
    {
        return [
            'order' => $this->order,
        ];
    }
}
