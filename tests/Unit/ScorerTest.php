<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ContactFinder\EmailValidator;
use App\Services\ContactFinder\NameMatcher;
use App\Services\ContactFinder\Scorer;
use App\Services\ContactFinder\Types\EnrichmentResult;
use App\Services\ContactFinder\Types\ListingResult;
use App\Services\ContactFinder\Types\MockRecord;
use App\Services\ContactFinder\Types\RegistryResult;
use PHPUnit\Framework\TestCase;

final class ScorerTest extends TestCase
{
    private Scorer $scorer;
    private EmailValidator $emailValidator;

    protected function setUp(): void
    {
        $this->emailValidator = new EmailValidator();
        $this->scorer = new Scorer(new NameMatcher(), $this->emailValidator);
    }

    public function test_is_generic_email_flags_generic_catch_all_emails(): void
    {
        $this->assertTrue($this->emailValidator->isGeneric('info@riversideprint.biz'));
        $this->assertTrue($this->emailValidator->isGeneric('office@sunbeltroofingaz.com'));
        $this->assertTrue($this->emailValidator->isGeneric('contact@summitpest.io'));
        $this->assertTrue($this->emailValidator->isGeneric('sales@anchormarine.co'));
    }

    public function test_is_generic_email_does_not_flag_personal_emails(): void
    {
        $this->assertFalse($this->emailValidator->isGeneric('d.ortega@cedarridgeplumbing.com'));
        $this->assertFalse($this->emailValidator->isGeneric('karen@bayviewauto.com'));
        $this->assertFalse($this->emailValidator->isGeneric('emily.hart@brooksidevet.com'));
        $this->assertFalse($this->emailValidator->isGeneric('bob@ironcladweld.com'));
    }

    public function test_scores_high_when_all_3_sources_agree_on_a_named_contact(): void
    {
        $record = new MockRecord(
            registry: new RegistryResult(
                name: 'Maria Gomez',
                role: 'President',
                source_url: 'mock://registry/id/pioneer-landscaping'
            ),
            listing: new ListingResult(
                name: 'Maria Gomez',
                phone: '+1-208-555-0175',
                source_url: 'mock://listing/pioneer-landscaping'
            ),
            enrichment: new EnrichmentResult(
                email: 'maria@pioneerlandscaping.com',
                phone: '+1-208-555-0175',
                provider_confidence: 88,
                source_url: 'mock://enrichment/pioneer-landscaping'
            )
        );

        $result = $this->scorer->score($record, 'Maria Gomez');

        $this->assertGreaterThanOrEqual(70, $result->score);
    }

    public function test_scores_low_when_only_enrichment_returns_a_generic_email(): void
    {
        $record = new MockRecord(
            enrichment: new EnrichmentResult(
                email: 'contact@summitpest.io',
                phone: null,
                provider_confidence: 38,
                source_url: 'mock://enrichment/summit-pest-control'
            )
        );

        $result = $this->scorer->score($record, null);

        $this->assertLessThan(70, $result->score);
    }

    public function test_scores_low_when_only_a_registered_agent_is_found_with_no_contact_info(): void
    {
        $record = new MockRecord(
            registry: new RegistryResult(
                name: 'Thomas Reed',
                role: 'Registered Agent',
                source_url: 'mock://registry/oh/northgate-hvac'
            )
        );

        $result = $this->scorer->score($record, 'Thomas Reed');

        $this->assertLessThan(70, $result->score);
    }

    public function test_returns_needs_human_review_when_sources_conflict_on_contact_name(): void
    {
        $record = new MockRecord(
            registry: new RegistryResult(
                name: 'Tina Alvarez',
                role: 'Manager',
                source_url: 'mock://registry/fl/coastal-breeze-pool'
            ),
            listing: new ListingResult(
                name: 'Marcus Webb',
                phone: '+1-941-555-0146',
                source_url: 'mock://listing/coastal-breeze-pool'
            )
        );

        $result = $this->scorer->score($record, 'Tina Alvarez');

        $this->assertTrue($result->needs_human_review);
    }

    public function test_boosts_score_when_fuzzy_name_match_confirms_across_sources(): void
    {
        $record = new MockRecord(
            registry: new RegistryResult(
                name: 'Robert Kowalski',
                role: 'Owner',
                source_url: 'mock://registry/pa/ironclad-welding'
            ),
            listing: new ListingResult(
                name: 'Bob Kowalski',
                phone: '+1-412-555-0184',
                source_url: 'mock://listing/ironclad-welding'
            ),
            enrichment: new EnrichmentResult(
                email: 'bob@ironcladweld.com',
                phone: '+1-412-555-0184',
                provider_confidence: 81,
                source_url: 'mock://enrichment/ironclad-welding'
            )
        );

        $result = $this->scorer->score($record, 'Robert Kowalski');

        $this->assertGreaterThanOrEqual(70, $result->score);
    }

    public function test_caps_score_at_95(): void
    {
        $record = new MockRecord(
            registry: new RegistryResult(
                name: 'Daniel Ortega',
                role: 'Owner',
                source_url: 'mock://registry/ne/cedar-ridge-plumbing'
            ),
            listing: new ListingResult(
                name: 'Daniel Ortega',
                phone: '+1-402-555-0148',
                source_url: 'mock://listing/cedar-ridge-plumbing'
            ),
            enrichment: new EnrichmentResult(
                email: 'd.ortega@cedarridgeplumbing.com',
                phone: null,
                provider_confidence: 84,
                source_url: 'mock://enrichment/cedar-ridge-plumbing'
            )
        );

        $result = $this->scorer->score($record, 'Daniel Ortega');

        $this->assertLessThanOrEqual(95, $result->score);
    }

    public function test_returns_confidence_0_and_needs_human_review_when_record_is_null(): void
    {
        $result = $this->scorer->score(null, null);

        $this->assertSame(0, $result->score);
        $this->assertTrue($result->needs_human_review);
    }
}
