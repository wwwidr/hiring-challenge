<?php

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\Contracts\ContactProviderInterface;
use App\Modules\ContactFinder\ValueObjects\NormalizedCompany;
use App\Modules\ContactFinder\ValueObjects\ProviderResult;

abstract class AbstractMockProvider implements ContactProviderInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $mockData;

    public function __construct(string $mockDataPath)
    {
        $json = file_get_contents($mockDataPath);
        $this->mockData = json_decode($json, true);
    }

    abstract protected function getProviderKey(): string;

    public function lookup(NormalizedCompany $company): ?ProviderResult
    {
        $companyData = $this->mockData[$company->originalName] ?? null;

        if ($companyData === null) {
            return null;
        }

        $providerData = $companyData[$this->getProviderKey()] ?? null;

        if ($providerData === null) {
            return null;
        }

        return $this->buildResult($providerData);
    }

    abstract protected function buildResult(array $data): ProviderResult;
}
