# PLAN.md

## Architecture
Goal: transform each input row (`company_name`, `mailing_address`) into one best outreach contact plus transparent evidence and risk state.

### 1 Deterministic pipeline contract
Input (required):
- `company_name` (string, non-empty)
- `mailing_address` (string, non-empty)

Output per input row:
- `contact_name` (string or null)
- `contact_role` (one of: `owner`, `cfo`, `ap_manager`, `office_manager`, `other`, or null)
- `contact_email_or_phone` (string or null)
- `confidence_score` (integer 0-100)
- `source` (array of provider IDs used in final selection)
- `needs_human_review` (boolean)
- `status` (one of: `verified`, `cannot_verify`, `error_transient`)
- `cannot_verify_reason` (nullable enum, defined below)
- `provenance_id` (reference to full evidence bundle)

### 2 Core components and responsibilities
1. `InputReader`
   - Reads CSV and validates required columns.
   - Rejects malformed rows to an `input_error` report.
2. `Normalizer`
   - Canonicalizes company name (case-fold, strip punctuation/suffix noise).
   - Parses address into street/city/state/postal/country tokens.
3. `CompanyIdentityResolver`
   - Computes `company_identity_key` from normalized name + postal geography.
   - Resolves domain hints only when evidence is explicit.
4. `SourceOrchestrator`
   - Calls provider adapters in parallel with per-provider timeout/retry policy.
   - Produces raw evidence records only (no scoring here).
5. `CandidateExtractor`
   - Converts provider payloads into normalized candidate records.
6. `CandidateResolver`
   - Dedupe and merge candidates across providers.
   - Applies conflict detection flags.
7. `ScoringEngine`
   - Applies deterministic weighted scoring + hard gates.
8. `DecisionEngine`
   - Selects top candidate or returns `cannot_verify` state.
9. `OutputWriter`
   - Emits final result row and immutable provenance bundle.

### 3 End-to-end flow
`read -> validate -> normalize -> resolve company identity -> query providers -> extract candidates -> dedupe/merge -> score -> choose top candidate -> emit output + provenance`

### 4 Runtime modes (same business logic, different orchestration)
1. Standard mode (small/medium batches)
   - Single orchestrator process handles rows end-to-end.
   - Concurrency: fixed worker pool (for example 10-50 rows in parallel).
   - Best when volume is manageable and operational simplicity is preferred.

2. High-scale mode (thousands+ rows/documents)
   - Event-driven orchestration (for example SNS topic + SQS stage queues).
   - Recommended stages:
     - `company-record-received` (topic)
     - `identity-resolution-queue`
     - `provider-enrichment-queue`
     - `candidate-resolution-queue`
     - `scoring-output-queue`
   - Each queue has DLQ and redrive policy.
   - Message envelope includes: `row_id`, `idempotency_key`, `correlation_id`, `attempt`.
   - Consumers scale independently by queue depth and provider rate limits.

### 5 Reliability rules
- Idempotency key: SHA-256 of normalized input row.
- Retry policy (provider calls): max 3 attempts, exponential backoff (1s, 4s, 10s), then mark provider as unavailable for this row.
- Row terminal states:
  - `verified`: top candidate passed hard gates and threshold.
  - `cannot_verify`: no candidate satisfies minimum evidence policy.
  - `error_transient`: system-level failure after retries (retryable by scheduler).
- Never drop a row silently. Every row must end in a terminal state with reason metadata.

## Sources & strategy
### Source categories
1. Company identity signals
   - Official website contact/about pages, business directory records, domain ownership/association metadata.
2. Person-role signals
   - Leadership/staff records, business profiles, role directories.
3. Contact reachability signals
   - Email/phone validation providers and recency indicators.

### Source acceptance policy
- A candidate can only be scored if company identity evidence exists.
- High-confidence output requires at least two independent source categories.
- Single-source candidates may be returned only with review flags (never auto-usable).

### Provider adapter contract
Each adapter must output:
- `provider_id`
- `retrieved_at`
- `raw_record_id`
- `company_match_fields`
- `person_fields`
- `contact_fields`
- `freshness_hint`

