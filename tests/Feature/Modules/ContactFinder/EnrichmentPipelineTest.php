<?php

namespace Tests\Feature\Modules\ContactFinder;

use App\Modules\ContactFinder\Services\EnrichmentPipeline;
use App\Modules\ContactFinder\ValueObjects\ScoredContact;
use Tests\TestCase;

class EnrichmentPipelineTest extends TestCase
{
    private EnrichmentPipeline $pipeline;
    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipeline = $this->app->make(EnrichmentPipeline::class);
        $this->csvPath = base_path('challenge/data/companies.csv');
    }

    public function test_processes_all_companies_from_csv(): void
    {
        $results = $this->pipeline->process($this->csvPath);

        $this->assertCount(30, $results);
    }

    public function test_all_results_are_scored_contacts(): void
    {
        $results = $this->pipeline->process($this->csvPath);

        foreach ($results as $contact) {
            $this->assertInstanceOf(ScoredContact::class, $contact);
        }
    }

    public function test_cedar_ridge_scores_high_confidence(): void
    {
        $results = $this->pipeline->process($this->csvPath);
        $cedarRidge = $this->findByCompanyName($results, 'Cedar Ridge Plumbing LLC');

        $this->assertNotNull($cedarRidge);
        $this->assertGreaterThanOrEqual(80, $cedarRidge->confidenceScore);
        $this->assertEquals('Daniel Ortega', $cedarRidge->contactName);
        $this->assertFalse($cedarRidge->needsHumanReview);
    }

    public function test_pioneer_landscaping_scores_high_confidence(): void
    {
        $results = $this->pipeline->process($this->csvPath);
        $pioneer = $this->findByCompanyName($results, 'Pioneer Landscaping Inc');

        $this->assertNotNull($pioneer);
        $this->assertGreaterThanOrEqual(80, $pioneer->confidenceScore);
        $this->assertEquals('Maria Gomez', $pioneer->contactName);
        $this->assertFalse($pioneer->needsHumanReview);
    }

    public function test_ironclad_welding_matches_bob_robert_nickname(): void
    {
        $results = $this->pipeline->process($this->csvPath);
        $ironclad = $this->findByCompanyName($results, 'Ironclad Welding Shop');

        $this->assertNotNull($ironclad);
        $this->assertGreaterThanOrEqual(80, $ironclad->confidenceScore);
        $this->assertEquals('Robert Kowalski', $ironclad->contactName);
        $this->assertFalse($ironclad->needsHumanReview);
    }

    public function test_brookside_vet_scores_high_confidence(): void
    {
        $results = $this->pipeline->process($this->csvPath);
        $brookside = $this->findByCompanyName($results, 'Brookside Veterinary Clinic');

        $this->assertNotNull($brookside);
        $this->assertGreaterThanOrEqual(80, $brookside->confidenceScore);
        $this->assertFalse($brookside->needsHumanReview);
    }

    public function test_coastal_breeze_has_conflicting_names(): void
    {
        $results = $this->pipeline->process($this->csvPath);
        $coastal = $this->findByCompanyName($results, 'Coastal Breeze Pool Service');

        $this->assertNotNull($coastal);
        $this->assertTrue($coastal->needsHumanReview);
    }

    public function test_riverside_print_generic_email_scores_low(): void
    {
        $results = $this->pipeline->process($this->csvPath);
        $riverside = $this->findByCompanyName($results, 'Riverside Print & Sign');

        $this->assertNotNull($riverside);
        $this->assertLessThan(70, $riverside->confidenceScore);
        $this->assertTrue($riverside->needsHumanReview);
    }

    public function test_not_found_companies_get_human_review(): void
    {
        $results = $this->pipeline->process($this->csvPath);

        $notFoundCompanies = [
            'Redwood Cabinetry',
            'Desert Sky Solar',
            'Cornerstone Masonry',
            'Velvet Thread Tailoring',
            'Frontier Towing & Recovery',
            'Blue Heron Landscaping',
            'Ace Mobile Locksmith',
            'Granite Peak Surveying',
            'Sierra Vista Auto Body',
            'Evergreen Tree Care',
            'Liberty Sign & Awning',
            'Crescent Moon Cafe',
        ];

        foreach ($notFoundCompanies as $companyName) {
            $contact = $this->findByCompanyName($results, $companyName);
            $this->assertNotNull($contact, "Expected to find result for {$companyName}");
            $this->assertTrue($contact->needsHumanReview, "Expected {$companyName} to need human review");
            $this->assertEquals(0, $contact->confidenceScore, "Expected {$companyName} to have score 0");
        }
    }

    public function test_output_format_matches_spec(): void
    {
        $results = $this->pipeline->process($this->csvPath);

        foreach ($results as $contact) {
            $output = $contact->toArray();

            $this->assertArrayHasKey('company_name', $output);
            $this->assertArrayHasKey('contact_name', $output);
            $this->assertArrayHasKey('contact_role', $output);
            $this->assertArrayHasKey('contact_email', $output);
            $this->assertArrayHasKey('contact_phone', $output);
            $this->assertArrayHasKey('confidence_score', $output);
            $this->assertArrayHasKey('source', $output);
            $this->assertArrayHasKey('needs_human_review', $output);
            $this->assertArrayHasKey('provenance', $output);

            $this->assertIsInt($output['confidence_score']);
            $this->assertIsBool($output['needs_human_review']);
            $this->assertIsArray($output['provenance']);
        }
    }

    public function test_below_threshold_still_shows_data_but_needs_review(): void
    {
        $results = $this->pipeline->process($this->csvPath);

        foreach ($results as $contact) {
            if ($contact->confidenceScore < config('enrichment.threshold') && $contact->confidenceScore > 0) {
                $this->assertTrue($contact->needsHumanReview, "Expected {$contact->companyName} to need human review (score: {$contact->confidenceScore})");
            }
        }
    }

    public function test_single_source_above_threshold_is_unverified_with_review(): void
    {
        $results = $this->pipeline->process($this->csvPath);

        $bayview = $this->findByCompanyName($results, 'Bayview Auto Repair');
        $this->assertNotNull($bayview);
        $this->assertGreaterThanOrEqual(70, $bayview->confidenceScore);
        $this->assertTrue($bayview->needsHumanReview, 'Single-source above threshold should need review');
        $this->assertEquals('unverified', $bayview->verificationStatus->value);
        $this->assertNotEmpty($bayview->contactEmail, 'Above-threshold contacts should still have email');
    }

    public function test_regulated_industry_detected_for_healthcare(): void
    {
        $results = $this->pipeline->process($this->csvPath);

        $dental = $this->findByCompanyName($results, 'Magnolia Family Dental');
        $this->assertNotNull($dental);
        $this->assertEquals('healthcare', $dental->regulatedIndustry);

        $vet = $this->findByCompanyName($results, 'Brookside Veterinary Clinic');
        $this->assertNotNull($vet);
        $this->assertEquals('healthcare', $vet->regulatedIndustry);
    }

    public function test_non_regulated_company_has_null_industry(): void
    {
        $results = $this->pipeline->process($this->csvPath);

        $cedarRidge = $this->findByCompanyName($results, 'Cedar Ridge Plumbing LLC');
        $this->assertNotNull($cedarRidge);
        $this->assertNull($cedarRidge->regulatedIndustry);
    }

    public function test_artisan_command_runs_successfully(): void
    {
        $outputPath = sys_get_temp_dir() . '/enrichment_test_output.json';

        $this->artisan('contacts:enrich', [
            'csv' => $this->csvPath,
            '--output' => $outputPath,
        ])->assertSuccessful();

        $this->assertFileExists($outputPath);

        $jsonContent = json_decode(file_get_contents($outputPath), true);
        $this->assertCount(30, $jsonContent);

        unlink($outputPath);
    }

    /**
     * @param ScoredContact[] $results
     */
    private function findByCompanyName(array $results, string $companyName): ?ScoredContact
    {
        foreach ($results as $contact) {
            if ($contact->companyName === $companyName) {
                return $contact;
            }
        }

        return null;
    }
}
