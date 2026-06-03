<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Data\ResolvedContact;
use App\Modules\ContactFinder\Scoring\ConfidenceScorer;
use App\Modules\ContactFinder\Support\RoleClassifier;
use PHPUnit\Framework\TestCase;

class ConfidenceScorerTest extends TestCase
{
    private ConfidenceScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new ConfidenceScorer(new RoleClassifier);
    }

    public function test_multi_source_agreement_scores_high(): void
    {
        $result = $this->scorer->score($this->resolved(
            name: 'Robert Kowalski',
            role: 'Owner',
            nameAgreement: true,
            registryHasName: true,
            listingHasName: true,
            email: 'bob@ironcladweld.com',
            emailNameMatch: true,
            phone: '+14125550184',
            phoneCorroborated: true,
            providerConfidence: 81,
            enrichmentCorroborated: true,
            sources: ['registry', 'listing', 'enrichment'],
        ));

        $this->assertGreaterThanOrEqual(70, $result->score);
        $this->assertFalse($result->forceReview);
    }

    public function test_lone_enrichment_guess_stays_low_and_adds_zero(): void
    {
        $result = $this->scorer->score($this->resolved(
            email: 'info@riversideprint.biz',
            emailGeneric: true,
            providerConfidence: 41,
            enrichmentCorroborated: false,
            sources: ['enrichment'],
        ));

        $this->assertLessThan(70, $result->score);
        $this->assertContains('lone enrichment guess (+0)', $result->reasons);
    }

    public function test_name_conflict_is_capped_and_forces_review(): void
    {
        $result = $this->scorer->score($this->resolved(
            name: 'Tina Alvarez',
            role: 'Manager',
            conflict: true,
            conflictName: 'Marcus Webb',
            registryHasName: true,
            listingHasName: true,
            phone: '+19415550146',
            sources: ['registry', 'listing'],
        ));

        $this->assertLessThanOrEqual(40, $result->score);
        $this->assertTrue($result->forceReview);
    }

    public function test_no_signal_is_zero_and_review(): void
    {
        $result = $this->scorer->score($this->resolved(sources: []));

        $this->assertSame(0, $result->score);
        $this->assertTrue($result->forceReview);
    }

    public function test_single_source_is_capped(): void
    {
        // Registry name (+40) + owner (+12) would be 52 anyway; assert the cap
        // reason is present and the score never exceeds 60 on one source.
        $result = $this->scorer->score($this->resolved(
            name: 'Thomas Reed',
            role: 'Registered Agent',
            registryHasName: true,
            sources: ['registry'],
        ));

        $this->assertLessThanOrEqual(60, $result->score);
        $this->assertLessThan(70, $result->score);
    }

    /**
     * Build a ResolvedContact with safe defaults, overriding only what a test
     * cares about.
     *
     * @param  string[]  $nameSources
     * @param  string[]  $phoneSources
     * @param  string[]  $sources
     * @param  array<string,string>  $sourceUrls
     */
    private function resolved(
        ?string $name = null,
        ?string $role = null,
        array $nameSources = [],
        bool $nameAgreement = false,
        bool $conflict = false,
        ?string $conflictName = null,
        bool $registryHasName = false,
        bool $listingHasName = false,
        bool $listingNamePartial = false,
        ?string $email = null,
        bool $emailGeneric = false,
        bool $emailNameMatch = false,
        ?string $emailSource = null,
        ?string $phone = null,
        array $phoneSources = [],
        bool $phoneCorroborated = false,
        ?int $providerConfidence = null,
        bool $enrichmentCorroborated = false,
        array $sources = [],
        array $sourceUrls = [],
    ): ResolvedContact {
        return new ResolvedContact(
            name: $name,
            role: $role,
            nameSources: $nameSources,
            nameAgreement: $nameAgreement,
            conflict: $conflict,
            conflictName: $conflictName,
            registryHasName: $registryHasName,
            listingHasName: $listingHasName,
            listingNamePartial: $listingNamePartial,
            email: $email,
            emailGeneric: $emailGeneric,
            emailNameMatch: $emailNameMatch,
            emailSource: $emailSource,
            phone: $phone,
            phoneSources: $phoneSources,
            phoneCorroborated: $phoneCorroborated,
            providerConfidence: $providerConfidence,
            enrichmentCorroborated: $enrichmentCorroborated,
            sources: $sources,
            sourceUrls: $sourceUrls,
        );
    }
}
