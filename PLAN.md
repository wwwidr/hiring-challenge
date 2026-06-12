# PLAN.md — Contact Finder
**Author:** Sameer Ray | **Date:** 2026-06-07
**Status:** Stage A — written BEFORE reading CLARIFICATIONS.md or Stage B

## Architecture

- CSV input: company_name, mailing_address
- Enrichment pipeline (per row, 3 providers): registry, listing, enrichment
- Merge + cross-reference layer: agreement boosts confidence, conflicts resolved by priority
- Confidence scorer (0-100, explainable breakdown per row)
- Threshold gate at 70: below = needs_human_review=true + empty contact
- Output CSV: contact_name, contact_role, contact_email_or_phone, confidence_score, source, needs_human_review

## Sources & Strategy

| Provider   | Signal                        | Trust    | Failure mode                      |
|------------|-------------------------------|----------|-----------------------------------|
| registry   | Owner/agent name + role       | High     | Often missing for tiny sole props |
| listing    | Phone, sometimes generic name | Medium   | Role-less, front-desk risk        |
| enrichment | Email/phone + self-confidence | Variable | Plausible-but-weak guesses        |

Combination strategy:
- Registry + enrichment agree on person = highest confidence
- Registry alone = name+role, no contact = medium confidence
- Enrichment alone high provider_confidence = medium-high
- Listing only = lowest, likely needs human review
- Zero sources = cannot-verify, confidence=0

Target persona (assumption): AP manager first, then owner/founder for small biz, CFO for larger, office manager fallback.

## Quality

Confidence scoring (0-100, explainable):
- registry name + role found: +40
- enrichment email found: +25
- enrichment.provider_confidence >= 80: +15 bonus
- enrichment.provider_confidence < 50: -10 penalty
- listing phone found: +20
- name/email cross-source agreement: +15
- phone cross-source agreement: +10
- no role found anywhere: -5
- clamped 0-100

Dedup: normalize email to lowercase, phone to E.164.
Provenance: every field carries source_url, nothing emitted without attribution.
Cannot-verify: confidence=0, contact_email_or_phone="", needs_human_review=true. Never fabricate.
False-positive risk: wrong contact in B2B debt collection = FDCPA/TCPA exposure. Precision first.

## Privacy / Compliance

Will do: business contact info only, source_url provenance on every field, suppression list support, EU/UK GDPR flag on addresses.
Will NOT do: personal home emails, dark-pattern scraping, non-business PII, fabricated contacts.

## Clarifying Questions

Q1: What is the decision-maker persona priority?
- Why: Owner vs AP manager requires different source weights. Registry is authoritative for owners; enrichment for AP roles.
- Default: AP manager first, then owner for small biz, CFO for large, office manager fallback.
- What changes: Owner-first confirmed = weight registry heavily. AP-first = weight enrichment + listing over registry.

Q2: What confidence threshold triggers needs_human_review?
- Why: Sets precision/recall tradeoff. Wrong contact in debt collection = FDCPA liability.
- Default: 70. Below = human review, empty contact returned.
- What changes: Client wants recall = lower to 50. Zero legal risk tolerance = raise to 80+.

Q3: One-time batch or recurring pipeline?
- Why: Batch = simple CLI script. Recurring = service with staleness detection and re-enrichment triggers.
- Default: One-time batch for this challenge.
- What changes: Recurring = add last_enriched_at, change_detected, cron trigger.
