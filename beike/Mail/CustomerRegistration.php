<?php

/**
 * CustomerRegistration.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-12-22 14:09:37
 * @modified   2022-12-22 14:09:37
 */

namespace Beike\Mail;

use Beike\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

class CustomerRegistration extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    private Customer $customer;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        App::setLocale($this->customer->locale ?: config('app.locale'));

        return $this
            ->subject(__('mail.subject_registration', ['store' => site_brand_name()]))
            ->view('mails.registration', ['customer' => $this->customer])
            ->withSymfonyMessage(static function ($message): void {
                $message->getHeaders()->addTextHeader('X-BK-Mail-Type', 'customer_registration');
            });
    }
}
