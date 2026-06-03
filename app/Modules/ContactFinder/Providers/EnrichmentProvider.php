<?php

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\Data\ProviderSignal;

/**
 * Email/phone enrichment: a candidate channel plus the provider's own
 * self-reported confidence. That confidence is an input signal only, never the
 * final score, and a lone enrichment guess must not stand on its own.
 */
class EnrichmentProvider implements ProviderInterface
{
    public function __construct(private MockDataset $dataset) {}

    public function name(): string
    {
        return 'enrichment';
    }

    public function fetch(string $companyName): ?ProviderSignal
    {
        $data = $this->dataset->for($companyName, 'enrichment');
        if ($data === null) {
            return null;
        }

        $confidence = $data['provider_confidence'] ?? null;

        return new ProviderSignal(
            provider: 'enrichment',
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            providerConfidence: $confidence === null ? null : (int) $confidence,
            sourceUrl: $data['source_url'] ?? null,
        );
    }
}
