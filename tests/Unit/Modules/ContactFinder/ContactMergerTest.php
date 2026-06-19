<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Enums\VerificationStatus;
use App\Modules\ContactFinder\Services\ContactMerger;
use App\Modules\ContactFinder\ValueObjects\ProviderResult;
use Tests\TestCase;

class ContactMergerTest extends TestCase
{
    private ContactMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merger = $this->app->make(ContactMerger::class);
    }

    public function test_merge_with_no_results_returns_not_found(): void
    {
        $contact = $this->merger->merge('Test Company', []);

        $this->assertEquals('Test Company', $contact->companyName);
        $this->assertEquals('', $contact->contactName);
        $this->assertEquals(0, $contact->confidenceScore);
        $this->assertEquals(VerificationStatus::NOT_FOUND, $contact->verificationStatus);
        $this->assertTrue($contact->needsHumanReview);
    }

    public function test_merge_with_null_results_returns_not_found(): void
    {
        $contact = $this->merger->merge('Test Company', [null, null, null]);

        $this->assertEquals(VerificationStatus::NOT_FOUND, $contact->verificationStatus);
        $this->assertTrue($contact->needsHumanReview);
    }

    public function test_merge_cedar_ridge_three_sources(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Daniel Ortega', 'Owner', null, null, null, 'mock://registry/ne/cedar-ridge-plumbing'),
            new ProviderResult('listing', 0.50, 'Daniel Ortega', null, null, '+1-402-555-0148', null, 'mock://listing/cedar-ridge-plumbing'),
            new ProviderResult('enrichment', 0.70, null, null, 'd.ortega@cedarridgeplumbing.com', null, 84, 'mock://enrichment/cedar-ridge-plumbing'),
        ];

        $contact = $this->merger->merge('Cedar Ridge Plumbing LLC', $results);

        $this->assertEquals('Daniel Ortega', $contact->contactName);
        $this->assertEquals('Owner', $contact->contactRole);
        $this->assertEquals('d.ortega@cedarridgeplumbing.com', $contact->contactEmail);
        $this->assertEquals('+1-402-555-0148', $contact->contactPhone);
        $this->assertFalse($contact->needsHumanReview);
        $this->assertEquals(VerificationStatus::VERIFIED, $contact->verificationStatus);
    }

    public function test_merge_nickname_bob_vs_robert(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Robert Kowalski', 'Owner', null, null, null, 'mock://registry/pa/ironclad-welding'),
            new ProviderResult('listing', 0.50, 'Bob Kowalski', null, null, '+1-412-555-0184', null, 'mock://listing/ironclad-welding'),
            new ProviderResult('enrichment', 0.70, null, null, 'bob@ironcladweld.com', '+1-412-555-0184', 81, 'mock://enrichment/ironclad-welding'),
        ];

        $contact = $this->merger->merge('Ironclad Welding Shop', $results);

        $this->assertEquals('Robert Kowalski', $contact->contactName);
        $this->assertEquals(VerificationStatus::VERIFIED, $contact->verificationStatus);
        $this->assertFalse($contact->needsHumanReview);
    }

    public function test_merge_conflicting_names(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Tina Alvarez', 'Manager', null, null, null, 'mock://registry/fl/coastal-breeze-pool'),
            new ProviderResult('listing', 0.50, 'Marcus Webb', null, null, '+1-941-555-0146', null, 'mock://listing/coastal-breeze-pool'),
        ];

        $contact = $this->merger->merge('Coastal Breeze Pool Service', $results);

        $this->assertEquals(VerificationStatus::CONFLICTING, $contact->verificationStatus);
        $this->assertTrue($contact->needsHumanReview);
    }

    public function test_merge_prefers_registry_name_over_listing(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Tina Alvarez', 'Manager'),
            new ProviderResult('listing', 0.50, 'Marcus Webb'),
        ];

        $contact = $this->merger->merge('Test Company', $results);

        $this->assertEquals('Tina Alvarez', $contact->contactName);
    }

    public function test_merge_generic_email_still_used_when_no_alternative(): void
    {
        $results = [
            new ProviderResult('enrichment', 0.70, null, null, 'info@riversideprint.biz', null, 41, 'mock://enrichment/riverside-print-sign'),
        ];

        $contact = $this->merger->merge('Riverside Print & Sign', $results);

        $this->assertTrue($contact->needsHumanReview);
        $this->assertLessThan(70, $contact->confidenceScore);
    }

    public function test_merge_prefers_non_generic_email(): void
    {
        $results = [
            new ProviderResult('enrichment', 0.70, null, null, 'info@example.com', null, 50),
            new ProviderResult('listing', 0.50, 'John Doe', null, 'john@example.com', null),
        ];

        $contact = $this->merger->merge('Test Company', $results);
        $output = $contact->toArray();

        $this->assertEquals('john@example.com', $output['provenance']['email']['value']);
    }

    public function test_merge_picks_best_role_by_priority(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Test Person', 'Registered Agent'),
            new ProviderResult('listing', 0.50, 'Test Person', 'Owner'),
        ];

        $contact = $this->merger->merge('Test Company', $results);

        $this->assertEquals('Owner', $contact->contactRole);
    }

    public function test_merge_cleans_name_with_parenthetical(): void
    {
        $results = [
            new ProviderResult('listing', 0.50, 'Jeff (manager)', null, null, '+1-608-555-0129'),
        ];

        $contact = $this->merger->merge('Lakeside Auto Glass', $results);

        $this->assertEquals('Jeff', $contact->contactName);
    }

    public function test_to_array_output_format(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Daniel Ortega', 'Owner', null, null, null, 'mock://registry/ne/cedar-ridge'),
            new ProviderResult('listing', 0.50, 'Daniel Ortega', null, null, '+1-402-555-0148', null, 'mock://listing/cedar-ridge'),
            new ProviderResult('enrichment', 0.70, null, null, 'd.ortega@test.com', null, 84, 'mock://enrichment/cedar-ridge'),
        ];

        $contact = $this->merger->merge('Cedar Ridge Plumbing LLC', $results);
        $output = $contact->toArray();

        $this->assertArrayHasKey('company_name', $output);
        $this->assertArrayHasKey('contact_name', $output);
        $this->assertArrayHasKey('contact_role', $output);
        $this->assertArrayHasKey('contact_email', $output);
        $this->assertArrayHasKey('contact_phone', $output);
        $this->assertArrayHasKey('confidence_score', $output);
        $this->assertArrayHasKey('source', $output);
        $this->assertArrayHasKey('needs_human_review', $output);
        $this->assertArrayHasKey('verification_status', $output);
        $this->assertArrayHasKey('provenance', $output);
    }

    public function test_provenance_tracks_field_sources(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Daniel Ortega', 'Owner', null, null, null, 'mock://registry/ne/cedar-ridge'),
            new ProviderResult('listing', 0.50, 'Daniel Ortega', null, null, '+1-402-555-0148', null, 'mock://listing/cedar-ridge'),
        ];

        $contact = $this->merger->merge('Cedar Ridge Plumbing LLC', $results);
        $output = $contact->toArray();

        $this->assertArrayHasKey('name', $output['provenance']);
        $this->assertCount(2, $output['provenance']['name']['sources']);
    }

    public function test_explanation_present_on_verified_contact(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Daniel Ortega', 'Owner', null, null, null, 'mock://registry'),
            new ProviderResult('listing', 0.50, 'Daniel Ortega', null, null, '+1-402-555-0148', null, 'mock://listing'),
            new ProviderResult('enrichment', 0.70, null, null, 'd.ortega@test.com', null, 84, 'mock://enrichment'),
        ];

        $contact = $this->merger->merge('Cedar Ridge Plumbing LLC', $results);

        $this->assertNotEmpty($contact->explanation);
        $this->assertStringContainsString('provider(s) queried', $contact->explanation);
        $this->assertStringContainsString('verified', $contact->explanation);
    }

    public function test_explanation_present_on_conflicting_contact(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Tina Alvarez', 'Manager', null, null, null, 'mock://registry'),
            new ProviderResult('listing', 0.50, 'Marcus Webb', null, null, '+1-941-555-0146', null, 'mock://listing'),
        ];

        $contact = $this->merger->merge('Coastal Breeze Pool Service', $results);

        $this->assertStringContainsString('conflict', $contact->explanation);
    }

    public function test_explanation_present_on_not_found_contact(): void
    {
        $contact = $this->merger->merge('Unknown Company', []);

        $this->assertStringContainsString('not found', $contact->explanation);
    }

    public function test_all_roles_collected_from_multiple_providers(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Test Person', 'Registered Agent', null, null, null, 'mock://registry'),
            new ProviderResult('listing', 0.50, 'Test Person', 'Owner', null, null, null, 'mock://listing'),
        ];

        $contact = $this->merger->merge('Test Company', $results);

        $this->assertCount(2, $contact->allRoles);
        $this->assertArrayHasKey('Owner', $contact->allRoles);
        $this->assertArrayHasKey('Registered Agent', $contact->allRoles);
        $this->assertEquals('Owner', $contact->contactRole);
    }

    public function test_to_array_includes_explanation(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Daniel Ortega', 'Owner', null, null, null, 'mock://registry'),
            new ProviderResult('listing', 0.50, 'Daniel Ortega', null, null, '+1-402-555-0148', null, 'mock://listing'),
        ];

        $contact = $this->merger->merge('Test Company', $results);
        $output = $contact->toArray();

        $this->assertArrayHasKey('explanation', $output);
        $this->assertNotEmpty($output['explanation']);
    }

    public function test_to_array_includes_all_roles_when_multiple(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Test Person', 'AP Manager', null, null, null, 'mock://registry'),
            new ProviderResult('listing', 0.50, 'Test Person', 'Owner', null, null, null, 'mock://listing'),
        ];

        $contact = $this->merger->merge('Test Company', $results);
        $output = $contact->toArray();

        $this->assertArrayHasKey('all_roles', $output);
        $this->assertCount(2, $output['all_roles']);
    }
}
