<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Services\CompanyNormalizer;
use App\Modules\ContactFinder\ValueObjects\NormalizedCompany;
use PHPUnit\Framework\TestCase;

class CompanyNormalizerTest extends TestCase
{
    private CompanyNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new CompanyNormalizer();
    }

    public function test_strips_llc_suffix(): void
    {
        $result = $this->normalizer->normalizeName('Cedar Ridge Plumbing LLC');
        $this->assertEquals('cedar ridge plumbing', $result);
    }

    public function test_strips_inc_suffix(): void
    {
        $result = $this->normalizer->normalizeName('Pioneer Landscaping Inc');
        $this->assertEquals('pioneer landscaping', $result);
    }

    public function test_strips_co_suffix(): void
    {
        $result = $this->normalizer->normalizeName('Hometown Hardware Co');
        $this->assertEquals('hometown hardware', $result);
    }

    public function test_removes_punctuation(): void
    {
        $result = $this->normalizer->normalizeName('Riverside Print & Sign');
        $this->assertEquals('riverside print sign', $result);
    }

    public function test_handles_extra_whitespace(): void
    {
        $result = $this->normalizer->normalizeName('  Cedar   Ridge   Plumbing   LLC  ');
        $this->assertEquals('cedar ridge plumbing', $result);
    }

    public function test_preserves_single_word_company_names(): void
    {
        $result = $this->normalizer->normalizeName('LLC');
        $this->assertEquals('llc', $result);
    }

    public function test_normalize_returns_normalized_company(): void
    {
        $result = $this->normalizer->normalize('Cedar Ridge Plumbing LLC', '4821 Maple Ave, Lincoln, NE 68504');

        $this->assertInstanceOf(NormalizedCompany::class, $result);
        $this->assertEquals('Cedar Ridge Plumbing LLC', $result->originalName);
        $this->assertEquals('cedar ridge plumbing', $result->normalizedName);
        $this->assertEquals('4821 Maple Ave, Lincoln, NE 68504', $result->address);
        $this->assertNotEmpty($result->fingerprint);
    }

    public function test_same_company_generates_same_fingerprint(): void
    {
        $resultA = $this->normalizer->normalize('Cedar Ridge Plumbing LLC', '4821 Maple Ave');
        $resultB = $this->normalizer->normalize('Cedar Ridge Plumbing LLC', '4821 Maple Ave');

        $this->assertEquals($resultA->fingerprint, $resultB->fingerprint);
    }

    public function test_different_address_generates_different_fingerprint(): void
    {
        $resultA = $this->normalizer->normalize('Cedar Ridge Plumbing LLC', '4821 Maple Ave');
        $resultB = $this->normalizer->normalize('Cedar Ridge Plumbing LLC', '100 Main St');

        $this->assertNotEquals($resultA->fingerprint, $resultB->fingerprint);
    }

    public function test_deduplicate_removes_exact_duplicates(): void
    {
        $companyA = $this->normalizer->normalize('Cedar Ridge Plumbing LLC', '4821 Maple Ave');
        $companyB = $this->normalizer->normalize('Cedar Ridge Plumbing LLC', '4821 Maple Ave');
        $companyC = $this->normalizer->normalize('Bayview Auto Repair', '129 Harbor St');

        $deduplicated = $this->normalizer->deduplicate([$companyA, $companyB, $companyC]);

        $this->assertCount(2, $deduplicated);
    }

    public function test_deduplicate_preserves_first_occurrence(): void
    {
        $companyA = $this->normalizer->normalize('Cedar Ridge Plumbing LLC', '4821 Maple Ave');
        $companyB = $this->normalizer->normalize('Cedar Ridge Plumbing LLC', '4821 Maple Ave');

        $deduplicated = $this->normalizer->deduplicate([$companyA, $companyB]);

        $this->assertCount(1, $deduplicated);
        $this->assertSame($companyA, $deduplicated[0]);
    }
}
