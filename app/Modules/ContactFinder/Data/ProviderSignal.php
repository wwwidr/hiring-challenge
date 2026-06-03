<?php

namespace App\Modules\ContactFinder\Data;

/**
 * A normalized signal returned by a single provider for one company.
 *
 * Every signal carries the provider name and its source_url so that any value
 * we later emit can be traced back to exactly one provenance record.
 */
class ProviderSignal
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $name = null,
        public readonly ?string $role = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?int $providerConfidence = null,
        public readonly ?string $sourceUrl = null,
    ) {}
}
