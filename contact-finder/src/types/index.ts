/**
 * Shared types for the Contact Finder slice.
 *
 * The flow is: raw fixture shape -> per-company merge (with derived cross-reference
 * signals) -> explainable score breakdown -> final output row (the contract).
 */

// ---- Raw fixture shape (mirrors challenge/mocks/enrichment_responses.json) ----

export interface RegistryRecord {
  name: string | null;
  role: string | null;
  source_url: string;
}

export interface ListingRecord {
  name: string | null;
  phone: string | null;
  source_url: string;
}

export interface EnrichmentRecord {
  email: string | null;
  phone: string | null;
  /** The provider's self-reported confidence — NOT our final score. */
  provider_confidence: number | null;
  source_url: string;
}

/** Value in the fixture map; any provider key may be absent. */
export interface ProviderResponse {
  registry?: RegistryRecord;
  listing?: ListingRecord;
  enrichment?: EnrichmentRecord;
}

/** The whole fixture, keyed by exact company_name. */
export type FixtureMap = Record<string, ProviderResponse>;

export type ProviderName = "registry" | "listing" | "enrichment";

// ---- CSV input ----

export interface CompanyInput {
  company_name: string;
  mailing_address: string;
}

// ---- Merged-per-company intermediate (post cross-reference) ----

/** How well the registry name and listing name agree. */
export type NameMatch = "none" | "fuzzy" | "exact";

/**
 * How well the decision-maker name agrees with the enrichment email's local-part.
 * Used as a fallback link when there is no listing name to cross-check against:
 *   - "strong": the last name appears in the email (last names are identifying)
 *   - "weak":   only the first name appears (common first names are weaker evidence)
 */
export type NameEmailMatch = "none" | "weak" | "strong";

/**
 * Agreement between listing.phone and enrichment.phone:
 * - "match": both populated and equal (normalized)
 * - "one": exactly one of the two is populated
 * - "conflict": both populated but different
 * - "none": neither populated
 */
export type PhoneAgreement = "none" | "one" | "match" | "conflict";

export interface MergedCompany {
  company_name: string;
  registry?: RegistryRecord;
  listing?: ListingRecord;
  enrichment?: EnrichmentRecord;
  /** false when the company key is absent from the fixture (genuine "not found"). */
  found: boolean;
  /** Which providers were actually present, in provenance order. */
  presentProviders: ProviderName[];
  // Derived cross-reference signals, computed once in merge and reused by the scorer:
  nameMatch: NameMatch;
  /** true when there is no listing name to compare against (absence, not conflict). */
  nameUncomparable: boolean;
  /** Fallback link: does the decision-maker name appear in the enrichment email? */
  nameEmailMatch: NameEmailMatch;
  phoneAgreement: PhoneAgreement;
  /** Best available decision-maker name (registry preferred, then listing). "" if unknown. */
  chosenName: string;
  /** Registry role (the only source of role). "" if unknown. */
  chosenRole: string;
}

// ---- Scoring breakdown (explainable) ----

export interface ScoreBreakdown {
  role: number;
  phone: number;
  email: number;
  /** Name-relationship bonus: name<->name agreement, or name<->email as a fallback. */
  relationshipBonus: number;
  /** Sum of the above, capped at config.maxScore. */
  total: number;
  /** Matched role tier label, for explainability (null when no tier matched). */
  roleTier: string | null;
  /** Human-readable reasons for each component. */
  notes: string[];
}

// ---- Provenance ----

export interface SourceRef {
  provider: ProviderName;
  source_url: string;
}

// ---- Final output row (the contract) ----

export type SuppressionLevel = "company" | "contact" | null;

export interface ContactRow {
  company_name: string;
  /** "" when unknown. */
  contact_name: string;
  /** "" when unknown. */
  contact_role: string;
  /** "" when below threshold / suppressed / not found. */
  contact_email_or_phone: string;
  confidence_score: number;
  /** Every provider that contributed to the emitted value, with its mock:// url. */
  source: SourceRef[];
  needs_human_review: boolean;
  /** Why a row was blanked despite (possibly) scoring well. */
  suppressed: SuppressionLevel;
  /** Explainability — handy for the demo, can be stripped before an HTTP response. */
  score_breakdown?: ScoreBreakdown;
}

// ---- Suppression fixture ----

export interface SuppressionList {
  companies: string[];
  emails: string[];
  phones: string[];
}
