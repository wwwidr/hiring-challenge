<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Modules\Sequence\Models\Sequence;
use App\Modules\Sequence\Observers\SequenceObserver;
use App\Modules\Payment\Models\UserPayment;
use App\Modules\Payment\Observers\UserPaymentObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Sequence::observe(SequenceObserver::class);
        UserPayment::observe(UserPaymentObserver::class);
    }
}
