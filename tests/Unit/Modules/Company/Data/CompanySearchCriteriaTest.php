<?php

namespace Tests\Unit\Modules\Company\Data;

use App\Modules\Company\Data\CompanySearchCriteria;
use App\Modules\Company\Enums\CompanyStatusFilter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompanySearchCriteriaTest extends TestCase
{
    public function test_creates_with_valid_data(): void
    {
        $criteria = CompanySearchCriteria::validateAndCreate([
            'q' => 'test',
            'status' => 'active',
            'per_page' => 10,
        ]);

        $this->assertSame('test', $criteria->q);
        $this->assertSame(CompanyStatusFilter::Active, $criteria->status);
        $this->assertSame(10, $criteria->perPage);
    }

    public function test_applies_default_status(): void
    {
        $criteria = CompanySearchCriteria::validateAndCreate(['q' => 'test']);

        $this->assertSame(CompanyStatusFilter::All, $criteria->status);
    }

    public function test_applies_default_per_page(): void
    {
        $criteria = CompanySearchCriteria::validateAndCreate(['q' => 'test']);

        $this->assertSame(15, $criteria->perPage);
    }

    public function test_rejects_missing_q(): void
    {
        $this->expectException(ValidationException::class);

        CompanySearchCriteria::validateAndCreate([]);
    }

    public function test_rejects_q_shorter_than_two_characters(): void
    {
        $this->expectException(ValidationException::class);

        CompanySearchCriteria::validateAndCreate(['q' => 'a']);
    }

    public function test_rejects_per_page_above_maximum(): void
    {
        $this->expectException(ValidationException::class);

        CompanySearchCriteria::validateAndCreate(['q' => 'test', 'per_page' => 101]);
    }

    public function test_rejects_per_page_below_minimum(): void
    {
        $this->expectException(ValidationException::class);

        CompanySearchCriteria::validateAndCreate(['q' => 'test', 'per_page' => 0]);
    }

    public function test_rejects_invalid_status(): void
    {
        $this->expectException(ValidationException::class);

        CompanySearchCriteria::validateAndCreate(['q' => 'test', 'status' => 'invalid']);
    }

    public function test_maps_per_page_from_snake_case(): void
    {
        $criteria = CompanySearchCriteria::validateAndCreate([
            'q' => 'test',
            'per_page' => 25,
        ]);

        $this->assertSame(25, $criteria->perPage);
    }
}
