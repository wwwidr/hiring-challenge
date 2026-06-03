<?php

namespace App\Modules\ContactFinder;

use App\Modules\ContactFinder\Data\ContactResult;
use App\Modules\ContactFinder\Data\ResolvedContact;
use App\Modules\ContactFinder\Providers\EnrichmentProvider;
use App\Modules\ContactFinder\Providers\ListingProvider;
use App\Modules\ContactFinder\Providers\MockDataset;
use App\Modules\ContactFinder\Providers\ProviderInterface;
use App\Modules\ContactFinder\Providers\RegistryProvider;
use App\Modules\ContactFinder\Resolution\EntityResolver;
use App\Modules\ContactFinder\Scoring\ConfidenceScorer;
use App\Modules\ContactFinder\Support\EmailNormalizer;
use App\Modules\ContactFinder\Support\NameNormalizer;
use App\Modules\ContactFinder\Support\PhoneNormalizer;
use App\Modules\ContactFinder\Support\RoleClassifier;

/**
 * Orchestrates the per-company pipeline: fan out to providers, resolve the
 * identity, score it, apply the threshold, and produce an auditable result.
 *
 * Core invariant: we never emit a contact channel we cannot attribute to at
 * least one source_url, and anything below the threshold (or flagged) comes
 * back with an empty contact and needs_human_review = true.
 */
class ContactFinderService
{
    public const DEFAULT_THRESHOLD = 70;

    /** @param  ProviderInterface[]  $providers */
    public function __construct(
        private array $providers,
        private EntityResolver $resolver,
        private ConfidenceScorer $scorer,
        private RoleClassifier $roles,
        private SuppressionList $suppression,
        private int $threshold = self::DEFAULT_THRESHOLD,
    ) {}

    /** Convenience wiring used by the command. */
    public static function fromMockFile(
        string $mocksPath,
        ?SuppressionList $suppression = null,
        int $threshold = self::DEFAULT_THRESHOLD,
    ): self {
        $dataset = MockDataset::fromFile($mocksPath);
        $names = new NameNormalizer;
        $emails = new EmailNormalizer;
        $phones = new PhoneNormalizer;
        $roles = new RoleClassifier;

        return new self(
            providers: [
                new RegistryProvider($dataset),
                new ListingProvider($dataset),
                new EnrichmentProvider($dataset),
            ],
            resolver: new EntityResolver($names, $emails, $phones),
            scorer: new ConfidenceScorer($roles),
            roles: $roles,
            suppression: $suppression ?? new SuppressionList,
            threshold: $threshold,
        );
    }

    /**
     * @param  iterable<string>  $companyNames
     * @return ContactResult[]
     */
    public function run(iterable $companyNames): array
    {
        $results = [];
        foreach ($companyNames as $company) {
            $results[] = $this->findForCompany($company);
        }

        return $results;
    }

    public function findForCompany(string $companyName): ContactResult
    {
        if ($this->suppression->isSuppressed($companyName)) {
            return new ContactResult(
                companyName: $companyName,
                contactName: '',
                contactRole: '',
                contactEmailOrPhone: '',
                confidenceScore: 0,
                sources: [],
                sourceUrls: [],
                needsHumanReview: false,
                rationale: 'suppressed (opt-out / do-not-contact); skipped',
            );
        }

        $signals = [];
        foreach ($this->providers as $provider) {
            $signal = $provider->fetch($companyName);
            if ($signal !== null) {
                $signals[$provider->name()] = $signal;
            }
        }

        $resolved = $this->resolver->resolve($signals);
        $score = $this->scorer->score($resolved);
        $needsReview = $score->forceReview || $score->score < $this->threshold;

        $rationale = $score->rationale().' => score '.$score->score.', '
            .($needsReview ? 'needs human review' : 'emit');

        if ($needsReview) {
            // Below threshold or flagged: empty contact, never fabricated. Keep
            // any tentative finding in the rationale (with provenance) for the
            // human reviewer.
            if ($resolved->name !== null && ! $resolved->conflict) {
                $rationale .= '; tentative: '.$resolved->name
                    .($this->roles->label($resolved->role) !== '' ? ' ('.$this->roles->label($resolved->role).')' : '');
            }
            $channel = $this->bestChannel($resolved);
            if ($channel !== null) {
                $rationale .= '; channel for review: '.$channel;
            }

            return new ContactResult(
                companyName: $companyName,
                contactName: '',
                contactRole: '',
                contactEmailOrPhone: '',
                confidenceScore: $score->score,
                sources: $resolved->sources,
                sourceUrls: array_values($resolved->sourceUrls),
                needsHumanReview: true,
                rationale: $rationale,
            );
        }

        return new ContactResult(
            companyName: $companyName,
            contactName: $resolved->name ?? '',
            contactRole: $this->roles->label($resolved->role),
            contactEmailOrPhone: (string) $this->bestChannel($resolved),
            confidenceScore: $score->score,
            sources: $resolved->sources,
            sourceUrls: array_values($resolved->sourceUrls),
            needsHumanReview: false,
            rationale: $rationale,
        );
    }

    public function threshold(): int
    {
        return $this->threshold;
    }

    /**
     * Channel preference: a personal email beats a corroborated phone beats a
     * single-source phone beats a generic mailbox. Returns null when there is
     * nothing attributable to emit.
     */
    private function bestChannel(ResolvedContact $contact): ?string
    {
        if ($contact->email !== null && ! $contact->emailGeneric) {
            return $contact->email;
        }
        if ($contact->phone !== null) {
            return $contact->phone;
        }
        if ($contact->email !== null) {
            return $contact->email;
        }

        return null;
    }
}
