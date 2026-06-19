<?php

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\ValueObjects\ProviderResult;

class MockEnrichmentProvider extends AbstractMockProvider
{
    public function __construct(string $mockDataPath)
    {
        parent::__construct($mockDataPath);
    }

    public function getName(): string
    {
        return 'enrichment';
    }

    public function getAuthorityWeight(): float
    {
        return config('enrichment.provider_authority_weights.enrichment', 0.70);
    }

    protected function getProviderKey(): string
    {
        return 'enrichment';
    }

    protected function buildResult(array $data): ProviderResult
    {
        return new ProviderResult(
            providerName: $this->getName(),
            authorityWeight: $this->getAuthorityWeight(),
            name: null,
            role: null,
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            providerConfidence: $data['provider_confidence'] ?? null,
            sourceUrl: $data['source_url'] ?? null,
        );
    }
}
