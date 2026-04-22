# TICKET-004: Contact Finder — Enterprise Collections

## Context

One of our enterprise clients (a global logistics company) just onboarded with ~1,000 unpaid small-business accounts. For every account we have **only**:
- `company_name` (string)
- `mailing_address` (full US postal address)

No owner name, no email, no phone number. We still need to reach the **right decision-maker** (owner, CFO, AP manager, office manager) to drive payment.

Apollo / LinkedIn coverage alone is weak here — many owners of these SMBs are not on LinkedIn, and their business has no meaningful web presence beyond a listing page. A single source will not cut it.

## The ticket

Build an agent that takes `(company_name, mailing_address)` rows and returns an enriched row with:

- `contact_name`
- `contact_role`
- `contact_email` (primary)
- `contact_phone` (optional)
- `confidence_score` (0–100, your own scoring logic)
- `source_urls` (pipe-separated — every email/phone must be traceable to a real URL)

Rows with confidence `< 70` should be returned with `contact_email=""` and `needs_human_review=true`.

## Rules

- **~20 minutes of your time.** We already have a production solution — we are evaluating *your approach*, not output volume. 5 rows is enough.
- **No fake data.** Every email/phone must be traceable to a real source URL.
- **Use any tools you want.** Apollo, Hunter, Serper, Tavily, Firecrawl, LLM reasoning, Playwright, anything.
- **Ship a short `NOTES.md`** explaining: your pipeline, why those providers, what you'd do differently with more time, and your `confidence_score` logic.

## Data

Generate 5 realistic SMB-style seeds yourself (US small businesses — plumbing, print shop, roofing, auto repair, small clinic, etc.). Use `company_name + mailing_address` only. No cheating by picking companies whose owner you already know.

## How to submit

Follow the root `README.md` rules:
- Private repo with `wwwidr` added as collaborator
- Screen recording with **audio narration** of the whole session
- `ABOUT.md` filled with substance
- Reply to JB's email thread with both links

What we look at most: the *diversity* of sources you use, how you score confidence, and how you reason under uncertainty. A creative pipeline with 3 providers cross-referenced beats a single-API script every time.
