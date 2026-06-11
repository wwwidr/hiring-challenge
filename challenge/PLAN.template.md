# PLAN.md (commit this BEFORE reading CLARIFICATIONS.md or writing solution code)

> Delete the prompts below and replace with your own. Keep it tight.

## Architecture

I propose a modular, pipeline-based architecture implemented as a processing engine to ensure strict data provenance, parallel processing, and clear handling of unverified states.

- **Ingestion Layer**: Reads data/companies.csv, cleanses and normalizes company names (e.g., stripping "LLC", "Inc.") and sanitizes mailing addresses.

- **Enrichment Orchestrator** : Dispatches parallel asynchronous requests to multiple discovery provider APIs (simulated via mocks). It handles timeouts gracefully and tags every piece of fetched data with its exact origin.
- **Aggregation & Deduplication Layer** : Merges overlapping data payloads returned for the same company. It groups contacts by identified personas (e.g., Owner, CFO).
- **Scoring & Evaluation Engine**: Runs a heuristic rule engine over the aggregated data to calculate a $0\text{--}100$ confidence score and determines if the row bypasses or requires human review.
- **Output Generation**: Writes the final structured results back to a CSV.

## Sources & strategy

To maximize coverage, the strategy combines three distinct types of data providers, relying on multi-source corroboration to mitigate individual source failures.

### Firmographic Data / Corporate Registries

- Why we use it: Provides the highest authority on legal entity registration, official addresses, and primary executive names (Owners/CFOs).
- How it fails: Often outdated. Small or newly formed businesses might not be updated in the database, or the registered agent listed might be a third-party lawyer rather than an internal decision-maker.

### B2B Professional Networks & Social Graph Data

- Why we use it: Excellent for real-time tracking of current employee roles, titles (AP Managers, Office Managers), and employment status.

- How it fails: Prone to stale data (users don't update profiles immediately after leaving a job), completely missing data for very traditional small businesses, and name ambiguity errors (e.g., finding a contact for "John Smith" at a common company name).

### Contact Enrichment & Verification APIs

- Why we use it: Takes names/domains and provides direct B2B email addresses and phone numbers.

- How it fails: High false-positive rates due to catch-all email domains or legacy databases containing inactive phone numbers. It often guesses email formats based on common patterns (e.g., firstname.lastname@company.com) without active verification.

## Quality

**Dedupe Approach**: Entities are resolved using normalized company names and normalized mailing addresses. If multiple sources return the same contact person, their contact details are merged into a single profile. If distinct sources return different people for the same role, both are preserved initially, and the one with the higher individual source confidence is bubbled up.

**Confidence Score Logic:**

- Base Score: Dictated by the source type (Firmographic direct match = 50, Professional Network match = 40).

- Corroboration Bonus: +25 if two entirely independent sources return the exact same contact name/role combination.

- Contact Quality Bonus: +25 if the email or phone passes active validation formatting.

**Provenance**: Every output record maintains a traceable metadata array (e.g., source: ["registry_mock", "social_mock"]) detailing exactly which system provided what attribute.

**Representing "Cannot Verify"**: Unverifiable fields are left strictly empty/null. The confidence_score will reflect a low value (or 0 if entirely untouched), and needs_human_review is set to true.

**False-Positive Risk**: Sending collection or payment notices to the wrong individual introduces high legal liability and brand damage. The system mitigates this by aggressively penalizing uncorroborated, single-source matches, choosing to route a record to a human reviewer rather than risking a blind guess.

## Privacy / compliance

**What We WILL do**: Limit all data extraction exclusively to corporate/business identities (B2B). Keep all processing data local to the runtime container without external logging of sensitive payload data.

**What We WILL NOT do**: We will not attempt to scrape or look up personal/private contact vectors (personal phone numbers, private Gmail/Yahoo addresses). We will not perform continuous credential guessing against mail servers.

## Clarifying questions

**1- Question**: What is the exact baseline Confidence Score threshold required to bypass human review?

**Why it matters**: It sets the mathematical boundary line between fully automated operations and manual operational costs.

**Default assumption**: In the absence of a response, I will assume a strict threshold of 70/100, optimizing heavily for data accuracy over automation volume.

**What changes if answered**: A lower threshold permits a more lenient scoring engine. A higher threshold turns cross-source corroboration from an optional bonus into a mandatory requirement.

**2- Question**: In terms of business impact, which error is more costly: a False Positive (contacting the wrong person) or a False Negative (failing to find a contact)?

**Why it matters**: This directly dictates our scoring weights. If a false positive results in compliance penalties for the logistics firm, we must be incredibly conservative. If a false negative means permanent lost revenue, we should expose lower-confidence leads.

**Default assumption**: False Positives are highly damaging given this involves driving payments (collections), so data accuracy takes precedence over match volume.

**What changes if answered**: If False Positives are worse, scoring penalties for uncorroborated data will increase sharply. If False Negatives are worse, we will lower the human review barrier.

**3- Question**: Is there a strict priority hierarchy among the target roles (Owner, CFO, AP Manager, Office Manager)?

**Why it matters**: If multiple viable contacts are discovered for a single company, the system needs deterministic instructions on which contact to surface as the primary decision-maker.

**Default assumption**: The priority hierarchy is: CFO > AP Manager > Owner > Office Manager for driving financial transactions and payments.

**What changes if answered**: The aggregation layer will sort multiple discovered contacts by this role-priority matrix, ensuring the highest-ranking role is returned as the primary contact row.
