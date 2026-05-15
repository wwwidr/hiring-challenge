<?php

namespace App\Modules\Sequence\Observers;

use App\Modules\Sequence\Models\Sequence;
use App\Jobs\Notifications\NotifySequenceUpdate;

class SequenceObserver
{
    
    public const TERMINAL_STATUSES = ['cancelled', 'recovered'];
    public function updated(Sequence $sequence): void
    {
        // BUG: This dispatches for ALL sequences, including cancelled ones
        if(in_array($sequence->status,self::TERMINAL_STATUSES,true)){
            return;
        }
        NotifySequenceUpdate::dispatch($sequence->id);
    }
}
