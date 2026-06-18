# PLAN.md

## Architecture

The system is a sequential enrichment pipeline: read the input CSV, fan out to all available providers per company, aggregate signals, score confidence, resolve the best contact, and emit a structured output row.

```
CSV Input (company_name, mailing_address)
        ↓
Row Dispatcher — iterates companies, fans out in parallel
        ↓
┌──────────────┬──────────────┬──────────────────┐
│  registry    │   listing    │   enrichment     │
│  adapter     │   adapter    │   adapter        │
└──────┬───────┴──────┬───────┴──────────┬───────┘
       └──────────────▼──────────────────┘
              Signal Aggregator
              · normalize & fuzzy-match names across sources
              · detect cross-source agreement vs conflict
              · carry source_url per field (provenance)
                       ↓
              Confidence Scorer
              · weighted formula (see Quality section)
              · flags conflict, generic contact, weak-only source
                       ↓
              Contact Resolver
              · selects best contact_name / role / email / phone
              · sets needs_human_review
                       ↓
Output (CSV + JSON): one row per input company
```

**Components**

| File | Responsibility |
|---|---|
| `contact_finder.py` | Entry point: load CSV, orchestrate pipeline, write output |
| `providers.py` | Mock provider adapter — reads `enrichment_responses.json`, returns typed dicts per provider |
| `scorer.py` | Name normalization, fuzzy matching, confidence formula |
| `tests/` | Unit tests per scorer rule + integration tests for representative rows |

---

## Sources & strategy

Three independent sources, each with distinct failure modes:

| Source | What it contributes | How it fails |
|---|---|---|
| **registry** | Official name + role from state business filing — highest authority for ownership | Absent for sole proprietors, DBAs, and micro-businesses that never registered an LLC |
| **listing** | Business phone almost always present; name sometimes present | Names are often role-less, informal, or entirely missing; phone may be a general line |
| **enrichment** | Personal email and/or phone with a self-reported `provider_confidence` | Can be a plausible-sounding generic guess (info@, office@); confidence score is provider-biased upward |

**Cross-referencing strategy**

A single source is treated as a weak signal. Confidence rises when independent sources agree on the same person. When sources conflict (different names), the score is penalized and the row is flagged for human review — we do not pick a "winner" from conflicting signals without more evidence.

**Persona priority** (when multiple candidates exist across sources):
Owner / President / CFO → AP Manager / Office Manager → Manager (generic) → Registered Agent → anonymous contact (phone/email only, no name)

A Registered Agent is often a law firm or third party, not a payment decision-maker, so it is treated as a lower-value hit.

---

## Quality

### Name normalization & fuzzy match

Before comparing names across sources, normalize:
- lowercase, strip punctuation
- expand initials: "S. Murphy" → matches "Sean Murphy" if first initial agrees
- resolve common nicknames: "Bob" ↔ "Robert", "Bill" ↔ "William"
- strip honorifics for matching only (keep originals in output): "Dr. Emily Hart" → "emily hart"

Two names are considered the **same person** if: exact match after normalization, OR initial-match (first initial + last name match), OR known nickname pair. Different last names → treated as conflict.

### Confidence scoring

```
score = base + agreement_bonus + role_bonus − conflict_penalty − generic_penalty

base
  · enrichment present  → enrichment.provider_confidence
  · registry only       → 50   (authoritative name/role, but no contact method)
  · listing only        → 35   (contact method present, identity weakly established)
  · no sources          → 0

agreement_bonus (additive)
  +15  registry.name and listing.name resolve to same person
  +12  registry name initial-matches enrichment email local-part
       (e.g. "d.ortega" ↔ "Daniel Ortega")
  +10  listing.name and enrichment email local-part match

role_bonus
  +8   Owner / President / CFO
   0   Manager / AP Manager / Office Manager
  −5   Registered Agent

conflict_penalty
  −25  registry.name and listing.name are clearly different people
       (different last names — treat as unresolved)

generic_penalty
  −12  email local-part is generic keyword: info, office, sales, contact, admin, support
       AND no personal name is identified from any source

cap: clamp final score to [0, 100]
```

