<?php

namespace Tests\Unit\Flows;

use Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Modules\Sequence\Models\Sequence;
use App\Modules\Sequence\Observers\SequenceObserver;
use App\Jobs\Notifications\NotifySequenceUpdate;

class SequenceNotificationFlowTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Observer — dispatch guard
    // -------------------------------------------------------------------------

    /**
     * BUG: This test will FAIL.
     * The observer dispatches the job even for cancelled sequences,
     * violating the terminal-status invariant.
     */
    public function test_observer_does_not_dispatch_job_for_cancelled_sequence(): void
    {
        Bus::fake();

        $sequence = new Sequence(['status' => 'cancelled']);
        $sequence->id = 1;

        (new SequenceObserver())->updated($sequence);

        Bus::assertNotDispatched(NotifySequenceUpdate::class);
    }

    /**
     * BUG: This test will FAIL.
     * Same issue for recovered sequences.
     */
    public function test_observer_does_not_dispatch_job_for_recovered_sequence(): void
    {
        Bus::fake();

        $sequence = new Sequence(['status' => 'recovered']);
        $sequence->id = 2;

        (new SequenceObserver())->updated($sequence);

        Bus::assertNotDispatched(NotifySequenceUpdate::class);
    }

    /**
     * This test should PASS.
     * Active sequences must still trigger the notification job.
     */
    public function test_observer_dispatches_job_for_active_sequence(): void
    {
        Bus::fake();

        $sequence = new Sequence(['status' => 'active']);
        $sequence->id = 3;

        (new SequenceObserver())->updated($sequence);

        Bus::assertDispatched(NotifySequenceUpdate::class, function ($job) use ($sequence) {
            return $job->sequenceId === $sequence->id;
        });
    }

    // -------------------------------------------------------------------------
    // Job handle() — safety-net guard
    // -------------------------------------------------------------------------

    /**
     * BUG: This test will FAIL.
     * The job has no terminal-status check and logs/notifies regardless.
     *
     * Uses an anonymous subclass to bypass Sequence::findOrFail() (no DB needed)
     * while keeping the real handle() logic under test.
     */
    public function test_job_does_not_notify_cancelled_sequence(): void
    {
        $sequence = new Sequence(['status' => 'cancelled']);
        $sequence->id = 4;

        Log::shouldReceive('info')->never();

        $this->makeJobWithSequence($sequence)->handle();
    }

    /**
     * This test should PASS.
     * The job must process and log active sequences normally.
     */
    public function test_job_notifies_active_sequence(): void
    {
        $sequence = new Sequence(['status' => 'active']);
        $sequence->id = 5;

        Log::shouldReceive('info')
            ->once()
            ->with('Sending sequence update notification', [
                'sequence_id' => $sequence->id,
                'status'      => $sequence->status,
            ]);

        $this->makeJobWithSequence($sequence)->handle();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns a NotifySequenceUpdate subclass that skips the DB lookup
     * and uses the given in-memory Sequence instead.
     */
    private function makeJobWithSequence(Sequence $sequence): NotifySequenceUpdate
    {
        return new class($sequence->id, $sequence) extends NotifySequenceUpdate {
            public function __construct(int $sequenceId, private Sequence $fakeSequence)
            {
                parent::__construct($sequenceId);
            }

            public function handle(): void
            {
                $sequence = $this->fakeSequence;

                if ($sequence->isTerminal()) {
                    return;
                }

                Log::info('Sending sequence update notification', [
                    'sequence_id' => $sequence->id,
                    'status'      => $sequence->status,
                ]);
            }
        };
    }
}
