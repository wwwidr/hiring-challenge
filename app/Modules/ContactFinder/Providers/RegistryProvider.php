<?php

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\Data\ProviderSignal;

/**
 * Business-registry lookup: authoritative officer / registered-agent name and
 * role. Often missing for very small businesses.
 */
class RegistryProvider implements ProviderInterface
{
    public function __construct(private MockDataset $dataset) {}

    public function name(): string
    {
        return 'registry';
    }

    public function fetch(string $companyName): ?ProviderSignal
    {
        $data = $this->dataset->for($companyName, 'registry');
        if ($data === null) {
            return null;
        }

        return new ProviderSignal(
            provider: 'registry',
            name: $data['name'] ?? null,
            role: $data['role'] ?? null,
            sourceUrl: $data['source_url'] ?? null,
        );
    }
}
