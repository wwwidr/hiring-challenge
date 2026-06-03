<?php

namespace App\Modules\ContactFinder\Scoring;

use App\Modules\ContactFinder\Data\ResolvedContact;
use App\Modules\ContactFinder\Support\RoleClassifier;

/**
 * Transparent, additive confidence model (0-100), tuned for precision and
 * provenance. Every point is attributable to a rule and recorded in the
 * reasons list so the score is fully explainable.
 *
 * Principles:
 *  - Identity comes mostly from the registry; independent cross-source
 *    agreement on the same person is the strongest single boost.
 *  - A provider's self-reported confidence (enrichment) is secondary and only
 *    counts when another source corroborates it; a lone guess stays low.
 *  - Conflicts, single-source rows, and generic-mailbox-only rows are capped
 *    or flagged so we never present an unverifiable contact as confident.
 */
class ConfidenceScorer
{
    private const REGISTRY_NAME = 40;

    private const LISTING_NAME_FULL = 25;

    private const LISTING_NAME_PARTIAL = 15;

    private const NAME_AGREEMENT = 20;

    private const PERSONAL_EMAIL = 15;

    private const GENERIC_EMAIL = 5;

    private const EMAIL_NAME_MATCH = 8;

    private const PHONE_SINGLE = 5;

    private const PHONE_CORROBORATED = 10;

    private const ENRICHMENT_MAX = 12;

    private const CAP_CONFLICT = 40;

    private const CAP_SINGLE_SOURCE = 60;

    private const CAP_NO_NAMED_CONTACT = 45;

    public function __construct(private RoleClassifier $roles) {}

    public function score(ResolvedContact $contact): ScoreResult
    {
        if (! $contact->hasAnySignal()) {
            return new ScoreResult(0, true, ['no source returned data']);
        }

        $points = 0;
        $reasons = [];

        // --- Identity ---
        if ($contact->registryHasName) {
            $points += self::REGISTRY_NAME;
            $reasons[] = 'registry name (+'.self::REGISTRY_NAME.')';
        }
        if ($contact->listingHasName) {
            $add = $contact->listingNamePartial ? self::LISTING_NAME_PARTIAL : self::LISTING_NAME_FULL;
            $points += $add;
            $reasons[] = 'listing name'.($contact->listingNamePartial ? ' partial' : '').' (+'.$add.')';
        }
        if ($contact->nameAgreement) {
            $points += self::NAME_AGREEMENT;
            $reasons[] = 'cross-source name agreement (+'.self::NAME_AGREEMENT.')';
        }

        // --- Role fit (persona priority) ---
        $rolePoints = $this->roles->points($contact->role);
        if ($rolePoints > 0) {
            $points += $rolePoints;
            $reasons[] = strtolower($this->roles->label($contact->role)).' role (+'.$rolePoints.')';
        }

        // --- Contactability ---
        if ($contact->email !== null) {
            if ($contact->emailGeneric) {
                $points += self::GENERIC_EMAIL;
                $reasons[] = 'generic mailbox (+'.self::GENERIC_EMAIL.')';
            } else {
                $points += self::PERSONAL_EMAIL;
                $reasons[] = 'personal email (+'.self::PERSONAL_EMAIL.')';
            }
        }
        if ($contact->emailNameMatch) {
            $points += self::EMAIL_NAME_MATCH;
            $reasons[] = 'email matches name (+'.self::EMAIL_NAME_MATCH.')';
        }
        if ($contact->phone !== null) {
            if ($contact->phoneCorroborated) {
                $points += self::PHONE_CORROBORATED;
                $reasons[] = 'phone corroborated by 2 sources (+'.self::PHONE_CORROBORATED.')';
            } else {
                $points += self::PHONE_SINGLE;
                $reasons[] = 'phone (+'.self::PHONE_SINGLE.')';
            }
        }

        // --- Provider self-confidence (secondary; only if corroborated) ---
        if ($contact->providerConfidence !== null) {
            if ($contact->enrichmentCorroborated) {
                $add = (int) round($contact->providerConfidence / 100 * self::ENRICHMENT_MAX);
                $add = min($add, self::ENRICHMENT_MAX);
                if ($add > 0) {
                    $points += $add;
                    $reasons[] = 'enrichment confidence '.$contact->providerConfidence.' corroborated (+'.$add.')';
                }
            } else {
                $reasons[] = 'lone enrichment guess (+0)';
            }
        }

        // --- Precision-first caps / flags ---
        $forceReview = false;

        if ($contact->conflict) {
            $points = min($points, self::CAP_CONFLICT);
            $forceReview = true;
            $reasons[] = 'name conflict'.($contact->conflictName ? ' ('.$contact->conflictName.')' : '').' -> cap '.self::CAP_CONFLICT.', review';
        }
        if (count($contact->sources) === 1) {
            $points = min($points, self::CAP_SINGLE_SOURCE);
            $reasons[] = 'single source -> cap '.self::CAP_SINGLE_SOURCE;
        }
        if (! $contact->hasNamedContact()) {
            $points = min($points, self::CAP_NO_NAMED_CONTACT);
            $reasons[] = 'no named decision-maker -> cap '.self::CAP_NO_NAMED_CONTACT;
        }

        $points = max(0, min(100, $points));

        return new ScoreResult($points, $forceReview, $reasons);
    }
}
