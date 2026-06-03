<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Support\RoleClassifier;
use PHPUnit\Framework\TestCase;

class RoleClassifierTest extends TestCase
{
    private RoleClassifier $roles;

    protected function setUp(): void
    {
        $this->roles = new RoleClassifier;
    }

    public function test_persona_priority_order_matches_clarifications(): void
    {
        // AP < owner < finance < manager (lower rank = higher priority).
        $this->assertLessThan($this->roles->rank('Owner'), $this->roles->rank('Accounts Payable'));
        $this->assertLessThan($this->roles->rank('CFO'), $this->roles->rank('Owner'));
        $this->assertLessThan($this->roles->rank('Office Manager'), $this->roles->rank('CFO'));
    }

    public function test_accounts_payable_scores_highest(): void
    {
        $this->assertGreaterThan($this->roles->points('Owner'), $this->roles->points('Accounts Payable'));
        $this->assertSame(15, $this->roles->points('Accounts Payable'));
    }

    public function test_registered_agent_is_weak(): void
    {
        $this->assertTrue($this->roles->isRegisteredAgent('Registered Agent'));
        $this->assertSame(2, $this->roles->points('Registered Agent'));
        $this->assertGreaterThan($this->roles->points('Registered Agent'), $this->roles->points('Owner'));
    }

    public function test_president_classified_as_owner_tier(): void
    {
        $this->assertSame(RoleClassifier::OWNER, $this->roles->classify('President'));
        $this->assertSame('Owner', $this->roles->label('President'));
    }

    public function test_unknown_role_scores_zero(): void
    {
        $this->assertSame(0, $this->roles->points(null));
        $this->assertSame(0, $this->roles->points('Wizard'));
    }
}
