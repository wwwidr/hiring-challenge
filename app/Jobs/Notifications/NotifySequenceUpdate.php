<?php

namespace App\Jobs\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Modules\Sequence\Models\Sequence;

class NotifySequenceUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $sequenceId) {}

    public function handle(): void
    {
        $sequence = Sequence::findOrFail($this->sequenceId);

        // Safety net: terminal sequences never receive notifications.
        if ($sequence->isTerminal()) {
            Log::info('Skipping notification for terminal sequence', [
                'sequence_id' => $sequence->id,
                'status' => $sequence->status,
            ]);
            return;
        }

        Log::info('Sending sequence update notification', [
            'sequence_id' => $sequence->id,
            'status' => $sequence->status,
        ]);

        // ... notification logic would go here
    }
}
