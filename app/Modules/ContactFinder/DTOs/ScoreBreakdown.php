<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\DTOs;

/**
 * The explainable result of scoring one company.
 *
 * We keep identity (do we know WHO the decision-maker is?) and channel (can we
 * actually REACH them?) as separate sub-scores. The emitted confidence_score is
 * their clamped sum, but the split makes the `reason` sharp.
 */
final readonly class ScoreBreakdown
{
    /**
     * @param array<string,int> $factors  label => points contributed (for audit/explainability)
     */
    public function __construct(
        public int $identity,
        public int $channel,
        public int $total,
        public array $factors,
    ) {
    }
}
