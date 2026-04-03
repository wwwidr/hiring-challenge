<?php

namespace App\Modules\Payment\Observers;

use App\Modules\Payment\Models\UserPayment;

class UserPaymentObserver
{
    public function created(UserPayment $payment): void
    {
        // TICKET-003: Candidates should wire PaymentConfirmation here
    }
}
