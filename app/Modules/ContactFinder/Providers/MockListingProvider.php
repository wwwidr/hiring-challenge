<?php

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\ValueObjects\ProviderResult;

class MockListingProvider extends AbstractMockProvider
{
    public function __construct(string $mockDataPath)
    {
        parent::__construct($mockDataPath);
    }

    public function getName(): string
    {
        return 'listing';
    }

    public function getAuthorityWeight(): float
    {
        return config('enrichment.provider_authority_weights.listing', 0.50);
    }

    protected function getProviderKey(): string
    {
        return 'listing';
    }

    protected function buildResult(array $data): ProviderResult
    {
        return new ProviderResult(
            providerName: $this->getName(),
            authorityWeight: $this->getAuthorityWeight(),
            name: $data['name'] ?? null,
            role: null,
            email: null,
            phone: $data['phone'] ?? null,
            providerConfidence: null,
            sourceUrl: $data['source_url'] ?? null,
        );
    }
}
