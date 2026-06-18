# Contact Finder

A minimal, precision-first slice that takes a CSV of US small businesses
(`company_name`, `mailing_address`), queries three mocked data sources, cross-references
them, scores confidence, applies a suppression/opt-out gate, and emits **one contact row
per company**. It optimizes for precision over recall: when it cannot verify a
decision-maker contact, it returns an empty contact flagged `needs_human_review` rather
than guessing.

## Run it

From this `contact-finder/` directory:

```bash
npm install        # install dependencies
npm run dev        # run the pipeline over the test CSV, print a table + summary
npm run serve      # start the HTTP server (GET /contacts, add ?debug=1 for score breakdowns)
npm test           # run the vitest suite (74 tests)
npm run typecheck  # tsc --noEmit
```

The pipeline reads the challenge fixtures directly:
`../challenge/data/companies.csv`, `../challenge/mocks/enrichment_responses.json`, and
`../challenge/mocks/suppression.json`.

## Sample output

```
Company                         Role              Contact                           Score  Status
---------------------------------------------------------------------------------------------------------
Ironclad Welding Shop           Owner             +1-412-555-0184                      98  OK
Greenfield Catering Group       Owner             a.brooks@greenfieldcater.com         72  OK
Bayview Auto Repair             Owner             —                                    67  review
Pioneer Landscaping Inc         —                 —                                    99  SUPPRESSED(co)
Redwood Cabinetry               —                 —                                     0  NOT FOUND

Summary
  30 companies — 5 confident, 25 need review (12 not found, 3 suppressed)
```

## Output fields (per company)

`contact_name`, `contact_role`, `contact_email_or_phone`, `confidence_score` (0–100),
`source` (contributing providers + their `mock://` URLs, for provenance), and
`needs_human_review`. Below the confidence threshold (70), suppressed, or not found →
empty contact + `needs_human_review = true`.

## How confidence is scored

Role-gated and explainable. A contact with no attributable decision-maker role is never
emitted confidently, however well its phone/email corroborates. Score =
`role (priority-tiered) + phone (cross-source agreement) + email (corroboration) +
relationship bonus (name↔name, or name↔email as a fallback)`, capped at 99. All weights
live in one place: [`src/config/scoring.config.ts`](src/config/scoring.config.ts).

## Layout

```
src/
  config/scoring.config.ts   weights, threshold, role tiers (single source of truth)
  types/                     shared types
  schemas/                   zod output validation (provenance + review invariants)
  lib/                       csv-parser, providers, normalize, merge, scorer, suppression
  pipeline/controller.ts     orchestration
  server/server.ts           HTTP endpoint
  index.ts                   CLI
test/                        vitest suite
```

See [`NOTES.md`](NOTES.md) for known boundaries and scope decisions.
