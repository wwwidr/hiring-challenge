<?php

namespace App\Modules\ContactFinder\Scoring;

/**
 * The outcome of scoring: a 0-100 value, whether scoring forces human review
 * (independent of the threshold, e.g. on a conflict), and the per-rule reasons
 * that make the number explainable.
 */
class ScoreResult
{
    /** @param  string[]  $reasons */
    public function __construct(
        public readonly int $score,
        public readonly bool $forceReview,
        public readonly array $reasons,
    ) {}

    public function rationale(): string
    {
        return implode('; ', $this->reasons);
    }
}
