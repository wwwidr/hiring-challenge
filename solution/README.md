# Contact Finder — Stage B Solution

## Overview

A minimal, honest contact-enrichment pipeline that takes `data/companies.csv` and uses three **mocked** data providers (registry, listing, enrichment) to find the best decision-maker contact for each company.

**Key principle (from CLARIFICATIONS.md):** Precision over recall. A high `needs_human_review` rate on genuinely hard rows is a **good** result.

## Quick Start

```bash
cd solution

# Run the finder
node src/index.js

# Run tests
node --test tests/resolver.test.js
```

No external dependencies — uses only Node.js built-in modules.

## Architecture

```
companies.csv → [CSV Parser] → [Provider Lookup] → [Resolver] → results.json
                                      ↓
                            enrichment_responses.json
                            (registry, listing, enrichment)
```

| Component | File | Responsibility |
|-----------|------|----------------|
| CSV Parser | `src/csv.js` | Reads & parses `companies.csv` (handles quoted fields) |
| Provider Layer | `src/providers.js` | Loads mock data, exposes per-company lookup |
| Resolver | `src/resolver.js` | Cross-references sources, computes confidence, handles "cannot verify" |
| Entry Point | `src/index.js` | Orchestrates the pipeline, produces `output/results.json` |
| Tests | `tests/resolver.test.js` | Unit tests for resolver logic (node:test) |

## Adaptations from CLARIFICATIONS.md

| Clarification | How it's reflected in code |
|---------------|---------------------------|
| **Confidence threshold = 70** | `CONFIDENCE_THRESHOLD = 70` in resolver.js — scores below emit `needs_human_review = true` and blank contact |
| **Role priority: AP > Owner > CFO > Manager** | `ROLE_PRIORITY` array in resolver.js drives name selection |
| **Precision > recall** | Low-confidence enrichment-only results are penalized; single "Registered Agent" is flagged for review |
| **One contact per company is enough** | Resolver returns the single best candidate |
| **Provenance required** | Every result carries `source` attribution + detailed `provenance` array with `source_url` |
| **US B2B only, no personal data** | Only business registry/listing/enrichment sources used; no personal social scraping |

## Confidence Score Logic (explainable)

The score (0–100) is built from independent signals:

| Signal | Points |
|--------|--------|
| Number of providers returning data (max 3) | up to **40** |
| Named contact found | **15** |
| Role is a decision-maker (Owner, AP, CFO) | **10** |
| Multiple sources agree on name | **15** |
| Contact channel (email/phone) found | **10** |
| Phone numbers match across sources | **5** |
| Enrichment provider's self-confidence (scaled) | up to **15** |

**Penalties (hard caps):**
- Single enrichment-only source with provider_confidence < 60 → capped at 45
- Contact info without any name → capped at 55
- Sole "Registered Agent" → capped at 55

## Sample Output

```
✅ FOUND   Cedar Ridge Plumbing LLC
           Name:  Daniel Ortega
           Role:  Owner
           Contact: d.ortega@cedarridgeplumbing.com
           Score:   97/100
           Source:  registry, listing, enrichment

🔍 REVIEW  Summit Pest Control
           Score:   25/100 (below threshold 70)
           Partial data from: enrichment

🔍 REVIEW  Redwood Cabinetry
           Score:   0/100 (below threshold 70)
           No data found from any provider.
```
