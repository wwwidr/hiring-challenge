# PLAN.md — Contact Finder

## Architecture

CSV input → query mock providers → deduplicate → score → output CSV

## Sources & Strategy

- Business registry (company name + state)
- LinkedIn-style search (company → decision-maker)
- Domain inference (company name → likely email pattern)
- Phone directory (address-based)

Two sources agreeing on a contact scores higher than one source alone.

## Quality

- Dedupe: fuzzy match on company name (handle LLC, Inc, Co. variants)
- Confidence: 0–100, based on source count, role match, and field completeness
- Provenance: every field tagged with which source returned it
- Cannot-verify: if nothing found, output empty contact fields with needs_human_review = true

## Privacy / Compliance

- Public business info only — no personal social profile scraping
- Inferred emails flagged as unverified
- No PII stored beyond what's needed for outreach

## Clarifying Questions

**Q1: What is the priority order for decision-maker roles?**
- Why it matters: if we find both a CFO and AP manager, we need to know which to surface.
- Default assumption: AP manager → owner → CFO.
- What changes: if owner is always first, role scoring weights flip.

**Q2: What confidence score triggers human review?**
- Why it matters: wrong contact at enterprise scale damages client relationships.
- Default assumption: below 70 → needs_human_review = true.
- What changes: lower threshold means more volume, more false positives.

**Q3: Is a LinkedIn URL a valid contact, or must we have email or phone?**
- Why it matters: some sources return profiles but no direct contact info.
- Default assumption: require email or phone; LinkedIn-only → needs_human_review = true.
- What changes: accepting LinkedIn URLs improves recall but lowers precision.
