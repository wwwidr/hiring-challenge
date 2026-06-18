/**
 * Pure confidence scoring. Consumes a MergedCompany's pre-classified signals and the
 * ScoringConfig; returns an explainable ScoreBreakdown. No I/O, no normalization here.
 *
 * Model (role-gated, precision-first):
 *   total = role(priority-tiered) + phone(agreement) + email(corroboration) + nameBonus
 * capped at config.maxScore. A no-role contact cannot clear the threshold, however well
 * its phone/email is corroborated — that is a deliberate "needs human review", not a miss.
 */

import type { MergedCompany, ScoreBreakdown } from "../types/index.js";
import type { ScoringConfig } from "../config/scoring.config.js";

interface RoleScore {
  points: number;
  tier: string | null;
}

export function scoreRole(merged: MergedCompany, cfg: ScoringConfig): RoleScore {
  const role = merged.chosenRole.trim();
  if (!role) return { points: 0, tier: null };
  for (const tier of cfg.roleTiers) {
    if (tier.patterns.some((p) => p.test(role))) {
      return { points: tier.points, tier: tier.label };
    }
  }
  return { points: cfg.roleNoMatchPoints, tier: null };
}

/** Do the names agree well enough to attribute a phone to the named person? */
function nameAlignedForPhone(merged: MergedCompany, cfg: ScoringConfig): "aligned" | "partial" | "blocked" {
  if (merged.nameMatch === "exact" || merged.nameMatch === "fuzzy") return "aligned";

  // nameMatch === "none" has two causes:
  //   - conflict: two names are present but disagree -> block phone credit entirely.
  //   - absence: only one side has a name, so there is nothing to cross-check. We still
  //     grant partial credit IF we have a name to attribute the phone to; a fully
  //     anonymous record (no name from any source) earns nothing.
  const haveNameButNothingToCompare = merged.nameUncomparable && merged.chosenName.trim() !== "";
  if (haveNameButNothingToCompare && cfg.creditPhoneWhenNameUncomparable) return "partial";

  return "blocked";
}

export function scorePhone(merged: MergedCompany, cfg: ScoringConfig): number {
  const alignment = nameAlignedForPhone(merged, cfg);
  if (alignment === "blocked") return cfg.phone.none;

  switch (merged.phoneAgreement) {
    case "match":
      // Two independent sources agree on the number. Full credit only when we can
      // also attribute it to the named person; otherwise it is a corroborated but
      // anonymous channel -> partial.
      return alignment === "aligned" ? cfg.phone.match : cfg.phone.partial;
    case "one":
      return cfg.phone.partial;
    case "conflict":
    case "none":
    default:
      return cfg.phone.none;
  }
}

export function scoreEmail(merged: MergedCompany, cfg: ScoringConfig): number {
  const email = merged.enrichment?.email;
  if (!email) return cfg.email.none;
  // Corroborated when a phone agrees across listing+enrichment (independent signal
  // that the enrichment record is about a real, consistent contact).
  return merged.phoneAgreement === "match" ? cfg.email.corroborated : cfg.email.uncorroborated;
}

interface RelationshipScore {
  points: number;
  reason: string | null;
}

/**
 * Credit for tying the contact to the named decision-maker. Prefer registry<->listing
 * name agreement; when that is unavailable, fall back to finding the name in the email.
 */
export function scoreRelationshipBonus(merged: MergedCompany, cfg: ScoringConfig): RelationshipScore {
  const b = cfg.relationshipBonus;
  if (merged.nameMatch === "exact") return { points: b.nameExact, reason: "name matches across sources (exact)" };
  if (merged.nameMatch === "fuzzy") return { points: b.nameFuzzy, reason: "name matches across sources (fuzzy)" };
  // No listing name to compare -> fall back to the name<->email link.
  if (merged.nameEmailMatch === "strong") return { points: b.emailStrong, reason: "last name found in email" };
  if (merged.nameEmailMatch === "weak") return { points: b.emailWeak, reason: "first name found in email" };
  return { points: b.none, reason: null };
}

export function scoreCompany(merged: MergedCompany, cfg: ScoringConfig): ScoreBreakdown {
  const notes: string[] = [];

  if (!merged.found) {
    return {
      role: 0,
      phone: 0,
      email: 0,
      relationshipBonus: 0,
      total: 0,
      roleTier: null,
      notes: ["No source returned this company — cannot verify."],
    };
  }

  const role = scoreRole(merged, cfg);
  const phone = scorePhone(merged, cfg);
  const email = scoreEmail(merged, cfg);
  const relationship = scoreRelationshipBonus(merged, cfg);

  notes.push(
    role.tier
      ? `Role "${merged.chosenRole}" -> ${role.tier} (+${role.points}).`
      : merged.chosenRole
        ? `Role "${merged.chosenRole}" is not a decision-maker tier (+0).`
        : "No role from registry (+0).",
  );
  notes.push(`Phone agreement "${merged.phoneAgreement}" (+${phone}).`);
  notes.push(email > 0 ? `Email ${email === cfg.email.corroborated ? "corroborated" : "uncorroborated"} (+${email}).` : "No email (+0).");
  if (relationship.reason) notes.push(`Relationship: ${relationship.reason} (+${relationship.points}).`);

  const rawTotal = role.points + phone + email + relationship.points;
  const total = Math.min(rawTotal, cfg.maxScore);
  if (rawTotal > cfg.maxScore) notes.push(`Capped at ${cfg.maxScore}.`);

  return {
    role: role.points,
    phone,
    email,
    relationshipBonus: relationship.points,
    total,
    roleTier: role.tier,
    notes,
  };
}
