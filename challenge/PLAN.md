# PLAN.md

> Written in Stage A, before reading CLARIFICATIONS.md or writing any solution code.

---

## Architecture

### Overview

A pipeline that takes `companies.csv` (rows: `company_name`, `mailing_address`) and
produces one output row per company: the best-verified decision-maker contact we can
attribute, or an explicit "cannot verify" record.

```
Input CSV
   │
   ▼
┌──────────────────────────────────────┐
│  Ingestion & Normalisation           │
│  • parse CSV, trim whitespace        │
│  • normalise company name (slug)     │
│  • parse address into components     │
└───────────────┬──────────────────────┘
                │
                ▼
┌──────────────────────────────────────┐
│  Parallel Source Fan-out             │
│  Provider A: Business Registry       │
│  Provider B: Web/Maps Listing        │
│  Provider C: Email/Phone Enrichment  │
└───────────────┬──────────────────────┘
                │
                ▼
┌──────────────────────────────────────┐
│  Merge & Deduplication               │
│  • reconcile names across sources    │
│  • merge contact fields              │
│  • flag conflicts                    │
└───────────────┬──────────────────────┘
                │
                ▼
┌──────────────────────────────────────┐
│  Confidence Scoring                  │
│  • source count, source types        │
│  • name agreement across sources     │
│  • role seniority weight             │
│  • provider_confidence (enrichment)  │
└───────────────┬──────────────────────┘
                │
                ▼
┌──────────────────────────────────────┐
│  Output Layer                        │
│  • contact_name, contact_role        │
│  • contact_email_or_phone            │
│  • confidence_score (0-100)          │
│  • source (list of source_urls)      │
│  • needs_human_review (bool)         │
└──────────────────────────────────────┘
```

All three providers are queried per company. Results flow into the merge step
independently of whether a provider returned data. This is intentional: a missing
result from one source is itself a signal (absence of a registry entry for a
supposedly-incorporated LLC is notable).

---

## Sources & Strategy

### Source types and why I'd combine them

| Source type | What it offers | How it fails |
|---|---|---|
| **Business registry** (state filings, SOS) | Authoritative legal name + role (owner, registered agent, officer). High trust when present. | Coverage is patchy for sole proprietors and DBAs. Officers change but filings lag. Registered agent ≠ the AP contact we want. |
| **Web / maps listing** (Google Business, Yelp, Bing) | Phone number and sometimes a first name or "owner" label. Reaches businesses that never filed anything formal. | Name is often "the business", not a person. Phone may be the main line, not a direct contact. |
| **Email / phone enrichment** (e.g., Hunter, Clearbit, ZoomInfo) | Role-matched direct email and/or phone. Provider has already done some cross-referencing. | Provider confidence varies wildly; "best guess" emails are common for micro-businesses. |

### Cross-referencing logic

Name agreement is the strongest signal. If registry says "Karen Liu" and the listing
says "Karen Liu", that's a corroborated identity even without enrichment. If the
enrichment email looks like `k.liu@...`, that's further corroboration. Disagreement
(different names across sources) depresses confidence and flags for review.

I would also do a lightweight fuzzy-match on names (e.g., "Bob" == "Robert", "S.
Murphy" ≈ "Sean Murphy") before treating them as conflicts.

### Role prioritisation

For an AP/payment context the ideal target is: Owner > CFO/Finance > AP Manager >
Office Manager > General Manager > Registered Agent (last resort). A "Registered
Agent" role from the registry alone does NOT mean this person handles invoices —
confidence penalty applies.

---

## Quality

### Deduplication

1. **Slug the company name** (lowercase, remove punctuation, collapse spaces) to
   form a stable key that survives `LLC` vs `` differences in source data.
2. **Fuzzy-match contact names** using token-sort ratio (e.g., Levenshtein-family):
   "Daniel Ortega" vs "D. Ortega" → same person with high probability.
3. **Exact-match contact channel** (email / phone): the same email appearing in two
   sources is a hard corroboration signal.

### Confidence score (0–100)

I compute a weighted sum of independent signals, capped at 100:

| Signal | Points |
|---|---|
| Registry has a name + role | +30 |
| Listing has a matching name | +20 |
| Enrichment email present | +15 |
| Enrichment phone present | +10 |
| Name agrees across ≥ 2 sources (fuzzy) | +15 |
| Email domain plausibly matches company slug | +5 |
| Provider enrichment confidence ≥ 80 | +5 |
| **Deductions** | |
| Role is "Registered Agent" only | −10 |
| Name conflict across sources (irreconcilable) | −20 |
| Only enrichment present, provider_confidence < 50 | −20 |
| Zero sources have a name | −30 (floor at 0) |

This is a first-pass heuristic. In production I'd calibrate against a labelled
ground-truth sample.

### Provenance

Every field in the output carries the `source_url` (or list of `source_url`s) it
was derived from. The output schema includes a `sources` array so auditors can trace
every value back to the raw provider response. No value is emitted without at least
one attributing `source_url`.

### "Cannot-verify" states

I define three output states:

| State | `confidence_score` | `needs_human_review` | Contact fields |
|---|---|---|---|
| **Verified** | ≥ threshold | false | Populated |
| **Low confidence** | 1 – (threshold−1) | true | Populated but flagged |
| **Cannot verify** | 0 | true | All null/empty |

The "Low confidence" state is important: it says "here is a lead, but don't trust it
without a human glance." The "Cannot verify" state (all sources returned nothing
useful) gives the human reviewer a clean signal to look elsewhere rather than a
fabricated contact to chase.

