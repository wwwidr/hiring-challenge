# PLAN.md — Contact Finder

> Written before reading `CLARIFICATIONS.md` or writing any solution code.

---

## Architecture

The system is a linear enrichment pipeline. One row in, one enriched contact record out. Each company is processed independently — a failure on one row doesn't stop the rest.

**Step 1 — Normalize the input**

The CSV gives us a company name and a mailing address. Before we can look anything up, we need to parse both into structured fields:

```
company_legal_name  →  Cedar Ridge Plumbing LLC
company_common_name →  Cedar Ridge Plumbing
entity_type         →  LLC
street_address      →  4821 Maple Ave
city                →  Lincoln
state               →  NE
zip_code            →  68504
```

Keeping the legal name and common name separate matters because they're used for different things. The legal name goes to the Secretary of State lookup. The common name goes to Google Maps, website discovery, and LinkedIn. Stripping "LLC" for the SoS search would lose the ability to match the registered entity.

Entity type also signals what sources are likely to be useful. A registered LLC or Corp almost certainly has a SoS filing. A DBA or no-suffix name like `Crescent Moon Cafe` may not — lean on Google and website first for those.

**Step 2 — Query multiple sources in parallel**

See Sources & Strategy section.

**Step 3 — Aggregate and deduplicate candidates**

Collect all names and titles returned across sources. Deduplicate by fuzzy name match (≥85% similarity = same person). For each unique candidate, track: how many distinct source tiers confirmed them, what role/title was found, and which sources it came from. Every field carries its source attribution meaning nothing is left untagged.

**Step 4 — Score each candidate**

See Quality section.

**Step 5 — Cannot-verify handler**

If no candidate clears the confidence threshold, mark the row with `needs_human_review=true` and null contact fields. Still worth surfacing the top candidate with its score so a human reviewer has something to work from rather than starting from scratch. If it's genuinely a dead end across all sources, output confidence=0.

It may also be worth flagging back to the sending party — if a company can't be found anywhere, that's a signal worth noting (dissolved business, bad address, etc.).

**Step 6 — Write output**

One row per input company: `contact_name`, `contact_role`, `contact_email_or_phone`, `confidence_score`, `source`, `needs_human_review`.

**Scale note:** For the ~1,000 company dataset, a simple sequential script is fine. If this pipeline needs to run in real-time as accounts onboard, the source queries in Step 2 would need to run in parallel and the slower sources (website scraping, deep SoS lookup) would move to an async enrichment queue. Accuracy trades off against speed at that point.

---

## Sources & Strategy

No single source is reliable enough on its own. The strategy is to query multiple independent sources and look for agreement across them. A name that shows up in one place is a lead. A name that shows up in three separate places is a finding.

**Sources, roughly in order of trustworthiness:**

- **Secretary of State filing** — The most authoritative source. The business itself registered this information. Returns registered agent, owner, principal officer. Requires the legal name and state.
- **Company website** — The business published this themselves. About and Contact pages frequently list key personnel by name and title. Requires finding the domain first (try `[common_name].com`, `[common_name][city].com`).
- **Google Maps / Business Profile** — Often contains owner-provided phone and sometimes owner name. Useful for address confirmation — if the source address matches our input, that's a strong signal we have the right business and not a same-name company in a different city.
- **LinkedIn company page** — Employees list their employer and title. Good for finding AP managers, CFOs, office managers at small businesses. Lower trust than SoS or website because profiles are self-reported and may be outdated.
- **Google search** — Broader signal. Owner mentions in local news, reviews, press, permits. Less structured but can surface names that don't appear in the cleaner sources. Can also lead us to company website if the earlier company website attempt fails. 

The address confirmation from Google Maps serves a specific purpose: it guards against the false-positive case where a common business name (e.g., "Hometown Hardware") matches a different company in a different city.

---

## Quality

**Confidence scoring — no arbitrary weights**

Rather than assigning arbitrary numeric weights to sources, confidence is calculated from a factual question: *how many independent source tiers confirmed this candidate?*

```
confidence = (tiers_confirmed / tiers_queried) × 100
```

If 4 source tiers were queried and a candidate appeared in all 4 → `4/4 × 100 = 100` 

If a candidate appeared in 2 of 4 tiers → `2/4 × 100 = 50`.

This produces a number that is fully explainable: "Person A has a confidence of 75 because they appeared in 3 of 4 source tiers." Every point traces back to a fact, not a weight someone chose.

**One honest penalty: address mismatch**

If no source confirmed the business at the input address (city + state), flag for human review. This part is binary, either we have the company or we don't.

**Important note on AI confidence scores**

AI can produce a confidence score of 1–100, but no one — including the model itself — can explain how it arrived at that number, and it can be easily shifted by rephrasing the prompt. That is not a confidence score, it's a guess. The algorithm above produces a score that can be audited and explained to a human reviewer or a compliance team.

**Deduplication**

