/**
 * Cross-reference the (up to) three provider records for one company into a single
 * MergedCompany with derived agreement signals. All the messy comparison lives here
 * so the scorer can stay pure over pre-classified flags.
 *
 * Key distinction the scorer relies on:
 *   - nameMatch "none" + nameUncomparable=false  => the two names genuinely CONFLICT
 *     (e.g. Coastal Breeze: registry "Tina Alvarez" vs listing "Marcus Webb") -> no phone credit.
 *   - nameMatch "none" + nameUncomparable=true   => one side has no name to compare
 *     (e.g. an owner from registry with no listing) -> partial phone credit is allowed.
 */

import type {
  MergedCompany,
  ProviderName,
  ProviderResponse,
  PhoneAgreement,
} from "../types/index.js";
import type { ScoringConfig } from "../config/scoring.config.js";
import { classifyNameEmailMatch, classifyNameMatch, normalizePhone } from "./normalize.js";

function classifyPhoneAgreement(
  listingPhone: string | null | undefined,
  enrichmentPhone: string | null | undefined,
): PhoneAgreement {
  const a = normalizePhone(listingPhone);
  const b = normalizePhone(enrichmentPhone);
  if (a && b) return a === b ? "match" : "conflict";
  if (a || b) return "one";
  return "none";
}

export function mergeCompany(
  companyName: string,
  resp: ProviderResponse | null,
  cfg: ScoringConfig,
): MergedCompany {
  const registry = resp?.registry;
  const listing = resp?.listing;
  const enrichment = resp?.enrichment;

  const presentProviders: ProviderName[] = [];
  if (registry) presentProviders.push("registry");
  if (listing) presentProviders.push("listing");
  if (enrichment) presentProviders.push("enrichment");

  const bothNamesPresent = Boolean(registry?.name && listing?.name);
  const nameMatch = bothNamesPresent
    ? classifyNameMatch(registry!.name, listing!.name, {
        fuzzyThreshold: cfg.fuzzyThreshold,
        surnameThreshold: cfg.surnameThreshold,
      })
    : "none";

  // registry is preferred for the identity-to-email link (it carries the role).
  const nameForEmail = registry?.name ?? listing?.name ?? null;

  const merged: MergedCompany = {
    company_name: companyName,
    found: resp !== null,
    presentProviders,
    nameMatch,
    nameUncomparable: !bothNamesPresent,
    nameEmailMatch: classifyNameEmailMatch(nameForEmail, enrichment?.email),
    phoneAgreement: classifyPhoneAgreement(listing?.phone, enrichment?.phone),
    // registry is preferred (it carries the role); listing is the fallback name source.
    chosenName: registry?.name ?? listing?.name ?? "",
    chosenRole: registry?.role ?? "",
  };
  if (registry) merged.registry = registry;
  if (listing) merged.listing = listing;
  if (enrichment) merged.enrichment = enrichment;
  return merged;
}
