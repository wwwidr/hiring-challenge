<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\ContactFinder;
use PHPUnit\Framework\TestCase;

class ContactFinderTest extends TestCase
{
    private ContactFinder $finder;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $results;

    protected function setUp(): void
    {
        $this->finder = new ContactFinder;
        $root = dirname(__DIR__, 4);

        $this->results = $this->finder->resolveRows(
            $this->finder->loadCompanies($root.'/challenge/data/companies.csv'),
            $this->finder->loadProviderResponses($root.'/challenge/mocks/enrichment_responses.json'),
        );
    }

    public function test_high_confidence_row_with_agreeing_sources(): void
    {
        $row = $this->rowForCompany('Cedar Ridge Plumbing LLC');

        $this->assertSame('Daniel Ortega', $row['contact_name']);
        $this->assertSame('Owner', $row['contact_role']);
        $this->assertSame('d.ortega@cedarridgeplumbing.com', $row['contact_email_or_phone']);
        $this->assertGreaterThanOrEqual(ContactFinder::CONFIDENCE_THRESHOLD, $row['confidence_score']);
        $this->assertFalse($row['needs_human_review']);
    }

    public function test_low_confidence_enrichment_only_row_requires_review(): void
    {
        $row = $this->rowForCompany('Riverside Print & Sign');

        $this->assertLessThan(ContactFinder::CONFIDENCE_THRESHOLD, $row['confidence_score']);
        $this->assertSame('', $row['contact_email_or_phone']);
        $this->assertTrue($row['needs_human_review']);
    }

    public function test_not_found_row_is_cannot_verify(): void
    {
        $row = $this->rowForCompany('Redwood Cabinetry');

        $this->assertSame(0, $row['confidence_score']);
        $this->assertSame('', $row['contact_name']);
        $this->assertSame('', $row['contact_email_or_phone']);
        $this->assertSame('', $row['source']);
        $this->assertTrue($row['needs_human_review']);
    }

    public function test_below_threshold_returns_empty_contact_and_review_flag(): void
    {
        $row = $this->rowForCompany('Sunbelt Roofing Co');

        $this->assertLessThan(ContactFinder::CONFIDENCE_THRESHOLD, $row['confidence_score']);
        $this->assertSame('', $row['contact_email_or_phone']);
        $this->assertTrue($row['needs_human_review']);
    }

    public function test_listing_and_weak_enrichment_without_registry_requires_review(): void
    {
        $row = $this->rowForCompany('Lakeside Auto Glass');

        $this->assertLessThan(ContactFinder::CONFIDENCE_THRESHOLD, $row['confidence_score']);
        $this->assertSame('', $row['contact_email_or_phone']);
        $this->assertTrue($row['needs_human_review']);
    }

    public function test_two_source_match_is_capped_below_absolute_certainty(): void
    {
        $row = $this->rowForCompany('Bayview Auto Repair');

        $this->assertSame(95, $row['confidence_score']);
        $this->assertFalse($row['needs_human_review']);
    }

    public function test_provenance_source_urls_are_carried_through(): void
    {
        $row = $this->rowForCompany('Cedar Ridge Plumbing LLC');

        $this->assertStringContainsString('registry:mock://registry/ne/cedar-ridge-plumbing', $row['source']);
        $this->assertStringContainsString('listing:mock://listing/cedar-ridge-plumbing', $row['source']);
        $this->assertStringContainsString('enrichment:mock://enrichment/cedar-ridge-plumbing', $row['source']);
    }

    public function test_run_writes_one_output_row_per_input_row(): void
    {
        $root = dirname(__DIR__, 4);
        $outputPath = sys_get_temp_dir().'/contact-finder-results.csv';

        $rows = $this->finder->run(
            $root.'/challenge/data/companies.csv',
            $root.'/challenge/mocks/enrichment_responses.json',
            $outputPath,
        );

        $this->assertCount(30, $rows);
        $this->assertFileExists($outputPath);
        $this->assertSame(31, count(file($outputPath) ?: []));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowForCompany(string $companyName): array
    {
        foreach ($this->results as $result) {
            if ($result['company_name'] === $companyName) {
                return $result;
            }
        }

        $this->fail("No result found for {$companyName}");
    }
}