Names with ≥85% fuzzy similarity across sources are treated as the same candidate. Merge their source attributions, count their confirmed tiers, score once. If two clearly different candidates come back within 10 points of each other, surface both and flag `needs_human_review=true` — that's genuine ambiguity a human should resolve.

**It is possible for two candidates to both score 100.** This likely indicates a company where multiple people share decision-making authority (e.g., two co-owners, or a company where the owner and AP manager are both clearly identified). Flag for human review — don't arbitrarily pick one.

**Provenance**

Every field in the output carries its source. The `source` column lists all providers that contributed, e.g. `secretary_of_state, google_maps`. If a name came from LinkedIn and a phone number came from the company website, both are recorded separately. Nothing in the output is unattributed.

**Cannot-verify states**

| Situation | Output |
|-----------|--------|
| No source returns any contact | All contact fields null, confidence=0, needs_human_review=true |
| Best candidate below threshold | Populate fields, needs_human_review=true |
| Two candidates within 10 points | Output best, needs_human_review=true |
| No source confirms the address | needs_human_review=true |
| Only a phone number found, no named contact | Output phone, confidence low, needs_human_review=true |

---

## Privacy / Compliance

**Would use:**
- Secretary of State public records (filed by the business, fully public)
- Information published on the company's own website (they published it)
- Google Maps / Business Profile (business-provided, public)
- LinkedIn company pages and employee profiles in a business context
- BBB listings and other public business directories

**Would not use:**
- Personal social media (personal Facebook, Instagram, X/Twitter)
- Personal cell numbers not published as a business contact
- Data brokers that aggregate personal PII without clear disclosed consent
- Any source that requires bypassing access controls or scraping behind a login

**FDCPA relevance:** This is debt collection. Under FDCPA, contacting the wrong person — especially disclosing the nature of the debt to someone who isn't the debtor or their authorized representative — creates legal exposure. A receptionist isn't the right contact. An unrelated employee isn't the right contact. Any result where the decision-making authority of the contact cannot be confirmed gets `needs_human_review=true`, regardless of how confident we are in the name itself.

**Data retention:** Enriched contact data should not be stored beyond the scope of this workflow. It was collected for a specific collections purpose and should be scoped to that use.

---

## Clarifying Questions

### Q1 — What data sources are permissible, and is there a compliance boundary we need to stay inside?

**Why it matters:** There's a spectrum from "only use publicly available records" to "use any data source that isn't explicitly illegal." Where the client sits on that spectrum determines which sources make it into the pipeline. Some enterprise clients have strict data sourcing policies — especially in financial services — and certain data brokers or scraping approaches may be off-limits regardless of legality.

**Default assumption:** Public sources only — SoS filings, company websites, Google Maps, LinkedIn company pages, public business directories. No data brokers, no personal social media, no sources that require authentication or bypass access controls.

**What changes:** If the client allows licensed data broker APIs (ZoomInfo, Apollo, etc.), recall improves significantly — those databases have pre-enriched contact data for millions of businesses. If the client is more restrictive than my default, some sources (LinkedIn scraping, Google search) may need to be removed from the pipeline.

---

### Q2 — What role counts as the right decision-maker for this client's accounts?

**Why it matters:** The sample dataset is entirely small trades and service businesses — plumbers, landscapers, auto shops, cafes. For a 5-person plumbing LLC, the owner is the person who approves invoices. For a 30-person HVAC company, it might be an AP manager or office manager. The role I surface — and whether I flag a non-owner contact as `needs_human_review` — depends on what the client considers actionable for outreach.

**Default assumption:** Target in priority order: Owner / Principal > CFO / Controller > AP Manager > Office Manager. For businesses that appear to be small sole-proprietor operations, I'll prioritize owner. If the only contact found is an office manager with no higher-authority contact available, I'll populate the fields but flag `needs_human_review=true`.

**What changes:** If any decision-maker contact is acceptable, the `needs_human_review` flag comes off for AP managers and office managers where confidence is otherwise high. If the client specifically requires the owner or principal, a contact who isn't confirmed in that role gets flagged regardless of confidence score.

---

### Q3 — Does this output feed directly into an automated outreach sequence, or does a human review it first?

**Why it matters:** A wrong contact surfaced to a human reviewer costs a few seconds. A wrong contact fed into an automated voice agent or email sequence causes real damage — to the debtor relationship, to the client's brand, and potentially under FDCPA. How conservative the pipeline needs to be is determined by what happens to its output.

**Default assumption:** A human reviews at least the sub-threshold queue before any outreach goes out. High-confidence contacts may route to automated outreach. I'll design the output to support both paths via the `needs_human_review` flag.

**What changes:** Fully automated end-to-end → raise the auto-contact threshold significantly, mark everything below it cannot-verify rather than low-confidence-but-surfaced. Always human-reviewed → lower the threshold, surface more candidates, let the human make the final call.

---

*End of Stage A. Committed before reading `CLARIFICATIONS.md` or writing any solution code.*
