<?php

declare(strict_types=1);

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

    // --- Job handle() gate tests ---

    public function test_cancelled_sequence_does_not_send_email(): void
    {
        Mail::fake();

        $payment = UserPayment::factory()
            ->for(Sequence::factory()->cancelled())
            ->create(['status' => 'completed']);

        (new SendPaymentConfirmation($payment->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_active_sequence_sends_email(): void
    {
        Mail::fake();

        $payment = UserPayment::factory()
            ->for(Sequence::factory())
            ->create(['status' => 'completed']);

        (new SendPaymentConfirmation($payment->id))->handle();

        Mail::assertSent(PaymentConfirmationMail::class);
    }

    public function test_job_logs_skip_reason_when_gate_blocks(): void
    {
        Log::spy();

        $payment = UserPayment::factory()
            ->for(Sequence::factory()->cancelled())
            ->create(['status' => 'completed']);

        (new SendPaymentConfirmation($payment->id))->handle();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            return isset($context['payment_id'], $context['sequence_id'], $context['sequence_status'], $context['reason']);
        });
    }

    public function test_installment_sequence_passes_gate(): void
    {
        Mail::fake();

        $payment = UserPayment::factory()
            ->for(Sequence::factory()->installment())
            ->create(['status' => 'completed']);

        (new SendPaymentConfirmation($payment->id))->handle();

        Mail::assertSent(PaymentConfirmationMail::class);
    }

    public function test_recovered_sequence_blocks_notification(): void
    {
        Mail::fake();

        $payment = UserPayment::factory()
            ->for(Sequence::factory()->recovered())
            ->create(['status' => 'completed']);

        (new SendPaymentConfirmation($payment->id))->handle();

        Mail::assertNothingSent();
    }

    // --- Observer dispatch tests ---

    public function test_observer_dispatches_job_for_completed_payment(): void
    {
        Queue::fake();

        UserPayment::factory()
            ->for(Sequence::factory())
            ->create(['status' => 'completed']);

        Queue::assertPushed(SendPaymentConfirmation::class);
    }

    public function test_observer_does_not_dispatch_for_pending_payment(): void
    {
        Queue::fake();

        UserPayment::factory()
            ->for(Sequence::factory())
            ->pending()
            ->create();

        Queue::assertNotPushed(SendPaymentConfirmation::class);
    }

    public function test_observer_does_not_dispatch_for_completed_payment_on_cancelled_sequence(): void
    {
        Queue::fake();

        UserPayment::factory()
            ->for(Sequence::factory()->cancelled())
            ->create(['status' => 'completed']);

        Queue::assertNotPushed(SendPaymentConfirmation::class);
    }

    public function test_job_skips_and_logs_when_recipient_not_configured(): void
    {
        Mail::fake();
        Log::spy();

        config(['mail.payment_confirmation_recipient' => '']);

        $payment = UserPayment::factory()
            ->for(Sequence::factory())
            ->create(['status' => 'completed']);

        (new SendPaymentConfirmation($payment->id))->handle();

        Mail::assertNothingSent();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            return isset($context['payment_id'], $context['sequence_id'], $context['sequence_status'], $context['reason'])
                && str_contains($context['reason'], 'recipient');
        });
    }
}
