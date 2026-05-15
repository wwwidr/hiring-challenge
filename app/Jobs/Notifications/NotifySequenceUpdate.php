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

    
    public const TERMINAL_STATUSES = ['cancelled', 'recovered'];

    public function __construct(public int $sequenceId) {}

    public function handle(): void
    {
        $sequence = Sequence::findOrFail($this->sequenceId);
        if(in_array($sequence->status,self::TERMINAL_STATUSES,true)){
            Log::info('Skipping sequence update notification', [
                'sequence_id' => $sequence->id,
                'status' => $sequence->status,
            ]);
            return;
        }
        // BUG: No status check here — should skip terminal sequences
        Log::info('Sending sequence update notification', [
            'sequence_id' => $sequence->id,
            'status' => $sequence->status,
        ]);

        // ... notification logic would go here
    }
}
