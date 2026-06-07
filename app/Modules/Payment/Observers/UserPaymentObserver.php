<?php

namespace App\Modules\Payment\Observers;

use App\Jobs\Notifications\SendPaymentConfirmation;
use App\Modules\Payment\Models\UserPayment;

class UserPaymentObserver
{
    public function created(UserPayment $payment): void
    {
        if ($payment->status === 'completed') {
            SendPaymentConfirmation::dispatch($payment->id);
        }
    }
}