### False-positive risk

The greatest false-positive risk is an enrichment provider returning a plausible-
looking email that doesn't belong to a decision-maker (or belongs to no one). To
mitigate:
- Never emit a contact that is *only* from enrichment at low provider_confidence
  without `needs_human_review = true`.
- Flag when the enrichment email domain doesn't match any slug-derived domain
  pattern.
- Never invent a contact by combining a name from one source with an email from a
  source that never mentioned that name.

---

## Privacy / Compliance

### What I WILL do
- Use only publicly available business registry data, public listings, and
  permission-based enrichment APIs whose ToS allows B2B prospecting.
- Store only business contact information (not personal home addresses, personal
  social accounts, or data obviously collected without consent).
- Log every data source and timestamp so any individual can request deletion of
  their data and we can demonstrate its provenance.
- Respect robots.txt and rate limits on any source we query directly.

### What I will NOT do
- Scrape personal social profiles (LinkedIn, Facebook) for direct contact info
  — ToS violation and GDPR/CCPA risk for any EU/CA individuals.
- Use data purchased from brokers that cannot demonstrate lawful basis for
  collection.
- Attempt to derive personal home addresses or personal mobile numbers from
  business records.
- Emit a confident-looking contact based purely on inference (e.g., guessing
  `firstname@company.com` with no corroborating source).
- Store enrichment data beyond the immediate processing run without a documented
  retention policy.

### Regulatory defaults
- Treat any individual (sole proprietor, owner) whose data appears as a data
  subject under GDPR if they are EU-based — even if the context is B2B.
- Include an opt-out mechanism in any outreach campaign using this data.

---

## Clarifying Questions

### 1. What is the acceptable false-positive rate vs. false-negative trade-off?

**Why it matters:** A false positive (contacting the wrong person or a fabricated
contact) harms the client relationship and wastes outreach effort. A false negative
(flagging a real contact as "cannot verify") means manual work. Without knowing
which error is costlier, I cannot set the right confidence threshold.

**Default assumption:** I'll set the threshold at 60/100. Below that, `needs_human_review
= true` and the contact is surfaced to a human queue rather than auto-dialled or
auto-emailed. This errs slightly toward false negatives (more manual review) to
protect the brand.

**What changes depending on the answer:**
- If false positives are cheap (e.g., the outreach is low-stakes email, not a
  collection call), I lower the threshold to ~40 and auto-send more.
- If false positives are very costly (e.g., collection letters to a legally wrong
  party), I raise the threshold to 80 and route ≈50% of the batch to human review.
- The score weights for "Registered Agent only" and "low enrichment confidence"
  penalties would also be tuned accordingly.

---

### 2. Who is the ideal decision-maker persona, and do we need a direct channel (email or phone), or is a company phone plus name sufficient for first outreach?

**Why it matters:** "Owner" in a 5-person plumbing company IS the AP function.
"CFO" in a 50-person logistics firm is not. The right enrichment strategy and the
acceptable contact channels differ: if we just need a named person to reference on a
letter, a listing phone is enough. If we need to email, we need an email. This
determines which source signals I weight most heavily and what "found" even means.

**Default assumption:** The target is the business owner / sole decision-maker
(consistent with small-business context). A verified name + any working contact
channel (email OR phone) counts as "found." Company main-line phone is acceptable
for first outreach if a name is attached.

**What changes depending on the answer:**
- If only email is accepted (e.g., automated email sequences): I must require an
  enrichment email to be present; a phone-only result becomes "cannot verify for
  email."
- If a specific role tier is required (CFO, AP): role-matching logic becomes
  mandatory, not optional, and sources without role data lose significant weight.
- If the client will be calling manually, a listing phone with any name is high
  value; if automated, email becomes the primary channel and the enrichment weight
  doubles.

---

### 3. What is the freshness requirement, and how often will this pipeline run?

**Why it matters:** Business registry data can be years out of date; listing data
changes monthly; enrichment providers have their own staleness. If this is a one-
time batch for ~1,000 accounts, I cache nothing. If this is a recurring pipeline
that ingests new accounts weekly, I need a staleness policy (re-query after X days)
and a data model that tracks when each source was last fetched — otherwise I'll
serve stale contacts and erode trust in the system.

**Default assumption:** This is a one-time (or very infrequent) batch run. I design
for single-pass: query all sources per company, emit results, done. No caching
layer, no refresh scheduler.

**What changes depending on the answer:**
- Recurring pipeline: I add a `fetched_at` timestamp per source response and a
  refresh-after policy (e.g., re-query enrichment after 30 days, registry after 90).
- Long-running system: the architecture gains a datastore (simple DB or document
  store) to persist raw source responses and decouple fetching from scoring —
  allowing re-scoring without re-querying.
- If freshness SLA is very tight (< 24 h), I add async workers per company rather
  than serial processing, and the fan-out in the architecture becomes a job queue.
