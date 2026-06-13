# TICKET-006: Contact Finder — Stage B (Clarify + Build)

## The problem

Following Stage A (the plan in `PLAN.md`, see TICKET-005), build a **minimal working slice** of the Contact Finder against the **mocked providers** in `challenge/mocks/`.

We have ~1,000 unpaid small-business accounts with only `company_name` + `mailing_address`. We need to surface the **right decision-maker** to drive payment, combining multiple independently-fallible sources, and be honest when a contact **cannot be verified**. The build must **follow the plan** and **adapt** to the answers in `challenge/CLARIFICATIONS.md`.

## The ticket (hard constraints)

- **Read `challenge/CLARIFICATIONS.md` first** (only after `PLAN.md` is committed) and let it shape the build.
- **Use the mocks only.** Query `challenge/mocks/enrichment_responses.json` via the three providers — `registry`, `listing`, `enrichment`. **No real APIs, no scraping.**
- **Precision over recall.** A confident, correct, traceable contact beats three guesses. A high `needs_human_review` rate on genuinely hard rows is a GOOD result.
- **Stay in scope.** A slice over a handful of CSV rows is enough — do not rebuild a CRM.

## Requirements

Per input row, output:

- `contact_name`
- `contact_role`
- `contact_email_or_phone`
- `confidence_score` (0-100, your own explainable logic)
- `source` — which mock provider(s) the value came from (carry `source_url` through as provenance)
- `needs_human_review` (`true` when confidence is below threshold or the row cannot be verified)

Behavior the mocks force you to handle:

- **Confidence threshold = 70** (per `CLARIFICATIONS.md`): `< 70` → `contact_email_or_phone = ""` and `needs_human_review = true`. Never fabricate a contact.
- **Contact priority**: AP / accounts-payable → owner / founder → CFO / finance lead → office manager fallback.
- **Cross-reference providers** — agreeing sources raise confidence; a single weak `enrichment` guess scores low. `enrichment.provider_confidence` is an input signal, NOT the final score.
- A provider may return `null` fields or be **entirely absent** (key missing) → "not found" from that source. Some companies have one source, some have none.
- **Provenance is mandatory** — never emit a value you cannot attribute to at least one `source_url`.

## Compliance (respect in the design)

- US B2B only; business contact info only — never personal/home data.
- Support opt-out / suppression; record provenance for every value.
- No identity inference from protected characteristics; no dark-pattern scraping.

## Files to Create/Modify

- `app/Modules/ContactFinder/Contracts/ContactProvider.php` — single provider contract.
- `app/Modules/ContactFinder/Providers/{Registry,Listing,Enrichment}Provider.php` — one adapter per mock source.
- `app/Modules/ContactFinder/DTOs/{ProviderResult,ResolvedContact,ScoreBreakdown}.php`
- `app/Modules/ContactFinder/Services/{ContactResolver,ConfidenceScorer,NameMatcher}.php` — resolve, score, match.
- `app/Modules/ContactFinder/Support/{MockDataLoader,ContactResolverFactory}.php`
- `app/Console/Commands/FindContactsCommand.php` — run the slice over the CSV.
- Tests: `tests/Unit/` (scoring, name matching, resolution edge cases) and `tests/Feature/` (end-to-end over sample rows incl. not-found / low-confidence).

## What we actually evaluate

- **Adaptation** — does the build follow `PLAN.md` and use the clarifications (threshold, contact priority, precision-over-recall)?
- **Slice quality** — clean provider abstraction, explainable confidence scoring, structural provenance.
- **"Cannot-verify" handling** — low-confidence and no-source rows come back with `needs_human_review = true` and an empty contact, never a fabricated one.

## Before submitting

- `php artisan config:clear && php artisan test --parallel`
- Verify the slice handles the agreeing-sources, single-weak-source, and zero-source mock rows correctly.
