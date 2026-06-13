<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Services;

use App\Modules\ContactFinder\Contracts\ContactProvider;
use App\Modules\ContactFinder\DTOs\ProviderResult;
use App\Modules\ContactFinder\DTOs\ResolvedContact;
use App\Modules\ContactFinder\DTOs\ScoreBreakdown;

/**
 * Cross-references the independent providers for one company, scores the result,
 * and applies the hard rules that protect against false positives:
 *   - conflicting sources (different people) -> always human review
 *   - no reachable channel -> always human review
 *   - no source at all -> always human review
 *   - below the confidence threshold -> human review, channel blanked
 *
 * Never fabricates: every emitted value traces to a provider source_url.
 */
final class ContactResolver
{
    /** @param list<ContactProvider> $providers */
    public function __construct(
        private readonly array $providers,
        private readonly NameMatcher $names,
        private readonly ConfidenceScorer $scorer,
    ) {
    }

    public function resolve(string $companyName, string $mailingAddress): ResolvedContact
    {
        /** @var array<string,ProviderResult> $results */
        $results = [];
        foreach ($this->providers as $provider) {
            $result = $provider->lookup($companyName);
            if ($result !== null) {
                $results[$provider->key()] = $result;
            }
        }

        if ($results === []) {
            return $this->noSource($companyName, $mailingAddress);
        }

        $registry = $results['registry'] ?? null;
        $listing = $results['listing'] ?? null;
        $enrichment = $results['enrichment'] ?? null;

        // --- identity ---
        $name = $this->resolveName($registry, $listing);
        $roleLabel = $registry?->role ?? '';
        $roleCategory = $this->roleCategory($roleLabel);

        $nameAgreement = false;
        $nameConflict = false;
        if ($registry?->hasName() && $listing?->hasName()) {
            if ($this->names->samePerson($registry->name, $listing->name)) {
                $nameAgreement = true;
            } else {
                $nameConflict = true;
            }
        }

        // --- channel ---
        $email = $enrichment?->hasEmail() ? $enrichment->email : null;
        $personalEmail = $email !== null && $name !== '' && $this->names->emailMatchesName($email, $name);
        $genericEmail = $email !== null && ! $personalEmail;

        [$phone, $phoneCorroborated] = $this->resolvePhone($listing, $enrichment);
        $hasPhone = $phone !== null;
        $hasChannel = $email !== null || $hasPhone;

        $breakdown = $this->scorer->score([
            'has_registry_name' => (bool) $registry?->hasName(),
            'has_listing_name' => (bool) $listing?->hasName(),
            'name_agreement' => $nameAgreement,
            'name_conflict' => $nameConflict,
            'role' => $roleCategory,
            'email_corroborates_name' => $personalEmail,
            'has_personal_email' => $personalEmail,
            'has_generic_email' => $genericEmail,
            'has_phone' => $hasPhone,
            'phone_corroborated' => $phoneCorroborated,
            'provider_confidence' => $enrichment?->providerConfidence,
        ]);

        $needsReview = $nameConflict
            || ! $hasChannel
            || $breakdown->total < ConfidenceScorer::THRESHOLD;

        $reason = $this->resolveReason(
            sourceCount: count($results),
            nameConflict: $nameConflict,
            hasChannel: $hasChannel,
            below: $breakdown->total < ConfidenceScorer::THRESHOLD,
            personalEmail: $personalEmail,
            genericEmail: $genericEmail,
            hasPhone: $hasPhone,
            roleCategory: $roleCategory,
        );

        // Pick the channel we'd actually use; blank it whenever we hand off to a human.
        $channelValue = '';
        if (! $needsReview) {
            $channelValue = $personalEmail ? (string) $email : ($phone ?? (string) $email);
        }

        return new ResolvedContact(
            companyName: $companyName,
            mailingAddress: $mailingAddress,
            contactName: $name,
            contactRole: $roleLabel,
            contactEmailOrPhone: $channelValue,
            confidenceScore: $breakdown->total,
            sources: array_keys($results),
            sourceUrls: $this->sourceUrls($results),
            needsHumanReview: $needsReview,
            reason: $reason,
            breakdown: $breakdown,
        );
    }

    private function resolveName(?ProviderResult $registry, ?ProviderResult $listing): string
    {
        if ($registry?->hasName()) {
            return trim($registry->name);
        }
        if ($listing?->hasName()) {
            return trim($listing->name);
        }

        return '';
    }

    /**
     * @return array{0: ?string, 1: bool}  [chosen phone, corroborated across sources]
     */
    private function resolvePhone(?ProviderResult $listing, ?ProviderResult $enrichment): array
    {
        $listingPhone = $listing?->hasPhone() ? $listing->phone : null;
        $enrichmentPhone = $enrichment?->hasPhone() ? $enrichment->phone : null;

        $corroborated = $listingPhone !== null
            && $enrichmentPhone !== null
            && $this->normalizePhone($listingPhone) === $this->normalizePhone($enrichmentPhone);

        return [$listingPhone ?? $enrichmentPhone, $corroborated];
    }

    private function normalizePhone(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }

    private function roleCategory(string $role): ?string
    {
        $role = strtolower(trim($role));
        if ($role === '') {
            return null;
        }
        if (str_contains($role, 'registered agent')) {
            return 'registered_agent';
        }
        foreach (['owner', 'founder', 'president', 'ceo', 'principal', 'proprietor', 'partner', 'cfo', 'finance', 'accounts payable', 'ap manager'] as $needle) {
            if (str_contains($role, $needle)) {
                return 'decision_maker';
            }
        }
        if (str_contains($role, 'manager')) {
            return 'manager';
        }

        return 'unknown';
    }

    private function resolveReason(
        int $sourceCount,
        bool $nameConflict,
        bool $hasChannel,
        bool $below,
        bool $personalEmail,
        bool $genericEmail,
        bool $hasPhone,
        ?string $roleCategory,
    ): string {
        if ($nameConflict) {
            return 'conflicting_sources';
        }
        if (! $hasChannel) {
            return $roleCategory === 'registered_agent' ? 'role_mismatch_no_channel' : 'no_contact_channel';
        }
        if ($below) {
            if ($genericEmail && ! $personalEmail && ! $hasPhone) {
                return 'generic_email_only';
            }
            if ($sourceCount === 1) {
                return 'single_unverified_source';
            }

            return 'low_confidence';
        }

        return 'verified';
    }

    /** @param array<string,ProviderResult> $results @return list<string> */
    private function sourceUrls(array $results): array
    {
        $urls = [];
        foreach ($results as $result) {
            if ($result->sourceUrl !== null && $result->sourceUrl !== '') {
                $urls[] = $result->sourceUrl;
            }
        }

        return $urls;
    }

    private function noSource(string $companyName, string $mailingAddress): ResolvedContact
    {
        return new ResolvedContact(
            companyName: $companyName,
            mailingAddress: $mailingAddress,
            contactName: '',
            contactRole: '',
            contactEmailOrPhone: '',
            confidenceScore: 0,
            sources: [],
            sourceUrls: [],
            needsHumanReview: true,
            reason: 'no_source',
            breakdown: new ScoreBreakdown(0, 0, 0, []),
        );
    }
}
