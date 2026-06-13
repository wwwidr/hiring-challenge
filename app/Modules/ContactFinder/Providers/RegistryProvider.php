<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\Contracts\ContactProvider;
use App\Modules\ContactFinder\DTOs\ProviderResult;

/**
 * Business-registry lookup (mocked). Strong for legal owner / registered agent,
 * weak on email. Often entirely absent for tiny businesses.
 */
final class RegistryProvider implements ContactProvider
{
    /** @param array<string,mixed> $data decoded fixture, keyed by company_name */
    public function __construct(private readonly array $data)
    {
    }

    public function key(): string
    {
        return 'registry';
    }

    public function lookup(string $companyName): ?ProviderResult
    {
        $row = $this->data[$companyName][$this->key()] ?? null;
        if (! is_array($row)) {
            return null;
        }

        $name = $row['name'] ?? null;
        $role = $row['role'] ?? null;
        if (($name === null || $name === '') && ($role === null || $role === '')) {
            return null;
        }

        return new ProviderResult(
            provider: $this->key(),
            name: $name,
            role: $role,
            sourceUrl: $row['source_url'] ?? null,
        );
    }
}
