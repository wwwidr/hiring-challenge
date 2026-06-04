# PLAN.md (commit this BEFORE reading CLARIFICATIONS.md or writing solution code)


## Architecture
Single-pass pipeline with three stages:

**1. Enrichment**
For each input row (company_name + mailing_address), invoke multiple
Provider mocks in parallel. Each provider returns zero or more candidates
contacts with raw metadata.

**2. Consolidation**
Merge candidates across providers. Dedupe on (normalized_name +
normalized_email_or_phone). For each unique contact, aggregate source
provenance and compute a confidence score.

**3. Output**
Emit one row per input company: best contact by confidence score, with
full provenance, a numeric confidence score, and a needs_human_review
flag when confidence is below threshold or no contact was found.

Data flow:
CSV → row iterator → parallel provider calls → candidate pool →
deduplication → scoring → output CSV/JSON

No database needed for this slice. In production I'd add a job queue
(each company as a task), persistent storage, and a retry layer for
provider failures.

## Sources & strategy
I'd combine at least three types of sources:

**Business registries** (Secretary of State filings, FEIN lookups)
— highest trust for owner/registered agent names. Fails on DBAs and
sole proprietors who file under personal names.

**LinkedIn / professional directories**
— good for AP managers and CFOs at slightly larger SMBs. Fails on
micro-businesses with no digital presence.

**Local business listings** (Google Business Profile, Yelp, BBB, Google Maps)
— often has a phone number and sometimes a contact name. Lower
confidence since the contact may be front-desk, not decision-maker.

**Cross-reference by address**
— if two sources return the same name at the same mailing address,
confidence increases meaningfully.

Failure modes: rural SMBs with no online presence, recently formed
LLCs with no digital footprint, companies that have moved since
registration. These become cannot-verify rows.

## Quality

**Deduplication**
Normalize names (lowercase, strip punctuation, collapse whitespace)
and contact values before comparing. Two candidates are the same
contact if normalized_name matches AND (email matches OR phone matches).
Keep all source attributions on the merged record.

**Confidence scoring (0–100)**

| Signal | Points |
|---|---|
| Name + contact verified by 2+ independent sources | +40 |
| Name + contact from single high-trust source (registry) | +30 |
| Name + contact from single medium-trust source (listing) | +20 |
| Role matches target persona (owner/CFO/AP/office manager) | +15 |
| Address on record matches input address | +10 |
| Contact found but role unknown | -10 |
| Single source, low-trust origin | -15 |

Cap at 100. Floor at 0.

**Provenance**
Every field traces to its source. Output includes a `source` field
listing all mock providers that contributed to this contact. No value
is emitted without a traceable origin.

**Cannot-verify states**
If no provider returns a candidate: output a row with all contact
fields null, confidence_score = 0, needs_human_review = true, and
a cannot_verify_reason string (e.g. "no results from any provider").
A confident-looking null is better than an invented contact.

**False-positive risk**
Common business names (e.g. "Cedar Ridge LLC") may match multiple
companies in the same state. I mitigate by requiring address
corroboration before assigning high confidence. If address doesn't
match, confidence is capped at 40 and needs_human_review = true.

## Privacy / compliance
**Will do:**
- Use only data the mock providers surface (simulating publicly
  available business records and directories)
- Store provenance so every contact can be audited
- Flag low-confidence contacts for human review before outreach
- Respect not-found signals rather than guessing

**Will NOT do:**
- Scrape personal social media (Facebook, personal LinkedIn profiles)
- Infer personal home addresses from mailing addresses
- Fabricate or hallucinate contacts when no data is found
- Combine data in ways that reveal personal information beyond
  what's needed for B2B collections contact (name, business role,
  business contact)

In production I'd also add GDPR tagging at the record level
and a data retention policy for enriched contacts.

## Clarifying questions
**1. What is the confidence threshold for needs_human_review?**
- Why it matters: This is the single most consequential tuning parameter.
  Too low and we flood the human review queue. Too high and we send
  outreach to unverified contacts at scale, risking regulatory exposure.
- Default assumption: 60. Below 60 = needs_human_review = true.
- What changes: If the threshold is higher (e.g. 75), I widen the
  cannot-verify bucket and the scoring rubric needs recalibration.
  If lower (e.g. 40), the false-positive
  risk increases meaningfully.

**2. Is there a priority order among target personas?**
(Owner > CFO > AP Manager > Office Manager, or treat them equally?)
- Why it matters: When two contacts are found with equal confidence,
  persona rank is the tiebreaker. If all personas are equally valid,
  I just pick highest confidence. If there's a preferred hierarchy,
  I encode that in the scoring logic.
- Default assumption: Owner and CFO rank above AP Manager and Office
  Manager. I'll use that order as a tiebreaker at equal confidence.
- What changes: If all personas are equal, I simplify the scoring and
  remove persona-based tiebreaking.

**3. Should the output be one contact per company, or all viable contacts ranked?**
- Why it matters: One row per company is simpler to consume downstream
  but loses signal. Multiple ranked contacts lets a human reviewer
  choose. This affects output schema and how I handle the case where
  two sources return different people.
- Default assumption: One contact per company (highest confidence),
  with all sources listed in the provenance field.
- What changes: If multiple contacts are wanted, I change the output
  to a one-to-many structure and adjust the schema accordingly.

