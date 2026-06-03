<?php

namespace App\Modules\ContactFinder\Resolution;

use App\Modules\ContactFinder\Data\ProviderSignal;
use App\Modules\ContactFinder\Data\ResolvedContact;
use App\Modules\ContactFinder\Support\EmailNormalizer;
use App\Modules\ContactFinder\Support\NameNormalizer;
use App\Modules\ContactFinder\Support\PhoneNormalizer;

/**
 * Cross-references the per-provider signals for one company into a single
 * resolved view: which person (if any) the sources agree on, whether they
 * conflict, the best reachable channel, and the provenance behind each value.
 */
class EntityResolver
{
    public function __construct(
        private NameNormalizer $names,
        private EmailNormalizer $emails,
        private PhoneNormalizer $phones,
    ) {}

    /** @param  array<string,ProviderSignal>  $signals  keyed by provider name */
    public function resolve(array $signals): ResolvedContact
    {
        $registry = $signals['registry'] ?? null;
        $listing = $signals['listing'] ?? null;
        $enrichment = $signals['enrichment'] ?? null;

        $sources = array_keys($signals);
        $sourceUrls = [];
        foreach ($signals as $provider => $signal) {
            if ($signal->sourceUrl !== null) {
                $sourceUrls[$provider] = $signal->sourceUrl;
            }
        }

        $registryName = ($registry && $this->names->isRealPersonName($registry->name))
            ? $this->names->clean($registry->name)
            : null;
        $listingName = ($listing && $this->names->isRealPersonName($listing->name))
            ? $this->names->clean($listing->name)
            : null;

        $listingPartial = $listingName !== null && $this->names->isPartial($listing->name);

        [$name, $role, $nameSources, $agreement, $conflict, $conflictName] =
            $this->resolveIdentity($registry, $listing, $registryName, $listingName);

        $email = $enrichment ? $this->emails->normalize($enrichment->email) : null;
        $emailGeneric = $email !== null && $this->emails->isGeneric($email);
        $emailSource = $email !== null ? 'enrichment' : null;
        $emailNameMatch = $this->emailMatchesName($email, $emailGeneric, $name);

        [$phone, $phoneSources, $phoneCorroborated] = $this->resolvePhone($listing, $enrichment);

        $enrichmentCorroborated = $enrichment !== null && ($registry !== null || $listing !== null);

        return new ResolvedContact(
            name: $name,
            role: $role,
            nameSources: $nameSources,
            nameAgreement: $agreement,
            conflict: $conflict,
            conflictName: $conflictName,
            registryHasName: $registryName !== null,
            listingHasName: $listingName !== null,
            listingNamePartial: $listingPartial,
            email: $email,
            emailGeneric: $emailGeneric,
            emailNameMatch: $emailNameMatch,
            emailSource: $emailSource,
            phone: $phone,
            phoneSources: $phoneSources,
            phoneCorroborated: $phoneCorroborated,
            providerConfidence: $enrichment->providerConfidence ?? null,
            enrichmentCorroborated: $enrichmentCorroborated,
            sources: $sources,
            sourceUrls: $sourceUrls,
        );
    }

    /**
     * @return array{0:?string,1:?string,2:string[],3:bool,4:bool,5:?string}
     */
    private function resolveIdentity(
        ?ProviderSignal $registry,
        ?ProviderSignal $listing,
        ?string $registryName,
        ?string $listingName,
    ): array {
        // Role: prefer the registry's stated role, else a parenthetical hint
        // from the listing (e.g. "Jeff (manager)").
        $role = $registry->role ?? $this->names->parentheticalRole($listing->name ?? null);

        if ($registryName !== null && $listingName !== null) {
            if ($this->names->matches($registryName, $listingName)) {
                // Same person from two independent sources -> agreement.
                return [$registryName, $role, ['registry', 'listing'], true, false, null];
            }

            // Two different real people -> conflict. Keep the higher-authority
            // (registry) name for the rationale, but this will be capped + flagged.
            return [$registryName, $role, ['registry'], false, true, $listingName];
        }

        if ($registryName !== null) {
            return [$registryName, $role, ['registry'], false, false, null];
        }

        if ($listingName !== null) {
            return [$listingName, $role, ['listing'], false, false, null];
        }

        return [null, $role, [], false, false, null];
    }

    private function emailMatchesName(?string $email, bool $emailGeneric, ?string $name): bool
    {
        if ($email === null || $emailGeneric || $name === null) {
            return false;
        }

        $nameTokens = $this->names->tokens($name);
        $emailTokens = $this->names->tokens($this->emails->localPartAsWords($email));
        foreach ($emailTokens as $token) {
            if (strlen($token) >= 2 && in_array($token, $nameTokens, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:?string,1:string[],2:bool}
     */
    private function resolvePhone(?ProviderSignal $listing, ?ProviderSignal $enrichment): array
    {
        $byNumber = [];
        foreach (['listing' => $listing, 'enrichment' => $enrichment] as $provider => $signal) {
            $normalized = $signal ? $this->phones->normalize($signal->phone) : null;
            if ($normalized !== null) {
                $byNumber[$normalized][] = $provider;
            }
        }

        if ($byNumber === []) {
            return [null, [], false];
        }

        // Prefer the most-corroborated number.
        uasort($byNumber, fn ($a, $b) => count($b) <=> count($a));
        $phone = array_key_first($byNumber);
        $phoneSources = $byNumber[$phone];

        return [$phone, $phoneSources, count(array_unique($phoneSources)) >= 2];
    }
}
