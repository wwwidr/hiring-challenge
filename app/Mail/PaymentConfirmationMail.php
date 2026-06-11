<?php

namespace App\Mail;

use App\Modules\Payment\Models\UserPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public UserPayment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment Confirmation');
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.payment-confirmation-text',
        );
    }
}
