# Contact Finder — Stage B slice

A minimal, precision-first slice that takes the company CSV and returns one
verifiable decision-maker contact per company (or an honest "cannot verify"),
running entirely against the mocked providers. It implements the approach in
[`PLAN.md`](PLAN.md), adapted to [`challenge/CLARIFICATIONS.md`](challenge/CLARIFICATIONS.md).

Built as a Laravel 12 artisan command with a self-contained module under
[`app/Modules/ContactFinder`](app/Modules/ContactFinder). No database, no real
network calls.

## Run it

```bash
# defaults to challenge/data/companies.csv + challenge/mocks/enrichment_responses.json
php artisan contacts:find

# options
php artisan contacts:find \
  --input=challenge/data/companies.csv \
  --mocks=challenge/mocks/enrichment_responses.json \
  --out=storage/app/contacts.json \
  --csv=storage/app/contacts.csv \
  --suppress=storage/app/suppression.txt \
  --threshold=70 \
  --limit=10
```

A committed example of the full 30-row output lives in
[`samples/contacts.sample.json`](samples/contacts.sample.json) and
[`samples/contacts.sample.csv`](samples/contacts.sample.csv).

## Output (per row)

The contracted fields plus provenance and an explainable rationale:

`contact_name`, `contact_role`, `contact_email_or_phone`, `confidence_score`
(0-100), `source` (which mock provider(s)), `needs_human_review`, and —
additionally — `source_urls` (every value is traceable) and `rationale` (the
per-rule score breakdown).

## Architecture

```
CSV -> Normalize -> Providers (registry, listing, enrichment)
    -> EntityResolver (agreement vs conflict) -> ConfidenceScorer -> threshold
    -> ContactResult (JSON + CSV + console), each value carrying a source_url
```

- `Providers/*` — uniform, individually fallible adapters over the mock JSON; a
  missing key or null field is a "not found", never an exception.
- `Support/*` — `NameNormalizer` (nicknames `Bob<->Robert`, initials
  `S. Murphy<->Sean Murphy`, titles, `(manager)` tags), `EmailNormalizer`
  (generic-mailbox detection), `PhoneNormalizer` (E.164 compare),
  `RoleClassifier` (persona priority).
- `Resolution/EntityResolver` — clusters signals into one identity, detects
  cross-source agreement vs genuine conflict, picks the best reachable channel.
- `Scoring/ConfidenceScorer` — the additive, explainable score.
- `ContactFinderService` — orchestrates and applies the threshold.

## Confidence scoring (precision-first, every point explained)

- Identity: registry name +40; listing name +25 (full) / +15 (partial);
  cross-source agreement on the same person +20.
- Role fit (persona priority): accounts payable +15; owner/president +12;
  CFO/finance +12; office manager +8; registered-agent-only +2.
- Contactability: personal email +15; generic mailbox +5; email matches the
  resolved name +8; phone +5, or +10 when the same number is corroborated by
  two sources.
- Enrichment `provider_confidence`: secondary — counts (scaled, max +12) only
  when another source corroborates; a lone guess adds 0.
- Precision guards: name conflict -> cap 40 and force review; single source ->
  cap 60; no named decision-maker -> cap 45. Clamped 0-100.

Channel preference when emitting: personal email > corroborated phone > single
phone > generic mailbox. We never emit a value without a `source_url`.

## How it adapts to the clarifications

- Threshold **70**: below it (or on a conflict) -> `contact_email_or_phone = ""`
  and `needs_human_review = true`, never a fabricated contact.
- Persona priority **AP > owner > CFO > office manager**, registered agent weak
  (`RoleClassifier`). My PLAN.md assumed owner-first; this was adjusted.
- **Precision over recall**: the sample run yields 8 confident contacts and 22
  flagged for review (12 of which have no source data at all) — a high review
  rate on genuinely hard rows, which the clarifications call a good result.
- Provenance on every value; opt-out via `--suppress`.

## Compliance

US B2B, business-contact data only (from the mocks). Opt-out / do-not-contact
is honored, every emitted value is attributable to a `source_url`, and nothing
is fabricated. No real scraping or API calls.

## Tests

```bash
php artisan config:clear && php artisan test --parallel
```

Unit tests cover the normalizers, role classifier, entity resolver, and scorer;
a feature test runs the command end-to-end against the real mock JSON and
asserts threshold behavior, provenance, and that low/missing rows are flagged
without fabrication.
