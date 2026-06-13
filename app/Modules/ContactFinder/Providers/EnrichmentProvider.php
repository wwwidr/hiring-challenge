<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\Contracts\ContactProvider;
use App\Modules\ContactFinder\DTOs\ProviderResult;

/**
 * Email/phone enrichment vendor (mocked). Returns a candidate email/phone plus
 * its own self-reported provider_confidence — which we discount, never trust
 * as our final score. Sometimes nothing, sometimes a plausible-but-weak guess.
 */
final class EnrichmentProvider implements ContactProvider
{
    /** @param array<string,mixed> $data decoded fixture, keyed by company_name */
    public function __construct(private readonly array $data)
    {
    }

    public function key(): string
    {
        return 'enrichment';
    }

    public function lookup(string $companyName): ?ProviderResult
    {
        $row = $this->data[$companyName][$this->key()] ?? null;
        if (! is_array($row)) {
            return null;
        }

        $email = $row['email'] ?? null;
        $phone = $row['phone'] ?? null;
        if (($email === null || $email === '') && ($phone === null || $phone === '')) {
            return null;
        }

        $confidence = $row['provider_confidence'] ?? null;

        return new ProviderResult(
            provider: $this->key(),
            email: $email,
            phone: $phone,
            providerConfidence: is_numeric($confidence) ? (int) $confidence : null,
            sourceUrl: $row['source_url'] ?? null,
        );
    }
}
