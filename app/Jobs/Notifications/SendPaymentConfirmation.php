<?php

declare(strict_types=1);

namespace App\Jobs\Notifications;

use App\Mail\PaymentConfirmationMail;
use App\Modules\Payment\Models\UserPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPaymentConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ALLOWED_STATUSES = ['active', 'installment'];

    public function __construct(public int $paymentId) {}

    public function handle(): void
    {
        $payment = UserPayment::with('sequence')->findOrFail($this->paymentId);
        $sequence = $payment->sequence;

        if (! in_array($sequence->status, self::ALLOWED_STATUSES)) {
            Log::info('PaymentConfirmation skipped: sequence status gate blocked', [
                'payment_id'      => $payment->id,
                'sequence_id'     => $sequence->id,
                'sequence_status' => $sequence->status,
                'reason'          => 'sequence status not in allowed statuses',
            ]);

            return;
        }

        $recipient = config('mail.payment_confirmation_recipient');

        if (empty($recipient)) {
            Log::info('PaymentConfirmation skipped: no recipient configured', [
                'payment_id'      => $payment->id,
                'sequence_id'     => $sequence->id,
                'sequence_status' => $sequence->status,
                'reason'          => 'no recipient configured',
            ]);

            return;
        }

        Mail::to($recipient)->send(new PaymentConfirmationMail($payment));

        Log::info('PaymentConfirmation sent', [
            'payment_id'  => $payment->id,
            'sequence_id' => $sequence->id,
        ]);
    }
}
