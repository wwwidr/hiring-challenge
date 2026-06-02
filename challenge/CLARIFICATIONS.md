# Clarifications (read AFTER you have committed your PLAN.md)

These are our answers to the questions most candidates ask. Some of your own questions may not be answered here — that is intentional. Where we are silent, state your assumption and move on (that is also a signal).

## Target contact
- Priority order for "decision-maker": **AP manager / accounts payable** first, then **owner / founder** for small businesses, then **CFO / finance lead** for larger ones, then office manager as a fallback.
- One good contact per company is enough. More is fine but not required.

## Allowed sources (for this challenge: all MOCKED)
- For this exercise you only use the mock providers in `mocks/`. Do **not** call real APIs or scrape real sites.
- The mocks represent the *kinds* of sources we'd use in production: a business-registry lookup, a web/maps listing, and an email/phone enrichment provider. Treat each as independently fallible.

## Compliance limits (these are real, respect them in your design)
- US B2B only for this dataset. Business contact info only — never personal/home data.
- Must support opt-out / suppression and must record provenance for every value.
- Do not infer a person's identity from protected characteristics. No dark-pattern scraping.

## Success metric
- We optimize for **precision over recall**: a confident, correct, traceable contact is worth more than three guesses. A high "needs_human_review" rate on genuinely hard rows is a GOOD result, not a failure.

## Confidence threshold
- Use **70** as the cutoff: confidence `< 70` → return `contact_email_or_phone = ""` and `needs_human_review = true`.
- Your `confidence_score` logic is yours to define — just make it explainable (more independent agreeing sources = higher; single unverifiable source = lower).

## Scope
- Stay in the contact-finder scope. Do not rebuild a CRM. A minimal slice over a handful of the CSV rows is enough to show your approach.
