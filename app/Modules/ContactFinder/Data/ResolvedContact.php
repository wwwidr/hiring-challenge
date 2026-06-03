<?php

namespace App\Modules\ContactFinder\Data;

/**
 * The cross-referenced view of one company after entity resolution, before
 * scoring. Holds the agreed/conflicting identity, the best reachable channel,
 * and the provenance needed by the scorer and the output.
 */
class ResolvedContact
{
    /**
     * @param  string[]  $nameSources  providers that contributed the chosen name
     * @param  string[]  $phoneSources  providers that reported the chosen phone
     * @param  string[]  $sources  every provider that returned any data
     * @param  array<string,string>  $sourceUrls  provider => source_url
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $role,
        public readonly array $nameSources,
        public readonly bool $nameAgreement,
        public readonly bool $conflict,
        public readonly ?string $conflictName,
        public readonly bool $registryHasName,
        public readonly bool $listingHasName,
        public readonly bool $listingNamePartial,
        public readonly ?string $email,
        public readonly bool $emailGeneric,
        public readonly bool $emailNameMatch,
        public readonly ?string $emailSource,
        public readonly ?string $phone,
        public readonly array $phoneSources,
        public readonly bool $phoneCorroborated,
        public readonly ?int $providerConfidence,
        public readonly bool $enrichmentCorroborated,
        public readonly array $sources,
        public readonly array $sourceUrls,
    ) {}

    public function hasAnySignal(): bool
    {
        return $this->sources !== [];
    }

    public function hasNamedContact(): bool
    {
        return $this->name !== null;
    }
}
