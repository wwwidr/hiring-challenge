<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Data\ProviderSignal;
use App\Modules\ContactFinder\Resolution\EntityResolver;
use App\Modules\ContactFinder\Support\EmailNormalizer;
use App\Modules\ContactFinder\Support\NameNormalizer;
use App\Modules\ContactFinder\Support\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class EntityResolverTest extends TestCase
{
    private EntityResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new EntityResolver(new NameNormalizer, new EmailNormalizer, new PhoneNormalizer);
    }

    public function test_agreeing_sources_resolve_with_agreement_flag(): void
    {
        $resolved = $this->resolver->resolve([
            'registry' => new ProviderSignal('registry', name: 'Robert Kowalski', role: 'Owner', sourceUrl: 'mock://r'),
            'listing' => new ProviderSignal('listing', name: 'Bob Kowalski', phone: '+1-412-555-0184', sourceUrl: 'mock://l'),
            'enrichment' => new ProviderSignal('enrichment', email: 'bob@ironcladweld.com', phone: '+1-412-555-0184', providerConfidence: 81, sourceUrl: 'mock://e'),
        ]);

        $this->assertSame('Robert Kowalski', $resolved->name);
        $this->assertTrue($resolved->nameAgreement);
        $this->assertFalse($resolved->conflict);
        $this->assertTrue($resolved->emailNameMatch);
        $this->assertTrue($resolved->phoneCorroborated);
        $this->assertTrue($resolved->enrichmentCorroborated);
    }

    public function test_conflicting_names_flag_conflict(): void
    {
        $resolved = $this->resolver->resolve([
            'registry' => new ProviderSignal('registry', name: 'Tina Alvarez', role: 'Manager', sourceUrl: 'mock://r'),
            'listing' => new ProviderSignal('listing', name: 'Marcus Webb', phone: '+1-941-555-0146', sourceUrl: 'mock://l'),
        ]);

        $this->assertTrue($resolved->conflict);
        $this->assertSame('Marcus Webb', $resolved->conflictName);
        $this->assertSame('Tina Alvarez', $resolved->name); // higher-authority kept for rationale
    }

    public function test_initial_match_counts_as_agreement(): void
    {
        $resolved = $this->resolver->resolve([
            'registry' => new ProviderSignal('registry', name: 'Sean Murphy', role: 'Owner', sourceUrl: 'mock://r'),
            'listing' => new ProviderSignal('listing', name: 'S. Murphy', phone: '+1-508-555-0160', sourceUrl: 'mock://l'),
        ]);

        $this->assertTrue($resolved->nameAgreement);
        $this->assertFalse($resolved->conflict);
        $this->assertSame('+15085550160', $resolved->phone);
    }

    public function test_generic_mailbox_marked_and_no_name(): void
    {
        $resolved = $this->resolver->resolve([
            'enrichment' => new ProviderSignal('enrichment', email: 'info@riversideprint.biz', providerConfidence: 41, sourceUrl: 'mock://e'),
        ]);

        $this->assertNull($resolved->name);
        $this->assertTrue($resolved->emailGeneric);
        $this->assertFalse($resolved->emailNameMatch);
        $this->assertFalse($resolved->enrichmentCorroborated);
    }

    public function test_parenthetical_role_is_captured_from_listing(): void
    {
        $resolved = $this->resolver->resolve([
            'listing' => new ProviderSignal('listing', name: 'Jeff (manager)', phone: '+1-608-555-0129', sourceUrl: 'mock://l'),
            'enrichment' => new ProviderSignal('enrichment', email: 'jeff@lakesideglass.net', providerConfidence: 58, sourceUrl: 'mock://e'),
        ]);

        $this->assertSame('Jeff', $resolved->name);
        $this->assertSame('manager', $resolved->role);
        $this->assertTrue($resolved->listingNamePartial);
        $this->assertTrue($resolved->emailNameMatch);
    }
}
