<?php

namespace Beike\Mail;

use Beike\Models\Discount;
use Beike\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AbandonedOrderRecoveryMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array{subject: string, heading: string, message: string, button_label: string, discount_message: string} $template
     */
    public function __construct(
        private Order $order,
        private array $template,
        private ?Discount $discount,
        private string $recoveryUrl,
    ) {}

    public function build(): self
    {
        return $this->subject($this->template['subject'])
            ->view('mails.abandoned_order_recovery', [
                'order'       => $this->order,
                'template'    => $this->template,
                'discount'    => $this->discount,
                'recoveryUrl' => $this->recoveryUrl,
            ])
            ->withSymfonyMessage(static function ($message): void {
                $message->getHeaders()->addTextHeader('X-BK-Mail-Type', 'abandoned_order_recovery');
            });
    }
}
