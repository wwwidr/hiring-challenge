/**
 * resolver.js
 *
 * Core resolution logic: takes raw provider responses for one company,
 * cross-references them, picks the best contact, calculates a confidence
 * score, and returns a structured result with full provenance.
 *
 * Design decisions adapted from CLARIFICATIONS.md:
 *  - Confidence threshold = 70  (< 70 → needs_human_review, blank contact)
 *  - Role priority: AP Manager > Owner/Founder > CFO > Office Manager
 *  - Precision > recall: "cannot verify" is a valid, good result
 *  - One good contact per company is enough
 */

// ── Role priority (from CLARIFICATIONS.md) ────────────────────────────
const ROLE_PRIORITY = [
  "ap manager",
  "accounts payable",
  "owner",
  "founder",
  "president",
  "cfo",
  "finance lead",
  "manager",
  "office manager",
  "registered agent", // weakest signal – may not be operational
];

const CONFIDENCE_THRESHOLD = 70;

// ── Helpers ────────────────────────────────────────────────────────────

/**
 * Normalize a name for fuzzy comparison.
 * "Dr. Emily Hart" → "emily hart", "S. Murphy" → "s murphy"
 * "Bob Kowalski" vs "Robert Kowalski" handled by first-initial check.
 */
function normalizeName(raw) {
  if (!raw) return null;
  return raw
    .replace(/^(dr\.?|mr\.?|mrs\.?|ms\.?)\s*/i, "")
    .replace(/\s*\(.*?\)\s*/g, "") // strip "(manager)" parenthetical
    .toLowerCase()
    .trim();
}

/**
 * Check if two names plausibly refer to the same person.
 * Handles: exact match, initial match ("S. Murphy" ↔ "Sean Murphy"),
 *          and common nickname pairs (Bob/Robert).
 */
const NICKNAME_MAP = {
  bob: "robert",
  rob: "robert",
  bill: "william",
  will: "william",
  mike: "michael",
  dan: "daniel",
  jim: "james",
  jeff: "jeffrey",
  tom: "thomas",
  dick: "richard",
  rick: "richard",
  ted: "theodore",
  tony: "anthony",
};

function namesMatch(a, b) {
  if (!a || !b) return false;
  const na = normalizeName(a);
  const nb = normalizeName(b);
  if (!na || !nb) return false;
  if (na === nb) return true;

  const stripDots = (s) => s.replace(/\./g, "");
  const [firstA, ...restA] = na.split(/\s+/).map(stripDots);
  const [firstB, ...restB] = nb.split(/\s+/).map(stripDots);
  const lastA = restA[restA.length - 1];
  const lastB = restB[restB.length - 1];

  // Last names must match (if both present)
  if (lastA && lastB && lastA !== lastB) return false;

  // Initial match: "s" === first letter of "sean"
  if (firstA.length === 1 && firstB.startsWith(firstA)) return true;
  if (firstB.length === 1 && firstA.startsWith(firstB)) return true;

  // Nickname match
  const canonA = NICKNAME_MAP[firstA] || firstA;
  const canonB = NICKNAME_MAP[firstB] || firstB;
  if (canonA === canonB) return true;

  return false;
}

/**
 * Assign a priority score for a role string.
 * Lower = better.  Returns Infinity for unknown roles.
 */
function rolePriority(role) {
  if (!role) return Infinity;
  const lower = role.toLowerCase();
  const idx = ROLE_PRIORITY.findIndex((r) => lower.includes(r));
  return idx === -1 ? Infinity : idx;
}

// ── Main resolver ──────────────────────────────────────────────────────

/**
 * Resolve the best contact for a single company from provider responses.
 *
 * @param {string} companyName
 * @param {{ registry?, listing?, enrichment? }} providers
 * @returns {{
 *   company_name: string,
 *   contact_name: string,
 *   contact_role: string,
 *   contact_email_or_phone: string,
 *   confidence_score: number,
 *   source: string,
 *   needs_human_review: boolean,
 *   provenance: object[]
 * }}
 */
