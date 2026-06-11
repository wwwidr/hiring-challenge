<?php

namespace Tests\Unit\Flows;

use App\Jobs\Notifications\SendPaymentConfirmation;
use App\Mail\PaymentConfirmationMail;
use App\Modules\Payment\Models\UserPayment;
use App\Modules\Sequence\Models\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentConfirmationFlowTest extends TestCase
{
    use RefreshDatabase;

    // --- Job handle() contract tests ---

    public function test_confirmation_mail_is_sent_for_active_sequence(): void
    {
        Mail::fake();

        $sequence = Sequence::factory()->create(['status' => 'active']);
        $payment  = UserPayment::factory()->create(['sequence_id' => $sequence->id, 'status' => 'completed']);

        (new SendPaymentConfirmation($payment->id))->handle();

        Mail::assertSent(PaymentConfirmationMail::class, fn ($mail) => $mail->payment->id === $payment->id);
    }

    public function test_confirmation_mail_is_sent_for_installment_sequence(): void
    {
        Mail::fake();

        $sequence = Sequence::factory()->installment()->create();
        $payment  = UserPayment::factory()->create(['sequence_id' => $sequence->id, 'status' => 'completed']);

        (new SendPaymentConfirmation($payment->id))->handle();

        Mail::assertSent(PaymentConfirmationMail::class);
    }

    public function test_confirmation_mail_is_not_sent_for_cancelled_sequence(): void
    {
        Mail::fake();

        $sequence = Sequence::factory()->cancelled()->create();
        $payment  = UserPayment::factory()->create(['sequence_id' => $sequence->id, 'status' => 'completed']);

        (new SendPaymentConfirmation($payment->id))->handle();

        Mail::assertNotSent(PaymentConfirmationMail::class);
    }

    public function test_confirmation_mail_is_not_sent_for_recovered_sequence(): void
    {
        Mail::fake();

        $sequence = Sequence::factory()->recovered()->create();
        $payment  = UserPayment::factory()->create(['sequence_id' => $sequence->id, 'status' => 'completed']);

        (new SendPaymentConfirmation($payment->id))->handle();

        Mail::assertNotSent(PaymentConfirmationMail::class);
    }

    public function test_skip_reason_is_logged_when_gate_blocks(): void
    {
        Mail::fake();
        Log::spy();

        $sequence = Sequence::factory()->cancelled()->create();
        $payment  = UserPayment::factory()->create(['sequence_id' => $sequence->id, 'status' => 'completed']);

        (new SendPaymentConfirmation($payment->id))->handle();

        Log::shouldHaveReceived('info')
            ->atLeast()->once()
            ->withArgs(fn ($message, $context) =>
                $message === 'Payment confirmation skipped' &&
                $context['sequence_id'] === $sequence->id &&
                $context['status'] === 'cancelled'
            );
    }

    // --- Observer dispatch contract tests ---

    public function test_observer_dispatches_job_when_completed_payment_is_created(): void
    {
        Queue::fake();

        $sequence = Sequence::factory()->create(['status' => 'active']);
        UserPayment::factory()->create(['sequence_id' => $sequence->id, 'status' => 'completed']);

        Queue::assertPushed(SendPaymentConfirmation::class);
    }

    public function test_observer_does_not_dispatch_job_for_pending_payment(): void
    {
        Queue::fake();

        $sequence = Sequence::factory()->create(['status' => 'active']);
        UserPayment::factory()->pending()->create(['sequence_id' => $sequence->id]);

        Queue::assertNotPushed(SendPaymentConfirmation::class);
    }
}
