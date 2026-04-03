<?php

namespace Tests\Unit\Modules\Company\Enums;

use App\Modules\Company\Enums\CompanyStatus;
use App\Modules\Company\Enums\CompanyStatusFilter;
use PHPUnit\Framework\TestCase;

class CompanyStatusTest extends TestCase
{
    public function test_company_status_has_active_value(): void
    {
        $this->assertSame('active', CompanyStatus::Active->value);
    }

    public function test_company_status_has_inactive_value(): void
    {
        $this->assertSame('inactive', CompanyStatus::Inactive->value);
    }

    public function test_company_status_try_from_returns_null_for_invalid(): void
    {
        $this->assertNull(CompanyStatus::tryFrom('invalid'));
    }

    public function test_company_status_try_from_returns_enum_for_valid(): void
    {
        $this->assertSame(CompanyStatus::Active, CompanyStatus::tryFrom('active'));
        $this->assertSame(CompanyStatus::Inactive, CompanyStatus::tryFrom('inactive'));
    }

    public function test_company_status_filter_includes_all(): void
    {
        $this->assertSame('all', CompanyStatusFilter::All->value);
    }

    public function test_company_status_filter_has_same_values_as_status(): void
    {
        $this->assertSame(CompanyStatus::Active->value, CompanyStatusFilter::Active->value);
        $this->assertSame(CompanyStatus::Inactive->value, CompanyStatusFilter::Inactive->value);
    }

    public function test_company_status_filter_try_from_returns_null_for_invalid(): void
    {
        $this->assertNull(CompanyStatusFilter::tryFrom('invalid'));
    }
}
