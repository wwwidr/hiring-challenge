<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\ContactFinderService;
use App\Modules\ContactFinder\Providers\EnrichmentProvider;
use App\Modules\ContactFinder\Providers\ListingProvider;
use App\Modules\ContactFinder\Providers\MockDataset;
use App\Modules\ContactFinder\Providers\RegistryProvider;
use App\Modules\ContactFinder\Resolution\EntityResolver;
use App\Modules\ContactFinder\Scoring\ConfidenceScorer;
use App\Modules\ContactFinder\Support\EmailNormalizer;
use App\Modules\ContactFinder\Support\NameNormalizer;
use App\Modules\ContactFinder\Support\PhoneNormalizer;
use App\Modules\ContactFinder\Support\RoleClassifier;
use App\Modules\ContactFinder\SuppressionList;
use PHPUnit\Framework\TestCase;

class ContactFinderServiceTest extends TestCase
{
    private function service(SuppressionList $suppression = new SuppressionList): ContactFinderService
    {
        $dataset = new MockDataset([
            'Agree Co' => [
                'registry' => ['name' => 'Maria Gomez', 'role' => 'President', 'source_url' => 'mock://registry/agree'],
                'listing' => ['name' => 'Maria Gomez', 'phone' => '+1-208-555-0175', 'source_url' => 'mock://listing/agree'],
                'enrichment' => ['email' => 'maria@agree.com', 'phone' => '+1-208-555-0175', 'provider_confidence' => 88, 'source_url' => 'mock://enrichment/agree'],
            ],
            'Weak Co' => [
                'enrichment' => ['email' => 'info@weak.biz', 'phone' => null, 'provider_confidence' => 41, 'source_url' => 'mock://enrichment/weak'],
            ],
            'Conflict Co' => [
                'registry' => ['name' => 'Tina Alvarez', 'role' => 'Manager', 'source_url' => 'mock://registry/conflict'],
                'listing' => ['name' => 'Marcus Webb', 'phone' => '+1-941-555-0146', 'source_url' => 'mock://listing/conflict'],
            ],
        ]);

        $names = new NameNormalizer;
        $emails = new EmailNormalizer;
        $phones = new PhoneNormalizer;
        $roles = new RoleClassifier;

        return new ContactFinderService(
            providers: [
                new RegistryProvider($dataset),
                new ListingProvider($dataset),
                new EnrichmentProvider($dataset),
            ],
            resolver: new EntityResolver($names, $emails, $phones),
            scorer: new ConfidenceScorer($roles),
            roles: $roles,
            suppression: $suppression,
            threshold: 70,
        );
    }

    public function test_agreeing_sources_emit_a_confident_contact(): void
    {
        $result = $this->service()->findForCompany('Agree Co');

        $this->assertFalse($result->needsHumanReview);
        $this->assertSame('Maria Gomez', $result->contactName);
        $this->assertSame('maria@agree.com', $result->contactEmailOrPhone);
        $this->assertGreaterThanOrEqual(70, $result->confidenceScore);
        $this->assertCount(3, $result->sourceUrls);
    }

    public function test_weak_lone_enrichment_needs_review_with_empty_contact(): void
    {
        $result = $this->service()->findForCompany('Weak Co');

        $this->assertTrue($result->needsHumanReview);
        $this->assertSame('', $result->contactEmailOrPhone);
        $this->assertSame('', $result->contactName);
        $this->assertLessThan(70, $result->confidenceScore);
        // Provenance is still carried for the human reviewer.
        $this->assertNotEmpty($result->sourceUrls);
    }

    public function test_conflicting_names_need_review(): void
    {
        $result = $this->service()->findForCompany('Conflict Co');

        $this->assertTrue($result->needsHumanReview);
        $this->assertSame('', $result->contactEmailOrPhone);
        $this->assertLessThanOrEqual(40, $result->confidenceScore);
    }

    public function test_absent_company_is_cannot_verify(): void
    {
        $result = $this->service()->findForCompany('Ghost Co');

        $this->assertTrue($result->needsHumanReview);
        $this->assertSame(0, $result->confidenceScore);
        $this->assertSame([], $result->sources);
        $this->assertStringContainsString('no source returned data', $result->rationale);
    }

    public function test_suppressed_company_is_skipped(): void
    {
        $result = $this->service(new SuppressionList(['Agree Co']))->findForCompany('Agree Co');

        $this->assertFalse($result->needsHumanReview);
        $this->assertSame('', $result->contactEmailOrPhone);
        $this->assertStringContainsString('suppressed', $result->rationale);
    }
}
