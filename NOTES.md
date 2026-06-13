# NOTES.md — Contact Finder slice

## Pipeline (5 bullets)

1. **Ingest** — read `company_name` + `mailing_address` from the CSV; each row is an independent unit of work (in production keyed by `idempotency_key = hash(norm_name + norm_address)` — see `PLAN.md`).
2. **Query 3 independent, fallible sources** (mocked): `registry` (owner/agent name + role), `listing` (phone, sometimes a name), `enrichment` (email/phone + a self-reported `provider_confidence`). A source can return partial fields, nothing, or be absent entirely.
3. **Cross-reference** — fuzzy-match names across sources (`NameMatcher`: "Robert"↔"Bob", "Sean"↔"S.") to detect *agreement* (raises confidence) vs. *conflict* (different people → forced review). Corroborate phone/email across sources.
4. **Score 0–100** on two axes (identity + channel), then apply **hard rules** that override the number.
5. **Gate at 70** — `≥70` emit the contact + provenance; `<70` (or any hard rule) → `contact_email_or_phone = ""`, `needs_human_review = true`, with a machine `reason`.

Run it: `php artisan contacts:find` → writes `storage/app/contacts_found.csv` + a console table.

## Why these sources (and how they fail)

Chosen for **independence**, so agreement between them is a real signal rather than echo. Each fails differently — registry is strong on legal owner but weak on email and stale on tiny businesses; listings give a business phone but often a role-less or informal name; enrichment has broad coverage but emits plausible-but-wrong guesses with inflated self-confidence. No single source is trusted alone; that's the whole point. (In a real, zero-budget build these map to free state business registries, Google/Maps listings, WHOIS, BBB/Yelp, and a free-tier enrichment lookup.)

## confidence_score formula

`score = clamp(identity + channel, 0, 100)`, weights as deliberate human judgement (multiples of 5), **not** fitted to data — we have no ground truth yet, so they're named constants ready to recalibrate against a human-review feedback loop.

**Identity — do we know WHO the decision-maker is?**
| Signal | Pts |
|---|---|
| Registry name present | +30 |
| Listing name present | +15 |
| Name agreement (≥2 sources, same person) | +20 |
| **Name conflict (different people)** | **−45** |
| Role = decision-maker (owner/founder/AP/CFO) | +15 |
| Role = manager (fallback persona) | +5 |
| Role = registered agent (not a decision-maker) | −10 |
| Email local-part corroborates the person | +10 |

**Channel — can we actually REACH them?**
| Signal | Pts |
|---|---|
| Personal email (tied to the person) | +20 |
| Generic mailbox (`info@`/`office@`…) | +5 |
| Phone present | +10 |
| Phone corroborated (listing == enrichment) | +20 |
| Vendor `provider_confidence`, discounted into bands (≥80 +15 / 60–79 +10 / 40–59 +5) | + |

**Hard rules (override the score → always human review):** conflicting sources, no reachable channel, no source at all. These are explicit, not left to arithmetic — a business invariant ("never guess between two candidates") shouldn't depend on a sum clearing 70.

**Result on the 30-row dataset:** 8 auto-emitted (≥70), 22 to human review — including all 12 companies absent from every source. This is intentional: the brief optimizes **precision over recall**, so a high review rate on genuinely hard rows is a good outcome, not a failure. We never fabricate a contact, and every emitted value carries its `mock://` `source_url` provenance.

## What I'd add in the next 30 minutes

- **Surname-blocking / better entity resolution** so two real "Blue Heron Landscaping" in different states don't collide, and an `ambiguous_duplicate` review flag (designed in `PLAN.md`, not built in the slice).
- **A calibration harness**: feed reviewer decisions back to tune the weights and per-source trust priors, turning judgement into measured precision.
- **A 4th independent source** (e.g. WHOIS on a resolved domain) to convert single-source `needs_human_review` rows into corroborated emits.
- **Suppression / opt-out list** check at ingest (compliance hook from `PLAN.md`).
