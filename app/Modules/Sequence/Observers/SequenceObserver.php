<?php

namespace App\Modules\Sequence\Observers;

use App\Modules\Sequence\Models\Sequence;
use App\Jobs\Notifications\NotifySequenceUpdate;

class SequenceObserver
{
    public function updated(Sequence $sequence): void
    {
        // BUG: This dispatches for ALL sequences, including cancelled ones
        NotifySequenceUpdate::dispatch($sequence->id);
    }
}
