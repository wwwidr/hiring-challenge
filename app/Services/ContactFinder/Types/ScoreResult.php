<?php

declare(strict_types=1);

namespace App\Services\ContactFinder\Types;

final readonly class ScoreResult
{
    public function __construct(
        public int $score,
        public bool $needs_human_review,
    ) {}
}
