<?php

namespace Tests\Unit\Flows;

use App\Jobs\Notifications\NotifySequenceUpdate;
use App\Modules\Sequence\Models\Sequence;
use Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SequenceNotificationFlowTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate');
    }
    public function test_cancelled_sequence(): void
    {
        Queue::fake();
        $seq=Sequence::factory()->create([
            'status'=>'cancelled',
            'amount'=>1,
        ]);
        $seq->update([
            'amount'=>999,
        ]);
        Queue::assertNotPushed(NotifySequenceUpdate::class);
    }
    
    public function test_recovered_sequence(): void
    {
        Queue::fake();
        $seq=Sequence::factory()->create([
            'status'=>'recovered',
            'amount'=>1,
        ]);
        $seq->update([
            'amount'=>999,
        ]);
        Queue::assertNotPushed(NotifySequenceUpdate::class);
    }
    
    public function test_active_sequence(): void
    {
        Queue::fake();
        $seq=Sequence::factory()->create([
            'status'=>'active',
            'amount'=>1,
        ]);
        $seq->update([
            'amount'=>999,
        ]);
        Queue::assertPushed(NotifySequenceUpdate::class,function ($job) use ($seq){
            return $job->sequenceId=== $seq->id;
        });
    }
}
