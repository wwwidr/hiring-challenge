# TICKET-004: Contact Finder — Zero-Budget Creativity Test

## The problem

One of our enterprise clients (a global logistics company) just onboarded with ~1,000 unpaid small-business accounts. For every account we have **only**:
- `company_name`
- `mailing_address`

No owner name, no email, no phone. We still need to reach the **right decision-maker** (owner, CFO, AP manager, office manager) to drive payment. Many of these owners are not on LinkedIn and their business has no real web presence.

**A single source will never cut it.** That's what we're testing.

## The ticket (hard constraints)

- **30 minutes max.** Including reading this, thinking, coding, and recording.
- **Zero paid APIs.** No Apollo, no Hunter paid plan, no RocketReach paid, no Clearbit. Free tiers only if they truly don't require a credit card. Use whatever LLM you already pay for personally (Claude, ChatGPT). **No budget from you.**
- **Stay in the contact-finder scope.** Don't rebuild the world.

Pick **3 realistic US SMB seeds** (plumbing, auto-repair, print shop, small clinic, roofing — whatever). Make up 3 rows of `(company_name, mailing_address)`. For each row, return:

- `contact_name`
- `contact_role`
- `contact_email_or_phone`
- `confidence_score` (0-100, your own scoring logic)
- `source_urls` (pipe-separated — every value must be traceable to a real public URL you can show us)

Rows with confidence `< 70` should be returned with `contact_email_or_phone=""` and `needs_human_review=true`.

## What we actually evaluate

- **Source diversity.** How many independent free sources did you combine? Google, Maps listings, state business registries (most are free), Yellow Pages, BBB, Yelp, Instagram/Facebook business pages, WHOIS on the domain, SEC EDGAR (for larger ones), Crunchbase free preview, free-tier Hunter (25/mo), public LinkedIn search without API — anything free and reproducible.
- **Your confidence logic.** How do you decide 70 vs 80 vs 90?
- **How you handle the hard rows.** The one where nothing comes back — what's your fallback?

## Deliverables

Private repo with `wwwidr` added as collaborator. Follow the root `README.md` for recording + ABOUT.md rules.

Ship a short `NOTES.md`:
1. Your pipeline in 5 bullet points.
2. Why these free sources and not others.
3. What you'd add in the next 30 minutes if given them.
4. Your `confidence_score` formula.

Creative pipelines with 3+ free sources cross-referenced beat any single-API script. That's the whole signal.
