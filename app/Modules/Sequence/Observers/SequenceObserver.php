<?php

namespace App\Modules\Sequence\Observers;

use App\Modules\Sequence\Models\Sequence;
use App\Jobs\Notifications\NotifySequenceUpdate;

class SequenceObserver
{
    public function updated(Sequence $sequence): void
    {
        if ($sequence->isTerminal()) {
            return;
        }

        NotifySequenceUpdate::dispatch($sequence->id);
    }
}
