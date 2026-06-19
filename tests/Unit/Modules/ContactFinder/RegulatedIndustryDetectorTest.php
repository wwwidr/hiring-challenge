<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Services\RegulatedIndustryDetector;
use PHPUnit\Framework\TestCase;

class RegulatedIndustryDetectorTest extends TestCase
{
    private RegulatedIndustryDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new RegulatedIndustryDetector();
    }

    public function test_detects_dental_as_healthcare(): void
    {
        $this->assertEquals('healthcare', $this->detector->detect('Magnolia Family Dental'));
    }

    public function test_detects_veterinary_as_healthcare(): void
    {
        $this->assertEquals('healthcare', $this->detector->detect('Brookside Veterinary Clinic'));
    }

    public function test_detects_medical_as_healthcare(): void
    {
        $this->assertEquals('healthcare', $this->detector->detect('First Choice Medical Center'));
    }

    public function test_detects_financial_as_finance(): void
    {
        $this->assertEquals('finance', $this->detector->detect('Pacific Financial Advisors'));
    }

    public function test_detects_insurance_as_finance(): void
    {
        $this->assertEquals('finance', $this->detector->detect('Mountain State Insurance'));
    }

    public function test_detects_accounting_as_finance(): void
    {
        $this->assertEquals('finance', $this->detector->detect('Downtown Accounting CPA'));
    }

    public function test_returns_null_for_non_regulated(): void
    {
        $this->assertNull($this->detector->detect('Cedar Ridge Plumbing LLC'));
    }

    public function test_returns_null_for_general_businesses(): void
    {
        $this->assertNull($this->detector->detect('Ironclad Welding & Fabrication'));
        $this->assertNull($this->detector->detect('Golden Harvest Catering Co.'));
        $this->assertNull($this->detector->detect('Summit Auto Repair'));
    }

    public function test_is_regulated_returns_boolean(): void
    {
        $this->assertTrue($this->detector->isRegulated('Valley Dental Clinic'));
        $this->assertFalse($this->detector->isRegulated('Cedar Ridge Plumbing'));
    }

    public function test_case_insensitive_detection(): void
    {
        $this->assertEquals('healthcare', $this->detector->detect('MAGNOLIA FAMILY DENTAL'));
        $this->assertEquals('finance', $this->detector->detect('PACIFIC FINANCIAL'));
    }
}
