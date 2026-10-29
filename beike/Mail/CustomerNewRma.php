<?php

/**
 * CustomerNewRma.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-12-22 14:09:37
 * @modified   2022-12-22 14:09:37
 */

namespace Beike\Mail;

use Beike\Models\Rma;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

class CustomerNewRma extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    private Rma $rma;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(Rma $rma)
    {
        $this->rma = $rma;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        App::setLocale($this->rma->order?->locale ?: config('app.locale'));

        return $this
            ->subject(__('mail.subject_return_received', ['number' => $this->rma->order?->number]))
            ->view('mails.rma_new', ['rma' => $this->rma])
            ->withSymfonyMessage(static function ($message): void {
                $message->getHeaders()->addTextHeader('X-BK-Mail-Type', 'rma_new');
            });
    }
}
