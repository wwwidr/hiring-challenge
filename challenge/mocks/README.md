# Mock providers

For this challenge you do **not** call real APIs or scrape real sites. Instead you query these canned fixtures, which simulate three independent (and individually fallible) data sources. Build your slice against this contract in whatever language you like.

All responses live in [`enrichment_responses.json`](enrichment_responses.json), keyed by `company_name` exactly as it appears in `data/companies.csv`.

## The three "providers"

1. **registry** — a business-registry lookup. Sometimes returns a registered agent / owner name. Often missing for tiny businesses.
2. **listing** — a web/maps business listing. May return a generic business phone and sometimes a role-less name.
3. **enrichment** — an email/phone enrichment provider. Returns a candidate email/phone with its own `provider_confidence` (0-100). Sometimes returns nothing, sometimes returns a plausible-but-weak guess.

## Response shape

```json
{
  "Cedar Ridge Plumbing LLC": {
    "registry":   { "name": "Daniel Ortega", "role": "Owner", "source_url": "mock://registry/ne/cedar-ridge-plumbing" },
    "listing":    { "name": null, "phone": "+1-402-555-0148", "source_url": "mock://listing/cedar-ridge-plumbing" },
    "enrichment": { "email": "d.ortega@cedarridgeplumbing.com", "phone": null, "provider_confidence": 82, "source_url": "mock://enrichment/cedar-ridge-plumbing" }
  }
}
```

Rules the mocks enforce (handle them):
- A provider can return `null` fields or be **entirely absent** for a company (key missing) → that is a "not found" from that source.
- `enrichment.provider_confidence` is the provider's self-reported confidence. It is NOT your final `confidence_score` — combine signals yourself.
- Some companies have **only one** source, some have **none** (intentional "cannot-verify" rows), some have **agreeing** sources (should score high), some have a single weak `enrichment` guess (should score low → `needs_human_review`).
- `source_url` values are `mock://...` strings. Carry them through as provenance.

## What good looks like
- You cross-reference providers (agreement raises confidence).
- You never emit a contact you cannot attribute to at least one `source_url`.
- Rows below the confidence threshold come back with `needs_human_review = true` and an empty contact, not a fabricated one.
