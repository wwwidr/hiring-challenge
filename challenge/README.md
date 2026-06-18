# Stage B — Contact Finder: Minimal Working Slice

A single-pass pipeline that takes `data/companies.csv` (company name + mailing
address), queries three mocked provider sources, and emits one scored contact row
per company — or an explicit `needs_human_review` record when confidence is too low.

---

## How to run

**Requirements:** Python 3.10+ (uses `dataclasses`, `argparse`, type hints — no
third-party dependencies).

```bash
# From the repo root:
python challenge/contact_finder.py
```

Optional flags:
```bash
python challenge/contact_finder.py \
  --input  challenge/data/companies.csv \
  --mocks  challenge/mocks/enrichment_responses.json \
  --output challenge/output/contacts.csv
```

Output is written to `challenge/output/contacts.csv` and a summary table is printed
to stdout.

---

## How to run tests

```bash
# From the repo root:
python -m pytest challenge/tests/ -v
```

---

## Output columns

| Column | Description |
|---|---|
| `company_name` | Input company name — the join key |
| `mailing_address` | Input address, passed through |
| `contact_name` | Best-verified decision-maker name |
| `contact_role` | Role from registry (if present) |
| `contact_email_or_phone` | Direct contact channel — **empty when `needs_human_review=true`** |
| `confidence_score` | 0–100, computed from signal combination (see below) |
| `source` | Semicolon-separated `source_url`s — every value is fully attributable |
| `needs_human_review` | `true` when score < 70 or no data found |
| `_raw_contact_channel` | Contact channel preserved for human reviewers even when flagged |
| `_score_breakdown` | Human-readable explanation of how the score was computed |

---

## Confidence scoring model

Additive signal model, capped 0–100. Designed around the principle that
**more independent agreeing sources = higher confidence**.

### Positive signals

| Signal | Points |
|---|---|
| Registry: name + role present | +30 |
| Listing: name present | +20 |
| Names agree across ≥2 sources (fuzzy) | +15 |
| Verified identity + reachable channel, cross-source attribution | +15 |
| Enrichment: email present | +15 |
| Enrichment: phone present | +10 |
| Email domain plausibly matches company slug | +5 |
| Provider confidence ≥ 80 | +5 |

### Deductions

| Signal | Penalty |
|---|---|
| Role is "Registered Agent" only | −10 |
| Name conflict between registry and listing | −20 |
| Sole enrichment source + provider confidence < 50 | −20 |
| No name found in any source | −30 |

**Threshold = 70** (from CLARIFICATIONS.md). Below this: `contact_email_or_phone`
is cleared to `""` and `needs_human_review = true`. The raw channel is preserved in
`_raw_contact_channel` for the human review queue.

### Name fuzzy matching

The name comparison handles:
- Case-insensitive exact match (`"Maria Gomez"` == `"maria gomez"`)
- Nickname expansion (`"Bob"` ≈ `"Robert"`, `"Dan"` ≈ `"Daniel"`)
- First-initial abbreviation (`"S. Murphy"` ≈ `"Sean Murphy"`)
- Surname-only match (`"K. Liu"` ≈ `"Karen Liu"`)

---

## How this adapts from PLAN.md

The plan was written before reading CLARIFICATIONS.md. Here is every design decision
that changed after reading it:

| PLAN.md assumption | Clarification received | Change made |
|---|---|---|
| Default threshold = 60 | Threshold = **70** | Updated `CONFIDENCE_THRESHOLD` constant |
| Owner/founder first in role priority | **AP Manager first**, then owner for small biz, then CFO | Updated `ROLE_PRIORITY` weights |
| Trade-off between false positives and false negatives TBD | **Precision over recall** — high review rate on hard rows is a good result | Confirmed conservative threshold; no change to mechanism |
| "Found" = any channel (email or phone) | One good contact per company is enough | No change needed — already single-contact design |

---

## Notable mock scenarios

| Company | Mock pattern | Score | Reviewed? |
|---|---|---|---|
| Cedar Ridge Plumbing | All 3 sources agree | ~95 | No |
| Pioneer Landscaping | All 3 agree, conf=88 | ~100 | No |
| Ironclad Welding | All 3 agree (Bob ≈ Robert), conf=81 | ~100 | No |
| Brookside Veterinary | All 3 agree, conf=86 | ~100 | No |
| Bayview Auto Repair | Registry + enrichment | ~75 | No |
| Harbor Light Electric | Registry + listing agree (S. Murphy ≈ Sean Murphy) | ~80 | No |
| Greenfield Catering | Registry + enrichment | ~75 | No |
| Tidewater Plumbing | Registry + enrichment | ~75 | No |
| **Coastal Breeze Pool** | Registry ≠ listing — **name conflict** | ~30 | **Yes** |
| **Northgate HVAC** | Registry only, Registered Agent role | ~20 | **Yes** |
| **Lakeside Auto Glass** | Listing + enrichment, no registry | ~50 | **Yes** |
| **Riverside Print & Sign** | Enrichment only, conf=41 | 0 | **Yes** |
| **Summit Pest Control** | Enrichment only, conf=38 | 0 | **Yes** |
| **Maple Leaf Bakery** | Listing with no name — phone only | 0 | **Yes** |
| **11 companies** | No mock data at all — cannot verify | 0 | **Yes** |
