<?php

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\ValueObjects\ProviderResult;

class MockRegistryProvider extends AbstractMockProvider
{
    public function __construct(string $mockDataPath)
    {
        parent::__construct($mockDataPath);
    }

    public function getName(): string
    {
        return 'registry';
    }

    public function getAuthorityWeight(): float
    {
        return config('enrichment.provider_authority_weights.registry', 0.90);
    }

    protected function getProviderKey(): string
    {
        return 'registry';
    }

    protected function buildResult(array $data): ProviderResult
    {
        return new ProviderResult(
            providerName: $this->getName(),
            authorityWeight: $this->getAuthorityWeight(),
            name: $data['name'] ?? null,
            role: $data['role'] ?? null,
            email: null,
            phone: null,
            providerConfidence: null,
            sourceUrl: $data['source_url'] ?? null,
        );
    }
}
