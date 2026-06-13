<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Services\ConfidenceScorer;
use App\Modules\ContactFinder\Services\ContactResolver;
use App\Modules\ContactFinder\Support\ContactResolverFactory;
use App\Modules\ContactFinder\Support\MockDataLoader;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end resolution against the actual challenge fixture, asserting the
 * deliberately-constructed cases land on the right side of the line.
 */
final class ContactResolverTest extends TestCase
{
    private ContactResolver $resolver;

    protected function setUp(): void
    {
        $fixture = dirname(__DIR__, 4).'/challenge/mocks/enrichment_responses.json';
        $this->resolver = ContactResolverFactory::fromMockData(MockDataLoader::load($fixture));
    }

    public function test_three_agreeing_sources_emit_a_personal_email(): void
    {
        $c = $this->resolver->resolve('Cedar Ridge Plumbing LLC', '');

        $this->assertFalse($c->needsHumanReview);
        $this->assertGreaterThanOrEqual(ConfidenceScorer::THRESHOLD, $c->confidenceScore);
        $this->assertSame('Daniel Ortega', $c->contactName);
        $this->assertSame('d.ortega@cedarridgeplumbing.com', $c->contactEmailOrPhone);
        $this->assertSame('verified', $c->reason);
        $this->assertSame(['registry', 'listing', 'enrichment'], $c->sources);
        $this->assertNotEmpty($c->sourceUrls);
    }

    public function test_name_variant_still_agrees(): void
    {
        // Ironclad: registry "Robert Kowalski" vs listing "Bob Kowalski".
        $c = $this->resolver->resolve('Ironclad Welding Shop', '');

        $this->assertFalse($c->needsHumanReview);
        $this->assertArrayHasKey('name_agreement', $c->breakdown->factors);
    }

    public function test_two_sources_naming_different_people_go_to_review(): void
    {
        // Coastal Breeze: registry "Tina Alvarez" vs listing "Marcus Webb".
        $c = $this->resolver->resolve('Coastal Breeze Pool Service', '');

        $this->assertTrue($c->needsHumanReview);
        $this->assertSame('conflicting_sources', $c->reason);
        $this->assertSame('', $c->contactEmailOrPhone, 'never emit a guessed channel on conflict');
    }

    public function test_registered_agent_without_channel_goes_to_review(): void
    {
        // Northgate: registry-only, role "Registered Agent", no email/phone.
        $c = $this->resolver->resolve('Northgate HVAC Services', '');

        $this->assertTrue($c->needsHumanReview);
        $this->assertSame('role_mismatch_no_channel', $c->reason);
        $this->assertSame('', $c->contactEmailOrPhone);
    }

    public function test_single_generic_guess_goes_to_review(): void
    {
        // Riverside: enrichment-only info@ at confidence 41.
        $c = $this->resolver->resolve('Riverside Print & Sign', '');

        $this->assertTrue($c->needsHumanReview);
        $this->assertSame('generic_email_only', $c->reason);
        $this->assertLessThan(ConfidenceScorer::THRESHOLD, $c->confidenceScore);
    }

    public function test_phone_only_owner_with_agreement_emits_the_phone(): void
    {
        // Harbor Light: registry + listing agree on owner, no email — emit the phone.
        $c = $this->resolver->resolve('Harbor Light Electric', '');

        $this->assertFalse($c->needsHumanReview);
        $this->assertSame('+1-508-555-0160', $c->contactEmailOrPhone);
    }

    public function test_company_absent_from_every_source_is_cannot_verify(): void
    {
        // Redwood Cabinetry is in the CSV but in none of the mock providers.
        $c = $this->resolver->resolve('Redwood Cabinetry', '509 Timber Ct, Eugene, OR 97401');

        $this->assertTrue($c->needsHumanReview);
        $this->assertSame('no_source', $c->reason);
        $this->assertSame(0, $c->confidenceScore);
        $this->assertSame('', $c->contactName);
        $this->assertSame([], $c->sources);
    }
}