**Threshold**: score < 60 → `needs_human_review = true`, contact fields set to `null`.
Score ≥ 60 with a conflict still sets `needs_human_review = true` (conflict flag overrides).

### Provenance

Every output field traces to the `source_url` of the provider that contributed it. The `source` output field is a comma-separated list of all `mock://...` URLs used. No value is emitted without at least one `source_url`.

### "Cannot-verify" representation

If zero providers returned data, or all returned data falls below threshold after scoring:
```
contact_name            = null
contact_role            = null
contact_email_or_phone  = null
confidence_score        = <actual score, even if 0>
source                  = null
needs_human_review      = true
```
We never fabricate or guess a contact. The row is present in the output so the human review queue is complete.

### False-positive risk

The highest false-positive risk is an enrichment-only hit with a generic email and a plausible-sounding but unverified name. Mitigations:
- generic email penalty (-12) pushes these below threshold
- if provider_confidence is already low (< 50) and no other source agrees, the row almost certainly fails the threshold
- explicit `needs_human_review` prevents auto-outreach to unverified contacts

---

## Privacy / compliance

**Will do**
- Query official state business registries (public record by law)
- Use licensed data-enrichment providers under a signed DPA
- Process professional-capacity contact info (owner/manager in their business role)
- Retain output only as long as needed for the outreach campaign

**Will NOT do**
- Scrape third-party websites or business directories without permission
- Access personal social media profiles (LinkedIn, Facebook) or home addresses
- Infer or reconstruct PII not returned by a licensed source
- Share enriched data across unrelated clients or repurpose it beyond payment outreach
- Store raw provider responses longer than necessary (delete after output is produced)

**Regulatory notes**
- Business contact info for a named officer in their professional capacity is generally outside personal-data scope under GDPR/CCPA, but we apply the same controls conservatively.
- If any contact is later identified as a sole proprietor (business = person), treat as personal data and apply full data-subject rights.

---

## Clarifying questions

1. **What is the confidence threshold that triggers `needs_human_review`?**
   - *Why it matters*: The threshold directly controls the split between auto-outreach and the human queue. Moving from 60 to 70 would flag roughly 30–40 % more rows (based on the data distribution I can see), which has a direct cost in reviewer time vs false-positive outreach risk.
   - *Default assumption*: 60. Below 60 → human review, contact fields nulled out. A generic-only email with no personal name is almost always below this.
   - *What changes*: A lower threshold (e.g. 50) means more rows go to auto-outreach, including some generic-email hits. A higher threshold (e.g. 70) means more rows go to human review, including single-source registry-only hits. The scoring weights in `scorer.py` are calibrated against whichever threshold we settle on.

2. **Is a generic business email (info@, office@, sales@) acceptable as `contact_email_or_phone` when no individual is identified?**
   - *Why it matters*: Generic addresses are technically deliverable but may never reach the AP decision-maker. Including them inflates the apparent "found" rate and risks outreach going unread. Excluding them is safer but shrinks coverage.
   - *Default assumption*: Include them in the output but apply the generic penalty (−12 to confidence) and always respect the threshold — if the score falls below 60, the row goes to human review regardless. We surface the email as evidence but do not auto-outreach.
   - *What changes*: If the answer is "reject generics entirely," several companies become full cannot-verify rows, and the output schema for `contact_email_or_phone` would only ever hold a personal address or direct phone line.

3. **When sources conflict (different names from registry vs listing), should we prefer one source or hold the row for human review?**
   - *Why it matters*: A Registered Agent in a state filing might be a law firm, while the listing shows the actual operating manager. Automatically preferring registry would surface wrong contacts. But automatically preferring listing might surface a temporary employee. Conflict is the highest false-positive risk in this dataset.
   - *Default assumption*: Neither source wins. Apply the conflict penalty (−25), which almost always drops the score below 60, and route to human review. Surface both candidate names in a `notes` field so the reviewer has context.
   - *What changes*: If the answer is "registry always wins" or "listing always wins," we drop the conflict penalty and route those rows to auto-outreach — but accept the higher false-positive rate. If "only flag as conflict when last names differ," we can refine the fuzzy-match boundary (e.g. "Robert" vs "Bob" would not trigger the penalty).