export function resolveContact(companyName, providers) {
  const { registry, listing, enrichment } = providers;

  // -- Collect raw signals ------------------------------------------------
  const names = [];
  const provenance = [];

  if (registry) {
    provenance.push({
      provider: "registry",
      source_url: registry.source_url,
      data: registry,
    });
    if (registry.name) {
      names.push({
        name: registry.name,
        role: registry.role || null,
        from: "registry",
      });
    }
  }

  if (listing) {
    provenance.push({
      provider: "listing",
      source_url: listing.source_url,
      data: listing,
    });
    if (listing.name) {
      names.push({
        name: listing.name,
        role: null, // listings rarely have explicit roles
        from: "listing",
      });
    }
  }

  if (enrichment) {
    provenance.push({
      provider: "enrichment",
      source_url: enrichment.source_url,
      data: enrichment,
    });
  }

  // -- No data at all → cannot verify ------------------------------------
  if (provenance.length === 0) {
    return cannotVerify(companyName, provenance);
  }

  // -- Pick the best name candidate by role priority ----------------------
  // Sort by role priority (AP > Owner > CFO > Manager > Registered Agent)
  names.sort((a, b) => rolePriority(a.role) - rolePriority(b.role));
  const bestNameEntry = names[0] || null;
  const bestName = bestNameEntry?.name ?? null;
  const bestRole = bestNameEntry?.role ?? null;

  // -- Check cross-source agreement on names ------------------------------
  const uniqueNameSources = new Set(names.map((n) => n.from));
  let nameAgreementCount = 0;
  if (bestName && names.length > 1) {
    nameAgreementCount = names.filter((n) => namesMatch(n.name, bestName))
      .length;
  }

  // -- Pick best contact channel (email preferred for records) ------------
  let contactChannel = "";
  const contactSources = [];

  if (enrichment?.email) {
    contactChannel = enrichment.email;
    contactSources.push("enrichment");
  } else if (enrichment?.phone) {
    contactChannel = enrichment.phone;
    contactSources.push("enrichment");
  }

  if (!contactChannel && listing?.phone) {
    contactChannel = listing.phone;
    contactSources.push("listing");
  }

  // Verify phone agreement across sources (boosts confidence)
  const phones = [listing?.phone, enrichment?.phone].filter(Boolean);
  const phoneAgreement = new Set(phones).size === 1 && phones.length > 1;

  // -- Compute confidence score -------------------------------------------
  let score = 0;
  const sourceCount = provenance.length; // 1-3

  // Base: how many providers returned data at all
  score += Math.min(sourceCount * 20, 40); // max 40

  // Name quality
  if (bestName) {
    score += 15; // we have a named contact
    if (bestRole && rolePriority(bestRole) <= 4) {
      score += 10; // role is a meaningful decision-maker
    }
    if (nameAgreementCount >= 2) {
      score += 15; // multiple sources agree on the name
    }
  }

  // Contact channel quality
  if (contactChannel) {
    score += 10;
    if (phoneAgreement) {
      score += 5; // phone numbers match across sources
    }
  }

  // Enrichment provider's own confidence (scaled contribution)
  if (enrichment?.provider_confidence != null) {
    // Provider confidence contributes 0-15 points
    score += Math.round((enrichment.provider_confidence / 100) * 15);
  }

  // Cap at 100
  score = Math.min(score, 100);

  // -- Penalize risky patterns --------------------------------------------
  // Single enrichment-only source with low provider confidence
  if (
    sourceCount === 1 &&
    enrichment &&
    !registry &&
    !listing &&
    enrichment.provider_confidence < 60
  ) {
    score = Math.min(score, 45); // force below threshold
  }

  // We have contact info but no name → risky
  if (!bestName && contactChannel) {
    score = Math.min(score, 55);
  }

  // "Registered Agent" is not reliably operational → penalty
  if (bestRole?.toLowerCase() === "registered agent" && sourceCount === 1) {
    score = Math.min(score, 55);
  }

  // -- Build source attribution string ------------------------------------
  const sourceAttribution = provenance
    .map((p) => `${p.provider} (${p.source_url})`)
    .join(", ");

  // -- Apply threshold ----------------------------------------------------
  if (score < CONFIDENCE_THRESHOLD || (!bestName && !contactChannel)) {
    return cannotVerify(companyName, provenance, score);
  }

  return {
    company_name: companyName,
    contact_name: bestName || "",
    contact_role: bestRole || "",
    contact_email_or_phone: contactChannel,
    confidence_score: score,
    source: sourceAttribution,
    needs_human_review: false,
    provenance,
  };
}

/** Helper: produce a "cannot verify" row */
function cannotVerify(companyName, provenance, score = 0) {
  const sourceAttribution =
    provenance.length > 0
      ? provenance.map((p) => `${p.provider} (${p.source_url})`).join(", ")
      : "none";

  return {
    company_name: companyName,
    contact_name: "",
    contact_role: "",
    contact_email_or_phone: "",
    confidence_score: score,
    source: sourceAttribution,
    needs_human_review: true,
    provenance,
  };
}

// Export helpers for testing
export { normalizeName, namesMatch, rolePriority, CONFIDENCE_THRESHOLD };
