<?php

namespace App\Modules\Payment\Observers;

use App\Jobs\Notifications\SendPaymentConfirmation;
use App\Modules\Payment\Models\UserPayment;

class UserPaymentObserver
{
    private const ALLOWED_SEQUENCE_STATUSES = ['active', 'installment'];

    public function created(UserPayment $payment): void
    {
        if ($payment->status !== 'completed') {
            return;
        }

        $payment->loadMissing('sequence');

        if (! in_array($payment->sequence->status, self::ALLOWED_SEQUENCE_STATUSES)) {
            return;
        }

        SendPaymentConfirmation::dispatch($payment->id);
    }
}
