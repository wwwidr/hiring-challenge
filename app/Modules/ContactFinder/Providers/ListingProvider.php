<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\Contracts\ContactProvider;
use App\Modules\ContactFinder\DTOs\ProviderResult;

/**
 * Web/maps business listing (mocked). Good for a business phone, sometimes a
 * role-less or informal name ("Jeff (manager)"), sometimes no name at all.
 */
final class ListingProvider implements ContactProvider
{
    /** @param array<string,mixed> $data decoded fixture, keyed by company_name */
    public function __construct(private readonly array $data)
    {
    }

    public function key(): string
    {
        return 'listing';
    }

    public function lookup(string $companyName): ?ProviderResult
    {
        $row = $this->data[$companyName][$this->key()] ?? null;
        if (! is_array($row)) {
            return null;
        }

        $name = $row['name'] ?? null;
        $phone = $row['phone'] ?? null;
        if (($name === null || $name === '') && ($phone === null || $phone === '')) {
            return null;
        }

        return new ProviderResult(
            provider: $this->key(),
            name: $name,
            phone: $phone,
            sourceUrl: $row['source_url'] ?? null,
        );
    }
}
