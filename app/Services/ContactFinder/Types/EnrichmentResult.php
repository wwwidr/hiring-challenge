<?php

declare(strict_types=1);

namespace App\Services\ContactFinder\Types;

final readonly class EnrichmentResult
{
    public function __construct(
        public ?string $email,
        public ?string $phone,
        public int $provider_confidence,
        public string $source_url,
    ) {}
}
