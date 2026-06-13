<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Services;

use App\Modules\ContactFinder\DTOs\ScoreBreakdown;

/**
 * Explainable confidence scoring.
 *
 * Two axes, summed then clamped to 0-100:
 *   - identity: do we know WHO the decision-maker is?
 *   - channel:  can we actually REACH them?
 *
 * Weights are deliberate human judgement (round multiples of 5), NOT fitted to
 * data — we have no ground truth to calibrate against. They are named constants
 * so they stay tunable once a human-review feedback loop produces labels.
 *
 * Conflicts and missing channels are handled as HARD RULES by ContactResolver,
 * not left to arithmetic — see resolveReason()/needsReview there.
 */
final class ConfidenceScorer
{
    public const THRESHOLD = 70;

    // --- identity weights ---
    public const REGISTRY_NAME = 30;
    public const LISTING_NAME = 15;
    public const NAME_AGREEMENT = 20;        // >=2 sources, same person
    public const NAME_CONFLICT_PENALTY = -45; // sources name different people
    public const ROLE_DECISION_MAKER = 15;    // owner / founder / AP / CFO / president
    public const ROLE_MANAGER = 5;            // office/other manager (fallback persona)
    public const ROLE_REGISTERED_AGENT = -10; // explicitly NOT a decision-maker
    public const EMAIL_NAME_CORROBORATION = 10; // email local-part confirms the person

    // --- channel weights ---
    public const PERSONAL_EMAIL = 20;   // email tied to the person
    public const GENERIC_EMAIL = 5;     // info@/office@ — reachable but not a person
    public const PHONE_PRESENT = 10;
    public const PHONE_CORROBORATED = 20; // listing phone == enrichment phone

    /**
     * @param array{
     *   has_registry_name?: bool,
     *   has_listing_name?: bool,
     *   name_agreement?: bool,
     *   name_conflict?: bool,
     *   role?: 'decision_maker'|'manager'|'registered_agent'|'unknown'|null,
     *   email_corroborates_name?: bool,
     *   has_personal_email?: bool,
     *   has_generic_email?: bool,
     *   has_phone?: bool,
     *   phone_corroborated?: bool,
     *   provider_confidence?: int|null,
     * } $signals
     */
    public function score(array $signals): ScoreBreakdown
    {
        $factors = [];

        // ---- identity ----
        $identity = 0;
        if ($signals['has_registry_name'] ?? false) {
            $identity += $factors['registry_name'] = self::REGISTRY_NAME;
        }
        if ($signals['has_listing_name'] ?? false) {
            $identity += $factors['listing_name'] = self::LISTING_NAME;
        }
        if ($signals['name_agreement'] ?? false) {
            $identity += $factors['name_agreement'] = self::NAME_AGREEMENT;
        }
        if ($signals['name_conflict'] ?? false) {
            $identity += $factors['name_conflict'] = self::NAME_CONFLICT_PENALTY;
        }
        $identity += $rolePoints = $this->rolePoints($signals['role'] ?? null);
        if ($rolePoints !== 0) {
            $factors['role'] = $rolePoints;
        }
        if ($signals['email_corroborates_name'] ?? false) {
            $identity += $factors['email_name_corroboration'] = self::EMAIL_NAME_CORROBORATION;
        }

        // ---- channel ----
        $channel = 0;
        if ($signals['has_personal_email'] ?? false) {
            $channel += $factors['personal_email'] = self::PERSONAL_EMAIL;
        } elseif ($signals['has_generic_email'] ?? false) {
            $channel += $factors['generic_email'] = self::GENERIC_EMAIL;
        }
        if ($signals['phone_corroborated'] ?? false) {
            $channel += $factors['phone_corroborated'] = self::PHONE_CORROBORATED;
        } elseif ($signals['has_phone'] ?? false) {
            $channel += $factors['phone_present'] = self::PHONE_PRESENT;
        }
        $hasChannel = ($signals['has_personal_email'] ?? false)
            || ($signals['has_generic_email'] ?? false)
            || ($signals['has_phone'] ?? false);
        if ($hasChannel) {
            $band = $this->providerConfidenceBand($signals['provider_confidence'] ?? null);
            if ($band !== 0) {
                $channel += $factors['provider_confidence'] = $band;
            }
        }

        $total = max(0, min(100, $identity + $channel));

        return new ScoreBreakdown($identity, $channel, $total, $factors);
    }

    private function rolePoints(?string $role): int
    {
        return match ($role) {
            'decision_maker' => self::ROLE_DECISION_MAKER,
            'manager' => self::ROLE_MANAGER,
            'registered_agent' => self::ROLE_REGISTERED_AGENT,
            default => 0,
        };
    }

    /** Vendor self-reported confidence, discounted into coarse human-readable bands. */
    private function providerConfidenceBand(?int $confidence): int
    {
        return match (true) {
            $confidence === null => 0,
            $confidence >= 80 => 15,
            $confidence >= 60 => 10,
            $confidence >= 40 => 5,
            default => 0,
        };
    }
}
