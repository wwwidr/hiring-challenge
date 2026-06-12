<?php

declare(strict_types=1);

namespace App\Services\ContactFinder;

use App\Services\ContactFinder\Types\MockRecord;
use App\Services\ContactFinder\Types\ScoreResult;

final class Scorer
{
    public function __construct(
        private readonly NameMatcher $nameMatcher,
        private readonly EmailValidator $emailValidator,
    ) {}

    public function score(?MockRecord $record, ?string $selectedName): ScoreResult
    {
        if (!$record) {
            return new ScoreResult(score: 0, needs_human_review: true);
        }

        $tiers_present = [
            $record->registry ? 1 : 0,
            $record->listing ? 1 : 0,
            $record->enrichment ? 1 : 0,
        ];
        $tiers_count = array_sum($tiers_present);

        $score = (int)round(($tiers_count / 3) * 100);

        $registry_name = $record->registry?->name ?? null;
        $listing_name = $record->listing?->name ?? null;

        if ($registry_name && $listing_name && !$this->nameMatcher->matches($registry_name, $listing_name)) {
            $score = min($score, 50);
            return new ScoreResult(score: $score, needs_human_review: true);
        }

        if ($record->enrichment) {
            $pc = $record->enrichment->provider_confidence;
            if ($pc >= 70) {
                $score += 10;
            } elseif ($pc < 50) {
                $score -= 15;
            }
        }

        if (!$selectedName) {
            $score -= 10;
        }

        $has_phone = (bool)($record->listing?->phone || $record->enrichment?->phone);
        $has_personal_email = $record->enrichment?->email
            ? !$this->emailValidator->isGeneric($record->enrichment->email)
            : false;

        if (!$selectedName && !$has_phone && !$has_personal_email) {
            $score -= 20;
        }

        $score = max(0, min(95, $score));

        return new ScoreResult(
            score: $score,
            needs_human_review: $score < 70,
        );
    }
}
