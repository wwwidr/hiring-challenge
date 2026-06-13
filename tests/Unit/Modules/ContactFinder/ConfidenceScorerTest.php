<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Services\ConfidenceScorer;
use PHPUnit\Framework\TestCase;

final class ConfidenceScorerTest extends TestCase
{
    private ConfidenceScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new ConfidenceScorer();
    }

    public function test_three_agreeing_sources_score_at_the_ceiling(): void
    {
        // Cedar Ridge / Pioneer shape: registry + listing agree, personal email, vendor-confident.
        $b = $this->scorer->score([
            'has_registry_name' => true,
            'has_listing_name' => true,
            'name_agreement' => true,
            'role' => 'decision_maker',
            'email_corroborates_name' => true,
            'has_personal_email' => true,
            'has_phone' => true,
            'provider_confidence' => 84,
        ]);

        $this->assertSame(90, $b->identity);
        $this->assertSame(45, $b->channel);
        $this->assertSame(100, $b->total, 'clamped from 135');
        $this->assertGreaterThanOrEqual(ConfidenceScorer::THRESHOLD, $b->total);
    }

    public function test_conflicting_sources_are_crushed(): void
    {
        // Coastal Breeze: two sources naming different people.
        $b = $this->scorer->score([
            'has_registry_name' => true,
            'has_listing_name' => true,
            'name_conflict' => true,
            'role' => 'manager',
            'has_phone' => true,
        ]);

        $this->assertSame(5, $b->identity); // 30 + 15 - 45 + 5
        $this->assertSame(10, $b->channel);
        $this->assertSame(15, $b->total);
        $this->assertLessThan(ConfidenceScorer::THRESHOLD, $b->total);
    }

    public function test_single_generic_enrichment_guess_scores_low(): void
    {
        // Riverside: enrichment-only info@ with weak provider confidence.
        $b = $this->scorer->score([
            'has_generic_email' => true,
            'provider_confidence' => 41,
        ]);

        $this->assertSame(0, $b->identity);
        $this->assertSame(10, $b->channel); // 5 generic + 5 band(40-59)
        $this->assertLessThan(ConfidenceScorer::THRESHOLD, $b->total);
    }

    public function test_registered_agent_is_penalised(): void
    {
        // Northgate: registry-only, role is registered agent (not a decision-maker).
        $b = $this->scorer->score([
            'has_registry_name' => true,
            'role' => 'registered_agent',
        ]);

        $this->assertSame(20, $b->identity); // 30 - 10
        $this->assertSame(0, $b->channel);
        $this->assertLessThan(ConfidenceScorer::THRESHOLD, $b->total);
    }

    public function test_corroborated_phone_beats_a_lone_phone(): void
    {
        $lone = $this->scorer->score(['has_phone' => true]);
        $both = $this->scorer->score(['has_phone' => true, 'phone_corroborated' => true]);

        $this->assertSame(ConfidenceScorer::PHONE_PRESENT, $lone->channel);
        $this->assertSame(ConfidenceScorer::PHONE_CORROBORATED, $both->channel);
        $this->assertGreaterThan($lone->channel, $both->channel);
    }

    public function test_vendor_confidence_only_counts_when_a_channel_exists(): void
    {
        // No channel at all → the vendor's self-confidence must not leak in.
        $b = $this->scorer->score([
            'has_registry_name' => true,
            'provider_confidence' => 99,
        ]);

        $this->assertSame(0, $b->channel);
    }
}
