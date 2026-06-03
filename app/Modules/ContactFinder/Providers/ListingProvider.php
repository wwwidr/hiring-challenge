<?php

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\Data\ProviderSignal;

/**
 * Web / maps business listing: usually a main phone, sometimes an owner or
 * manager name (which may be partial or role-less).
 */
class ListingProvider implements ProviderInterface
{
    public function __construct(private MockDataset $dataset) {}

    public function name(): string
    {
        return 'listing';
    }

    public function fetch(string $companyName): ?ProviderSignal
    {
        $data = $this->dataset->for($companyName, 'listing');
        if ($data === null) {
            return null;
        }

        return new ProviderSignal(
            provider: 'listing',
            name: $data['name'] ?? null,
            phone: $data['phone'] ?? null,
            sourceUrl: $data['source_url'] ?? null,
        );
    }
}