### Known failure modes and mitigation
- Stale roles: apply recency penalty and require corroboration.
- Company name collisions: require address-level agreement before acceptance.
- Generic contacts (`info@`, call centers): role relevance penalty.
- Sparse data: return `cannot_verify` with explicit reason.

## Quality
### Dedupe and merge policy
Canonical fields:
- `company_canonical`
- `person_name_canonical`
- `role_bucket`
- `contact_value_canonical`

Candidate merge key:
- Exact key: `(company_canonical, person_name_canonical, role_bucket)`
- Secondary merge condition: normalized name similarity >= 0.92 and same company/address region.

Conflict flags:
- `conflict_role`
- `conflict_contact`
- `conflict_company`

### Confidence scoring (deterministic)
Score formula:

$$
score = identity + role + contact + corroboration - penalties
$$

Component ranges:
- `identity` in [0, 30]
- `role` in [0, 25]
- `contact` in [0, 25]
- `corroboration` in [0, 20]
- `penalties` in [0, 40]

Hard gates (must pass all for `verified`):
1. Company identity evidence present and non-conflicting.
2. Contact value exists (email or phone) and passes basic validity check.
3. Final score >= threshold.

Default threshold (until clarified):
- `verified` if score >= 80 and hard gates pass.
- `needs_human_review=true` if 60 <= score < 80.
- `cannot_verify` if score < 60 or hard gate fails.

Role relevance points (default):
- `owner`: 25
- `cfo`: 23
- `ap_manager`: 22
- `office_manager`: 18
- `other`: 10

Penalty examples:
- Free-domain email with no company tie: -15
- Role conflict across providers: -10
- Contact conflict across providers: -10
- Stale evidence: -5 to -15 (age-dependent)

### Cannot-verify reason taxonomy
Allowed values:
- `no_company_match`
- `no_person_found`
- `no_contact_found`
- `conflicting_evidence`
- `low_confidence`
- `provider_unavailable`

### Provenance requirements
For every output field, persist:
- source provider(s)
- source record ID(s)
- retrieved timestamp(s)
- transformation rule version
- scoring version and component breakdown

This enables full replay and audit of every decision.

## Privacy / compliance
### What this system will do
- Use only approved business-contact sources and mocked providers for challenge implementation.
- Store minimum required data for outreach operations and auditability.
- Enforce data retention and access controls on evidence payloads.
- Honor suppression and internal do-not-contact constraints.

### What this system will not do
- No personal social scraping.
- No data sources with unclear lawful basis or provenance.
- No sensitive attribute inference.
- No fabricated precision when evidence is weak.

### Compliance guardrails in design
- Every contact decision is traceable to evidence.
- `cannot_verify` is a first-class valid output, not an error.
- Human review is mandatory for low-confidence outputs.

## Clarifying questions
1. **Question:** What is the exact confidence threshold and action policy (`auto-send`, `queue-for-review`, `do-not-contact`) by band?
   - Why it matters: Determines both scoring calibration and `needs_human_review` behavior.
   - Default assumption: Threshold is 80; below threshold routes to human review, not automatic outreach.
   - What changes if answered: I will tune score weights/bands and output-state mapping to match your operational SLA.

2. **Question:** What source categories are explicitly allowed/prohibited for this client and jurisdiction?
   - Why it matters: Source policy constrains retrieval adapters, legal risk, and achievable coverage.
   - Default assumption: Only approved business-data providers and public business contact pages; no personal/social scraping.
   - What changes if answered: I will enable/disable provider adapters and adjust expected recall plus review load.

3. **Question:** What is the primary success metric: precision of verified contacts, coverage across accounts, or outreach conversion?
   - Why it matters: Trade-off decisions differ: precision-first suppresses risky candidates; coverage-first accepts more review traffic.
   - Default assumption: Precision-first (minimize false positives even at lower coverage).
   - What changes if answered: I will rebalance scoring penalties, candidate selection, and fallback behavior (`cannot_verify` rate vs contact yield).

## Acceptance criteria for Stage B implementation
- For every input row, output exactly one final record.
- Every record includes `confidence_score`, `needs_human_review`, `source`, and provenance reference.
- All low-confidence or unresolved rows are explicitly marked for human review.
- No row ends without a terminal status and reason.
- Business invariant: system prefers `cannot_verify` over risky guesses.