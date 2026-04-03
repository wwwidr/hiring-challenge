<?php

namespace Tests\Unit\Modules\Sequence;

use PHPUnit\Framework\TestCase;
use App\Modules\Sequence\Models\Sequence;

class SequenceModelTest extends TestCase
{
    public function test_terminal_statuses_are_defined(): void
    {
        $this->assertContains('cancelled', Sequence::TERMINAL_STATUSES);
        $this->assertContains('recovered', Sequence::TERMINAL_STATUSES);
    }

    public function test_active_statuses_are_defined(): void
    {
        $this->assertContains('active', Sequence::ACTIVE_STATUSES);
        $this->assertContains('installment', Sequence::ACTIVE_STATUSES);
        $this->assertContains('partially_paid_recovery', Sequence::ACTIVE_STATUSES);
        $this->assertContains('will_pay_later', Sequence::ACTIVE_STATUSES);
    }

    public function test_is_terminal_returns_true_for_cancelled(): void
    {
        $sequence = new Sequence(['status' => 'cancelled']);
        $this->assertTrue($sequence->isTerminal());
    }

    public function test_is_terminal_returns_false_for_active(): void
    {
        $sequence = new Sequence(['status' => 'active']);
        $this->assertFalse($sequence->isTerminal());
    }

    public function test_is_active_returns_true_for_active_statuses(): void
    {
        foreach (Sequence::ACTIVE_STATUSES as $status) {
            $sequence = new Sequence(['status' => $status]);
            $this->assertTrue($sequence->isActive(), "Expected isActive() to be true for status: {$status}");
        }
    }

    public function test_is_active_returns_false_for_terminal_statuses(): void
    {
        foreach (Sequence::TERMINAL_STATUSES as $status) {
            $sequence = new Sequence(['status' => $status]);
            $this->assertFalse($sequence->isActive(), "Expected isActive() to be false for status: {$status}");
        }
    }
}
