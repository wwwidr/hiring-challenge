# Contact Finder — Stage B Solution

Minimal working slice against the mocked providers. Reads `data/companies.csv`,
queries all three mock providers per company, scores confidence, and emits a
per-row decision with full provenance.

## Run

```bash
cd challenge/solution
python3 contact_finder.py           # writes output.csv + output.json
python3 contact_finder.py --verbose # also prints a per-company summary
```

No dependencies beyond the Python 3.10 standard library.

## Test

```bash
cd challenge/solution
PYTHONPATH=.deps python3 -m pytest tests/ -v
# or, if pytest is installed globally:
python3 -m pytest tests/ -v
```

40 tests: 21 unit tests (scorer rules and name-matching in isolation) +
19 integration tests (full pipeline against real mock data).

## Output fields

| Field | Description |
|---|---|
| `company_name` | From input CSV |
| `mailing_address` | From input CSV |
| `contact_name` | Best resolved name, null if needs_human_review |
| `contact_role` | Role from registry, null if needs_human_review |
| `contact_email_or_phone` | Best personal contact method, empty string if needs_human_review |
| `confidence_score` | 0–100, formula below |
| `source` | Comma-separated mock:// source URLs (always present, even on review rows) |
| `needs_human_review` | true when score < 70 or sources conflict |

## Confidence formula (adapted from PLAN.md after CLARIFICATIONS)

```
score = base + agreement_bonus + role_bonus − conflict_penalty − generic_penalty

base
  enrichment present  →  enrichment.provider_confidence
  registry only       →  50
  listing + name      →  35
  listing, phone only →  20
  no sources          →   0

agreement_bonus (additive, independent sources only)
  registry name == listing name (fuzzy)           +15
  registry name matches enrichment email local    +12
  listing name matches enrichment email local     +10

role_bonus
  AP Manager / Accounts Payable                   +10   ← top priority (CLARIFICATIONS)
  Owner / President / CFO / Founder               +8
  Manager / Office Manager                         0
  Registered Agent                                -5

conflict_penalty
  registry and listing name different last names  -25   → also forces needs_human_review

generic_penalty
  email is info/office/sales/contact/… AND
  no personal name found from any source          -12

threshold: score < 70  →  needs_human_review = true
```

## Results summary

```
Verified contacts  : 8   (Cedar Ridge, Bayview, Pioneer, Harbor Light,
                          Greenfield, Ironclad, Brookside, Tidewater)
Needs human review : 22  (11 companies with zero mock data, plus weak/
                          conflicting/generic-only signals)
```

A high human-review rate on genuinely hard rows is the correct result
(precision over recall — see CLARIFICATIONS.md).

## Adaptations from PLAN.md after reading CLARIFICATIONS

| PLAN default | CLARIFICATIONS answer | Change made |
|---|---|---|
| Threshold = 60 | **Threshold = 70** | Raised threshold; Lakeside Auto Glass (68) now routes to review |
| Persona priority: Owner first | **AP Manager first** | Role bonus table reordered: AP Manager +10, Owner/CFO +8 |
| Generic email: include with penalty | Precision over recall confirmed | Kept penalty, no change needed |
| Conflict: hold for human review | Unanswered — default holds | Conflict still routes to review; both candidate names in `_notes` |
