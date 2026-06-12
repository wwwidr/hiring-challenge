<?php

declare(strict_types=1);

namespace App\Services\ContactFinder\Types;

final readonly class MockRecord
{
    public function __construct(
        public ?RegistryResult $registry = null,
        public ?ListingResult $listing = null,
        public ?EnrichmentResult $enrichment = null,
    ) {}
}
