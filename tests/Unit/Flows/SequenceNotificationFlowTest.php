<?php

namespace Tests\Unit\Flows;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Modules\Sequence\Models\Sequence;
use App\Jobs\Notifications\NotifySequenceUpdate;

/**
 * Invariant: Terminal sequences (cancelled, recovered) never receive notifications.
 *
 * This is enforced at two layers:
 *   1. SequenceObserver skips dispatch for terminal sequences.
 *   2. NotifySequenceUpdate::handle() returns early if the sequence is terminal.
 */
class SequenceNotificationFlowTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Observer layer tests
    // -------------------------------------------------------------------------

    public function test_cancelled_sequence_does_not_dispatch_notification_job(): void
    {
        Bus::fake();

        $sequence = Sequence::factory()->cancelled()->create();
        $sequence->update(['amount' => $sequence->amount + 1]);

        Bus::assertNotDispatched(NotifySequenceUpdate::class);
    }

    public function test_recovered_sequence_does_not_dispatch_notification_job(): void
    {
        Bus::fake();

        $sequence = Sequence::factory()->recovered()->create();
        $sequence->update(['amount' => $sequence->amount + 1]);

        Bus::assertNotDispatched(NotifySequenceUpdate::class);
    }

    public function test_active_sequence_dispatches_notification_job(): void
    {
        Bus::fake();

        $sequence = Sequence::factory()->create(['status' => 'active']);
        $sequence->update(['amount' => $sequence->amount + 1]);

        Bus::assertDispatched(NotifySequenceUpdate::class, function ($job) use ($sequence) {
            return $job->sequenceId === $sequence->id;
        });
    }

    // -------------------------------------------------------------------------
    // Job safety-net layer tests
    // -------------------------------------------------------------------------

    public function test_job_handle_skips_notification_for_cancelled_sequence(): void
    {
        Log::spy();

        $sequence = Sequence::factory()->cancelled()->create();
        $job = new NotifySequenceUpdate($sequence->id);
        $job->handle();

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Skipping notification for terminal sequence', \Mockery::subset([
                'sequence_id' => $sequence->id,
            ]));

        Log::shouldNotHaveReceived('info', ['Sending sequence update notification']);
    }

    public function test_job_handle_sends_notification_for_active_sequence(): void
    {
        Log::spy();

        $sequence = Sequence::factory()->create(['status' => 'active']);
        $job = new NotifySequenceUpdate($sequence->id);
        $job->handle();

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Sending sequence update notification', \Mockery::subset([
                'sequence_id' => $sequence->id,
            ]));
    }
}
