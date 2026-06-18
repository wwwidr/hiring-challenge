/**
 * Name and phone normalization + fuzzy name matching.
 *
 * Real source data is messy: "Sean Murphy" vs "S. Murphy", "Robert Kowalski" vs
 * "Bob Kowalski", "Dr. Emily Hart", "Jeff (manager)". Exact string equality misses
 * agreements that should raise confidence, so we normalize then compare token-wise
 * (surname + given-name, with initial and nickname handling) and fall back to a
 * Dice-coefficient similarity.
 */

import type { NameMatch, NameEmailMatch } from "../types/index.js";

const TITLE_PATTERN =
  /\b(?:dr|mr|mrs|ms|miss|prof|rev|sir|md|dds|esq|jr|sr|ii|iii|iv)\b/gi;

/** Common nickname <-> formal-name equivalences (canonical = the value). */
const NICKNAMES: Record<string, string> = {
  bob: "robert",
  rob: "robert",
  bobby: "robert",
  bill: "william",
  will: "william",
  billy: "william",
  jim: "james",
  jimmy: "james",
  mike: "michael",
  dave: "david",
  dan: "daniel",
  danny: "daniel",
  tom: "thomas",
  tommy: "thomas",
  jeff: "jeffrey",
  rick: "richard",
  rich: "richard",
  dick: "richard",
  steve: "steven",
  chris: "christopher",
  joe: "joseph",
  tony: "anthony",
  ed: "edward",
  ted: "edward",
  kate: "katherine",
  katie: "katherine",
  liz: "elizabeth",
  beth: "elizabeth",
  peggy: "margaret",
  meg: "margaret",
};

/** Strip titles, parentheticals, punctuation; lowercase; collapse whitespace. */
export function normalizeName(raw: string | null | undefined): string {
  if (!raw) return "";
  return raw
    .toLowerCase()
    .replace(/\([^)]*\)/g, " ") // "jeff (manager)" -> "jeff"
    .replace(TITLE_PATTERN, " ")
    .replace(/[.,]/g, " ")
    .replace(/[^a-z\s'-]/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

/** Reduce a phone to comparable digits (drops US country code). null if empty. */
export function normalizePhone(raw: string | null | undefined): string | null {
  if (!raw) return null;
  let digits = raw.replace(/\D/g, "");
  if (digits.length === 11 && digits.startsWith("1")) digits = digits.slice(1);
  return digits.length > 0 ? digits : null;
}

export function phonesEqual(a: string | null | undefined, b: string | null | undefined): boolean {
  const na = normalizePhone(a);
  const nb = normalizePhone(b);
  return na !== null && nb !== null && na === nb;
}

function canonicalToken(token: string): string {
  return NICKNAMES[token] ?? token;
}

/** Sørensen–Dice coefficient over character bigrams (0..1). */
export function diceCoefficient(a: string, b: string): number {
  const x = a.replace(/\s+/g, "");
  const y = b.replace(/\s+/g, "");
  if (x === y) return 1;
  if (x.length < 2 || y.length < 2) return x === y ? 1 : 0;

  const bigrams = new Map<string, number>();
  for (let i = 0; i < x.length - 1; i++) {
    const bg = x.slice(i, i + 2);
    bigrams.set(bg, (bigrams.get(bg) ?? 0) + 1);
  }
  let intersection = 0;
  for (let i = 0; i < y.length - 1; i++) {
    const bg = y.slice(i, i + 2);
    const count = bigrams.get(bg) ?? 0;
    if (count > 0) {
      bigrams.set(bg, count - 1);
      intersection++;
    }
  }
  return (2 * intersection) / (x.length - 1 + (y.length - 1));
}

/** True when two given-name tokens agree, allowing initials and nicknames. */
function givenNamesAgree(a: string, b: string): boolean {
  if (a === b) return true;
  // Initial match: "s" vs "sean".
  if ((a.length === 1 && b.startsWith(a)) || (b.length === 1 && a.startsWith(b))) return true;
  return canonicalToken(a) === canonicalToken(b);
}

export interface NameMatchThresholds {
  /** Whole-name Dice similarity at/above which a name pair counts as a fuzzy match. */
  fuzzyThreshold: number;
  /** Stricter Dice similarity required for surnames specifically. */
  surnameThreshold: number;
}

/**
 * Classify how well two names agree. When either side is empty the names cannot be
 * compared and this returns "none"; the caller decides what an empty side means.
 */
export function classifyNameMatch(
  a: string | null | undefined,
  b: string | null | undefined,
  thresholds: NameMatchThresholds,
): NameMatch {
  const na = normalizeName(a);
  const nb = normalizeName(b);
  if (!na || !nb) return "none";
  if (na === nb) return "exact";

  const ta = na.split(" ");
  const tb = nb.split(" ");
  const surnameA = ta[ta.length - 1]!;
  const surnameB = tb[tb.length - 1]!;
  const givenA = ta[0]!;
  const givenB = tb[0]!;

  // Surnames must agree (exact or strongly fuzzy) for a person-level match.
  const surnameAgrees =
    surnameA === surnameB || diceCoefficient(surnameA, surnameB) >= thresholds.surnameThreshold;
  if (surnameAgrees && givenNamesAgree(givenA, givenB)) return "fuzzy";

  // Whole-string fallback for odd orderings/spellings.
  if (diceCoefficient(na, nb) >= thresholds.fuzzyThreshold) return "fuzzy";

  return "none";
}

/** Minimum token length we trust for an email substring match (avoids spurious hits). */
const MIN_EMAIL_TOKEN = 3;

/**
 * Look for the person's name inside an email's local-part. This is the fallback
 * relationship signal when there is no second name source to cross-check against:
 * an email like "g.whitfield@..." plausibly belongs to "George Whitfield".
 *
 * Last-name presence is "strong" (last names are distinctive); a first-name-only
 * hit is "weak" (common first names are weaker evidence of identity).
 */
export function classifyNameEmailMatch(
  name: string | null | undefined,
  email: string | null | undefined,
): NameEmailMatch {
  if (!email) return "none";
  const normalized = normalizeName(name);
  if (!normalized) return "none";

  const localPart = email.split("@")[0] ?? "";
  const localAlpha = localPart.toLowerCase().replace(/[^a-z]/g, "");
  if (!localAlpha) return "none";

  const tokens = normalized
    .split(" ")
    .map((t) => t.replace(/[^a-z]/g, ""))
    .filter((t) => t.length >= MIN_EMAIL_TOKEN);
  if (tokens.length === 0) return "none";

  const lastName = tokens[tokens.length - 1]!;
  const firstName = tokens[0]!;

  // With a single token we can't tell a surname from a given name, so any hit is weak.
  if (tokens.length === 1) {
    return localAlpha.includes(lastName) ? "weak" : "none";
  }
  if (localAlpha.includes(lastName)) return "strong";
  if (localAlpha.includes(firstName)) return "weak";
  return "none";
}
