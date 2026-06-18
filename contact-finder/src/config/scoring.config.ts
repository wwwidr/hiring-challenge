/**
 * Single source of truth for the confidence model: every weight, the threshold,
 * and the role taxonomy live here so tuning happens in one place.
 *
 * Model — role-gated and precision-first:
 *   - A contact with no attributable decision-maker role is not a confident
 *     decision-maker contact, however well its phone/email is corroborated. Role
 *     alone therefore tops out below the threshold (44 < 70): corroboration from
 *     other sources must do real work to clear the bar.
 *   - Cross-source agreement raises confidence; a single unverifiable source does not.
 *   - Role points are priority-tiered — the highest-priority persona scores most,
 *     a non-decision-maker role scores nothing.
 */

export interface RoleTier {
  label: string;
  patterns: RegExp[];
  points: number;
}

export interface ScoringConfig {
  /** confidence_score >= threshold may emit a contact; below => needs_human_review. */
  threshold: number;
  /** Hard cap; we always leave 1% headroom (no contact is ever 100% certain). */
  maxScore: number;

  /** Ordered high -> low priority; the scorer takes the FIRST matching tier. */
  roleTiers: RoleTier[];
  /** Points when the registry role matches no tier (e.g. "Registered Agent"). */
  roleNoMatchPoints: number;

  phone: {
    /** listing.phone === enrichment.phone (normalized), names aligned. */
    match: number;
    /** exactly one of listing/enrichment phone populated, names aligned/uncomparable. */
    partial: number;
    none: number;
  };

  email: {
    /** enrichment email present AND phone corroborated across listing+enrichment. */
    corroborated: number;
    /** enrichment email present, no phone corroboration. */
    uncorroborated: number;
    none: number;
  };

  /**
   * Bonus for establishing that the contact belongs to the named decision-maker.
   * Primary signal is registry<->listing name agreement; when there is no listing
   * name to compare, we fall back to finding the name inside the enrichment email.
   */
  relationshipBonus: {
    /** registry name === listing name (two independent sources agree). */
    nameExact: number;
    /** registry ~ listing (initials / nicknames). */
    nameFuzzy: number;
    /** last name found in the enrichment email's local-part (no listing to compare). */
    emailStrong: number;
    /** only the first name found in the email's local-part. */
    emailWeak: number;
    none: number;
  };

  /** Whole-name Dice similarity at/above which a name pair counts as a fuzzy match. */
  fuzzyThreshold: number;
  /** Stricter Dice similarity required for surnames specifically (they must align closely). */
  surnameThreshold: number;

  /**
   * When there is no second name to compare a known name against (absence, not
   * conflict), still grant partial phone credit if a name + a phone exist — so a
   * solid single-source owner record is not unfairly zeroed.
   */
  creditPhoneWhenNameUncomparable: boolean;
}

export const scoringConfig: ScoringConfig = {
  threshold: 70,
  maxScore: 99,

  // Decision-maker priority order; the highest-priority persona scores most.
  roleTiers: [
    {
      label: "accounts_payable",
      patterns: [/accounts?\s*payable/i, /\ba\/?p\b/i],
      points: 44,
    },
    {
      label: "owner_founder",
      patterns: [/\bowner\b/i, /\bfounder\b/i, /\bpresident\b/i, /\bprincipal\b/i, /\bproprietor\b/i],
      points: 40,
    },
    {
      label: "cfo_finance",
      patterns: [/\bcfo\b/i, /chief\s*financial/i, /\bfinance\b/i, /\bcontroller\b/i, /\btreasurer\b/i],
      points: 36,
    },
    {
      // Deliberately narrow: only an explicit "office manager" counts. A bare
      // "Manager" is too generic to treat as a decision-maker, so it stays a no-match.
      label: "office_manager",
      patterns: [/office\s*manager/i, /office\s*mgr/i],
      points: 30,
    },
  ],
  roleNoMatchPoints: 0,

  phone: { match: 30, partial: 15, none: 0 },
  email: { corroborated: 20, uncorroborated: 10, none: 0 },
  // Ordered by evidence strength: two-source name agreement (exact > fuzzy) outranks a
  // single-source name<->email link (last name > first-name-only). The top tier stays at ~9
  // (≈ an uncorroborated email) so it remains a secondary signal and a no-role record still
  // cannot reach the threshold. A strong link lifts an otherwise-65 record a few points clear;
  // a first-name-only hit stays under.
  relationshipBonus: { nameExact: 9, nameFuzzy: 8, emailStrong: 7, emailWeak: 2, none: 0 },

  fuzzyThreshold: 0.6,
  surnameThreshold: 0.8,
  creditPhoneWhenNameUncomparable: true,
};
