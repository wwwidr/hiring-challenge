<?php

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

    // Narrower than Sequence::ACTIVE_STATUSES — only confirmed payment contexts
    private const ELIGIBLE_STATUSES = ['active', 'installment'];

    public function __construct(public int $paymentId) {}

    public function handle(): void
    {
        $payment = UserPayment::findOrFail($this->paymentId);
        $sequence = $payment->sequence;

        if (!in_array($sequence->status, self::ELIGIBLE_STATUSES)) {
            Log::info('Payment confirmation skipped', [
                'payment_id'  => $payment->id,
                'sequence_id' => $sequence->id,
                'status'      => $sequence->status,
                'reason'      => 'sequence status not eligible for payment confirmation',
            ]);

            return;
        }

        // TODO: replace stub recipient with real contact lookup once Contact model is available
        Mail::to('debtor@example.com')->send(new PaymentConfirmationMail($payment));

        Log::info('Payment confirmation sent', [
            'payment_id'  => $payment->id,
            'sequence_id' => $sequence->id,
        ]);
    }
}
