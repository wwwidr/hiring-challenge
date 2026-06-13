<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Support;

use App\Modules\ContactFinder\Providers\EnrichmentProvider;
use App\Modules\ContactFinder\Providers\ListingProvider;
use App\Modules\ContactFinder\Providers\RegistryProvider;
use App\Modules\ContactFinder\Services\ConfidenceScorer;
use App\Modules\ContactFinder\Services\ContactResolver;
use App\Modules\ContactFinder\Services\NameMatcher;

/**
 * Wires the three mocked providers + scoring into a ready resolver.
 * Used by the artisan command and by tests so both share one assembly.
 */
final class ContactResolverFactory
{
    /** @param array<string,mixed> $mockData decoded fixture keyed by company_name */
    public static function fromMockData(array $mockData): ContactResolver
    {
        return new ContactResolver(
            providers: [
                new RegistryProvider($mockData),
                new ListingProvider($mockData),
                new EnrichmentProvider($mockData),
            ],
            names: new NameMatcher(),
            scorer: new ConfidenceScorer(),
        );
    }
}
