<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Services\ConfidenceCalculator;
use App\Modules\ContactFinder\ValueObjects\ProviderResult;
use Tests\TestCase;

class ConfidenceCalculatorTest extends TestCase
{
    private ConfidenceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = $this->app->make(ConfidenceCalculator::class);
    }

    public function test_empty_results_returns_zero(): void
    {
        $score = $this->calculator->calculate([], [
            'name' => '',
            'role' => '',
            'email' => '',
            'phone' => '',
        ]);

        $this->assertEquals(0, $score);
    }

    public function test_full_agreement_three_sources_all_fields(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Daniel Ortega', 'Owner'),
            new ProviderResult('listing', 0.50, 'Daniel Ortega', null, null, '+1-402-555-0148'),
            new ProviderResult('enrichment', 0.70, null, null, 'd.ortega@cedarridgeplumbing.com', null, 84),
        ];

        $mergedContact = [
            'name' => 'Daniel Ortega',
            'role' => 'Owner',
            'email' => 'd.ortega@cedarridgeplumbing.com',
            'phone' => '+1-402-555-0148',
        ];

        $score = $this->calculator->calculate($results, $mergedContact);

        // Agreement: 1.0 (two named sources agree), Authority: 0.90, Completeness: 1.0, Recency: 0.5
        // (0.40*1.0 + 0.20*0.90 + 0.25*1.0 + 0.15*0.5) * 100 = (0.40 + 0.18 + 0.25 + 0.075) * 100 = 90.5 → 91
        $this->assertEquals(91, $score);
    }

    public function test_single_enrichment_api_with_generic_email(): void
    {
        $results = [
            new ProviderResult('enrichment', 0.70, null, null, 'info@riversideprint.biz', null, 41),
        ];

        $mergedContact = [
            'name' => '',
            'role' => '',
            'email' => 'info@riversideprint.biz',
            'phone' => '',
        ];

        $score = $this->calculator->calculate($results, $mergedContact);

        // Agreement: 0.0 (no named sources), Authority: 0.70, Completeness: 0/3=0.0 (generic email doesn't count as contact_channel)
        // (0.40*0.0 + 0.20*0.70 + 0.25*0.0 + 0.15*0.5) * 100 = (0 + 0.14 + 0 + 0.075) * 100 = 21.5 → 22
        $this->assertEquals(22, $score);
    }

    public function test_single_registry_source_with_name_and_role_only(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Thomas Reed', 'Registered Agent'),
        ];

        $mergedContact = [
            'name' => 'Thomas Reed',
            'role' => 'Registered Agent',
            'email' => '',
            'phone' => '',
        ];

        $score = $this->calculator->calculate($results, $mergedContact);

        // Agreement: 0.5 (single named source), Authority: 0.90, Completeness: 2/3=0.67, Recency: 0.5
        // (0.40*0.5 + 0.20*0.90 + 0.25*0.67 + 0.15*0.5) * 100 = (0.20 + 0.18 + 0.1675 + 0.075) * 100 = 62.25 → 62
        $this->assertEquals(62, $score);
    }

    public function test_two_sources_agreeing_name_with_phone(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Sean Murphy', 'Owner'),
            new ProviderResult('listing', 0.50, 'S. Murphy', null, null, '+1-508-555-0160'),
        ];

        $mergedContact = [
            'name' => 'Sean Murphy',
            'role' => 'Owner',
            'email' => '',
            'phone' => '+1-508-555-0160',
        ];

        $score = $this->calculator->calculate($results, $mergedContact);

        // "S. Murphy" normalized to "s murphy", "Sean Murphy" to "sean murphy"
        // Initial "s" matches first letter of "sean" → names agree
        // Agreement: 1.0, Authority: 0.90, Completeness: 3/3=1.0, Recency: 0.5
        // (0.40*1.0 + 0.20*0.90 + 0.25*1.0 + 0.15*0.5) * 100 = (0.40 + 0.18 + 0.25 + 0.075) * 100 = 90.5 → 91
        $this->assertEquals(91, $score);
    }

    public function test_conflicting_names_different_people(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Tina Alvarez', 'Manager'),
            new ProviderResult('listing', 0.50, 'Marcus Webb', null, null, '+1-941-555-0146'),
        ];

        $mergedContact = [
            'name' => 'Tina Alvarez',
            'role' => 'Manager',
            'email' => '',
            'phone' => '+1-941-555-0146',
        ];

        $score = $this->calculator->calculate($results, $mergedContact);

        // Agreement: 0.0 (names conflict), Authority: 0.90, Completeness: 3/3=1.0, Recency: 0.5
        // (0.40*0.0 + 0.20*0.90 + 0.25*1.0 + 0.15*0.5) * 100 = (0 + 0.18 + 0.25 + 0.075) * 100 = 50.5 → 51
        $this->assertEquals(51, $score);
    }

    public function test_nickname_matching_bob_vs_robert(): void
    {
        $results = [
            new ProviderResult('registry', 0.90, 'Robert Kowalski', 'Owner'),
            new ProviderResult('listing', 0.50, 'Bob Kowalski', null, null, '+1-412-555-0184'),
            new ProviderResult('enrichment', 0.70, null, null, 'bob@ironcladweld.com', '+1-412-555-0184', 81),
        ];

        $mergedContact = [
            'name' => 'Robert Kowalski',
            'role' => 'Owner',
            'email' => 'bob@ironcladweld.com',
            'phone' => '+1-412-555-0184',
        ];

        $score = $this->calculator->calculate($results, $mergedContact);

        // Agreement: "Robert Kowalski" → "robert kowalski", "Bob Kowalski" → "bob" → "robert" → "robert kowalski"
        // They match! Agreement: 1.0, Authority: 0.90, Completeness: 1.0, Recency: 0.5
        // (0.40*1.0 + 0.20*0.90 + 0.25*1.0 + 0.15*0.5) * 100 = 90.5 → 91
        $this->assertEquals(91, $score);
    }

    public function test_generic_email_reduces_completeness(): void
    {
        $results = [
            new ProviderResult('enrichment', 0.70, null, null, 'sales@anchormarine.co', '+1-207-555-0138', 63),
        ];

        $mergedContact = [
            'name' => '',
            'role' => '',
            'email' => 'sales@anchormarine.co',
            'phone' => '+1-207-555-0138',
        ];

        $score = $this->calculator->calculate($results, $mergedContact);

        // Agreement: 0.0 (no named sources), Authority: 0.70
        // Completeness: generic email + phone → phone counts, so 1/3=0.33
        // Recency: 0.5
        // (0.40*0.0 + 0.20*0.70 + 0.25*0.33 + 0.15*0.5) * 100 = (0 + 0.14 + 0.0825 + 0.075) * 100 = 29.75 → 30
        $this->assertEquals(30, $score);
    }

    public function test_is_generic_email(): void
    {
        $this->assertTrue($this->calculator->isGenericEmail('info@example.com'));
        $this->assertTrue($this->calculator->isGenericEmail('contact@example.com'));
        $this->assertTrue($this->calculator->isGenericEmail('office@example.com'));
        $this->assertTrue($this->calculator->isGenericEmail('sales@example.com'));
        $this->assertTrue($this->calculator->isGenericEmail('hello@example.com'));
        $this->assertTrue($this->calculator->isGenericEmail('support@example.com'));

        $this->assertFalse($this->calculator->isGenericEmail('d.ortega@example.com'));
        $this->assertFalse($this->calculator->isGenericEmail('karen@example.com'));
        $this->assertFalse($this->calculator->isGenericEmail('bob@example.com'));
    }

    public function test_name_normalization_removes_parenthetical(): void
    {
        $normalized = $this->calculator->normalizeNameForComparison('Jeff (manager)');
        $this->assertEquals('jeff', $normalized);
    }

    public function test_name_normalization_removes_dr_title(): void
    {
        $normalized = $this->calculator->normalizeNameForComparison('Dr. Emily Hart');
        $this->assertEquals('emily hart', $normalized);
    }

    public function test_name_normalization_handles_initial(): void
    {
        $normalized = $this->calculator->normalizeNameForComparison('S. Murphy');
        $this->assertEquals('s murphy', $normalized);
    }

    public function test_agreement_with_enrichment_only_email_no_name(): void
    {
        $results = [
            new ProviderResult('enrichment', 0.70, null, null, 'contact@summitpest.io', null, 38),
        ];

        $agreement = $this->calculator->calculateAgreement($results);

        $this->assertEquals(0.0, $agreement);
    }

    public function test_authority_picks_highest_weight(): void
    {
        $results = [
            new ProviderResult('listing', 0.50, 'Dr. Patel'),
            new ProviderResult('enrichment', 0.70, null, null, 'test@example.com', null, 50),
        ];

        $authority = $this->calculator->calculateAuthority($results);

        $this->assertEquals(0.70, $authority);
    }

    public function test_completeness_with_name_role_and_non_generic_email(): void
    {
        $merged = [
            'name' => 'Daniel Ortega',
            'role' => 'Owner',
            'email' => 'd.ortega@cedarridgeplumbing.com',
            'phone' => '',
        ];

        $completeness = $this->calculator->calculateCompleteness($merged);

        $this->assertEquals(1.0, $completeness);
    }

    public function test_completeness_with_phone_only(): void
    {
        $merged = [
            'name' => '',
            'role' => '',
            'email' => '',
            'phone' => '+1-555-0148',
        ];

        $completeness = $this->calculator->calculateCompleteness($merged);

        $this->assertEqualsWithDelta(0.33, $completeness, 0.01);
    }

    public function test_score_never_exceeds_100(): void
    {
        $results = [
            new ProviderResult('registry', 1.0, 'Test Person', 'Owner'),
            new ProviderResult('listing', 1.0, 'Test Person', 'Owner', 'test@example.com', '+1-555-0000'),
        ];

        $mergedContact = [
            'name' => 'Test Person',
            'role' => 'Owner',
            'email' => 'test@example.com',
            'phone' => '+1-555-0000',
        ];

        $score = $this->calculator->calculate($results, $mergedContact);

        $this->assertLessThanOrEqual(100, $score);
    }

    public function test_score_never_below_zero(): void
    {
        $results = [];
        $mergedContact = ['name' => '', 'role' => '', 'email' => '', 'phone' => ''];

        $score = $this->calculator->calculate($results, $mergedContact);

        $this->assertGreaterThanOrEqual(0, $score);
    }
}
