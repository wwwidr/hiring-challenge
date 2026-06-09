<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ContactFinder\ContactSelector;
use App\Services\ContactFinder\NameMatcher;
use App\Services\ContactFinder\Types\EnrichmentResult;
use App\Services\ContactFinder\Types\ListingResult;
use App\Services\ContactFinder\Types\MockRecord;
use App\Services\ContactFinder\Types\RegistryResult;
use PHPUnit\Framework\TestCase;

final class ContactSelectorTest extends TestCase
{
    private ContactSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new ContactSelector(new NameMatcher());
    }

    public function test_prefers_owner_over_registered_agent(): void
    {
        $record = new MockRecord(
            registry: new RegistryResult(
                name: 'Angela Brooks',
                role: 'Owner',
                source_url: 'mock://registry/mo/greenfield-catering'
            ),
            enrichment: new EnrichmentResult(
                email: 'a.brooks@greenfieldcater.com',
                phone: '+1-417-555-0151',
                provider_confidence: 72,
                source_url: 'mock://enrichment/greenfield-catering'
            )
        );

        $contact = $this->selector->selectBestContact($record);

        $this->assertSame('Angela Brooks', $contact->name);
        $this->assertSame('Owner', $contact->role);
    }

    public function test_selects_email_over_phone_as_preferred_contact_method(): void
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

        $contact = $this->selector->selectBestContact($record);

        $this->assertSame('d.ortega@cedarridgeplumbing.com', $contact->contact_email_or_phone);
    }

    public function test_falls_back_to_phone_when_no_email_is_available(): void
    {
        $record = new MockRecord(
            registry: new RegistryResult(
                name: 'Sean Murphy',
                role: 'Owner',
                source_url: 'mock://registry/ma/harbor-light-electric'
            ),
            listing: new ListingResult(
                name: 'S. Murphy',
                phone: '+1-508-555-0160',
                source_url: 'mock://listing/harbor-light-electric'
            )
        );

        $contact = $this->selector->selectBestContact($record);

        $this->assertSame('+1-508-555-0160', $contact->contact_email_or_phone);
    }

    public function test_does_not_select_a_registered_agent_as_the_contact(): void
    {
        $record = new MockRecord(
            registry: new RegistryResult(
                name: 'Thomas Reed',
                role: 'Registered Agent',
                source_url: 'mock://registry/oh/northgate-hvac'
            )
        );

        $contact = $this->selector->selectBestContact($record);

        $this->assertNull($contact->name);
    }

    public function test_returns_null_contact_when_only_a_generic_email_exists(): void
    {
        $record = new MockRecord(
            enrichment: new EnrichmentResult(
                email: 'info@hometownhardware.com',
                phone: null,
                provider_confidence: 44,
                source_url: 'mock://enrichment/hometown-hardware'
            )
        );

        $contact = $this->selector->selectBestContact($record);

        $this->assertNull($contact->name);
    }

    public function test_collects_all_contributing_source_urls_for_provenance(): void
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

        $contact = $this->selector->selectBestContact($record);

        $this->assertContains('mock://registry/id/pioneer-landscaping', $contact->sources);
        $this->assertContains('mock://enrichment/pioneer-landscaping', $contact->sources);
    }
}
