<?php

namespace App\Mail;

use App\Modules\Payment\Models\UserPayment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;

class PaymentConfirmationMail extends Mailable
{
    use SerializesModels;

    public function __construct(public UserPayment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment Confirmation');
    }

    public function content(): Content
    {
        return new Content(
            htmlString: new HtmlString(
                '<p>Your payment of ' . number_format((float) $this->payment->amount, 2) . ' '
                . e($this->payment->currency) . ' has been received.</p>'
            )
        );
    }
}
