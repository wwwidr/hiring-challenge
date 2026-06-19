# PLAN.md

## Tech stack and runtime

The existing codebase is **Laravel (PHP)**. Since this feature will integrate with the existing system, we use the same stack:

| Layer | Choice | Why |
|---|---|---|
| Language | **PHP 8.2+ (Laravel)** | Matches existing codebase; enables Artisan commands, queued jobs, Eloquent models, and dependency injection via Laravel's service container |
| Database | **PostgreSQL 16** | Native JSONB columns for provenance data, full-text search for company name matching, `pgcrypto` for field-level encryption of regulated PII, row-level security for tenant isolation |
| Cache / Queue broker | **Redis 7** | Shared rate limiter counters, circuit breaker state, queue driver, and provider response cache (TTL-based) |
| Entry point | Artisan command | `php artisan contacts:enrich companies.csv` — fits the existing CLI workflow |
| Queue processing | Laravel Jobs + Queue | Each company enrichment is a dispatched job; supports Redis/database queue drivers with retry, backoff, and rate limiting built-in |
| HTTP client | Laravel HTTP facade (`Http::`) | Built-in retry, timeout, concurrent requests via pool, and fake/mock for testing |
| CSV parsing | `league/csv` | Battle-tested PHP CSV library with stream support |
| Logging | Laravel Log (Monolog) | Structured JSON logging, multiple channels, already configured in the project |
| Monitoring | **Laravel Telescope** (dev) / **Prometheus + Grafana** (prod) | Request tracing, queue monitoring, and custom metric dashboards |
| Testing | PHPUnit (Laravel's `php artisan test`) | Consistent with existing test suite |

**For this challenge**: we use the mocked providers in `challenge/mocks/` — no real API calls. Providers implement a PHP interface so that mock implementations are injected during testing and real API implementations are injected in production (via Laravel's service container binding).

**Runtime options** (depends on scale — see "Scaling considerations" below):

| Scenario | Runtime | How |
|---|---|---|
| One-time batch (≤ 1,000) | Artisan command | `php artisan contacts:enrich input.csv --output=results.json` |
| Recurring batches | Scheduled Artisan + Queue workers | Laravel Scheduler triggers enrichment; jobs processed by queue workers |
| High-scale (10K+ concurrent) | Queue workers + Redis rate limiter | Multiple queue workers consuming from Redis; shared rate limiter prevents API overuse |

### Database schema

Four tables, managed via Laravel migrations:

```
enrichment_batches
├── id (uuid, PK)
├── status (enum: pending, processing, completed, failed)
├── input_file_hash (string, for idempotency — reject duplicate CSV uploads)
├── total_companies (int)
├── processed_count (int)
├── started_at (timestamp)
├── completed_at (timestamp, nullable)
└── created_at / updated_at

enrichment_companies
├── id (uuid, PK)
├── batch_id (FK → enrichment_batches)
├── original_name (string)
├── original_address (text)
├── normalized_name (string, indexed)
├── fingerprint (string, indexed — for dedup)
├── country (string, 2-letter ISO code)
├── regulated_industry (enum: healthcare, finance, null)
└── created_at / updated_at

enrichment_contacts
├── id (uuid, PK)
├── company_id (FK → enrichment_companies)
├── contact_name (string — always encrypted via pgcrypto)
├── contact_role (string)
├── contact_email (string, nullable — always encrypted via pgcrypto)
├── contact_phone (string, nullable — always encrypted via pgcrypto)
├── confidence_score (int, 0–100)
├── verification_status (enum: verified, unverified, conflicting, not_found)
├── needs_human_review (boolean)
├── provenance (jsonb — full source tracking per field)
└── created_at / updated_at

enrichment_audit_logs
├── id (bigint, PK, auto-increment)
├── company_id (FK → enrichment_companies)
├── provider (string)
├── event (string: lookup, validation, access)
├── request_duration_ms (int)
├── result_status (string: found, not_found, error, rate_limited)
├── fields_returned (jsonb)
├── accessed_by (string, nullable — for regulated record reads)
├── batch_id (FK → enrichment_batches)
└── created_at
```

**Why PostgreSQL over MySQL**: JSONB columns give us native indexing on the provenance field (e.g., query "all contacts where source includes state_registry"). `pgcrypto` provides transparent field-level encryption for **all** PII contact fields (name, email, phone) — not just regulated companies — without needing application-level encrypt/decrypt on every read. Row-level security policies can enforce tenant isolation at the database layer if the system becomes multi-tenant.

**Indexes**: `enrichment_companies.fingerprint` (dedup lookups), `enrichment_companies.normalized_name` (search), `enrichment_contacts.company_id` + `confidence_score` (filtering high-confidence contacts), `enrichment_audit_logs.company_id` + `created_at` (audit trail queries).

## Architecture

The system is a pipeline with four stages: **Ingest → Resolve → Enrich → Score**.

```
CSV Input
   │
   ▼
┌──────────────────┐
│  1. INGEST        │  Parse CSV, normalize company names & addresses
│                   │  (trim whitespace, standardize abbreviations like
│                   │   LLC/Inc, normalize state/zip)
└──────────────────┘
          │
          ▼
┌──────────────────┐
│  2. RESOLVE       │  For each company, build a canonical identity:
│                   │  - Normalized name + address fingerprint
│                   │  - Deduplicate entries that refer to the same entity
└──────────────────┘
          │
          ▼
┌──────────────────┐
│  3. ENRICH        │  Dispatch queued jobs in controlled batches:
│  (multi-source)   │  - Business registries → owner / registered agent
│                   │  - Directory APIs → phone, website, basic contact
│                   │  - Enrichment APIs → decision-maker names,
│                   │    roles, emails
│                   │  Each result is tagged with its source (provenance)
│                   │  Checkpoint after each batch (resumable)
└──────────────────┘
          │
          ▼
┌──────────────────┐
│  4. SCORE &       │  Merge results per company:
│     OUTPUT        │  - Dedupe contacts across sources
│                   │  - Validate emails (business vs. personal domain)
│                   │  - Validate phone numbers (format, type, carrier)
│                   │  - Compute confidence_score per contact (formula below)
│                   │  - Derive verification_status from score + sources
│                   │  - Flag needs_human_review when below threshold
│                   │  - Emit structured output (JSON / CSV)
└──────────────────┘
```

### Key design decisions

**Modules with dependency injection (Laravel service container).** Providers implement a `ContactProviderInterface` so we can bind mock implementations for testing or swap real APIs in production without changing the pipeline logic.

```php
interface ContactProviderInterface
{
    public function getName(): string;
    public function getAuthorityWeight(): float;
    public function lookup(NormalizedCompany $company): ProviderResult;
}
```

Registered in a service provider:

```php
$this->app->tag([
    StateRegistryProvider::class,
    DirectoryApiProvider::class,
    EnrichmentApiProvider::class,
], 'contact.providers');
```

**Batched processing with per-batch checkpointing.** Companies are processed in configurable batches (e.g., 10 at a time). Within each batch, providers are queried concurrently using `Http::pool()`. Results are collected in memory for the batch, then written atomically after the entire batch completes. This avoids the race condition of parallel writes to a shared checkpoint file.

```
Batch 1: companies[0..9]   → each queries 3 providers concurrently (Http::pool)
   collect results in memory
   write all 10 results to checkpoint atomically (DB transaction or file lock)
   wait 1s (rate limiting between batches)
Batch 2: companies[10..19] → same pattern
...
```

**Why per-batch, not per-company checkpointing?** If we write to a checkpoint file after each individual company while processing companies in parallel within a batch, multiple concurrent writes could corrupt the file. Writing at batch boundaries is safe because the batch is fully resolved before any I/O happens.

**Provenance as a first-class concept.** "Provenance" means **tracking the origin of every piece of data**. When a provider returns a contact name, that name doesn't become a bare string — it becomes a value object that carries metadata about where it came from and when. This is our own design choice to ensure auditability and enable the confidence scoring.

```php
class ProvenanceField
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $source,
        public readonly string $retrievedAt,
    ) {}
}
```

Every field in the system carries this metadata from the moment it enters through a provider, through merging, and into the final output. It is never stripped.

**Idempotent and resumable via checkpoint.** After processing each batch, results are persisted (to a JSON Lines file for CLI mode, or to a database table for queue mode). On startup, the pipeline loads already-processed company IDs. If the pipeline crashes at batch 50, restarting it skips batches 1–49 and continues from 50.

```php
// On startup — load already-processed IDs
$processedIds = $this->checkpointRepository->getProcessedCompanyIds();

// Per batch — skip if all companies in batch are done
$pendingCompanies = $batch->reject(
    fn (Company $company) => $processedIds->contains($company->id)
);

// After batch completes — persist atomically
DB::transaction(function () use ($batchResults) {
    foreach ($batchResults as $result) {
        $this->checkpointRepository->save($result);
    }
});
```

### Scaling considerations

At 1,000 companies with 3 providers each, the system makes ~3,000 API calls — manageable with a single process. But what happens when multiple clients trigger contact enrichment simultaneously, or the dataset grows to 100K+ companies?

#### How rate limiting works in detail

The core problem: if two users trigger enrichment at the same time, two separate processes send API requests independently. Neither knows about the other. Both hit the same provider API key, and the provider sees double the request volume — exceeding the rate limit.

**Solution: a shared rate limiter in Redis.** Every process checks a central counter before calling any provider API. Here's the flow:

```
Worker A wants to call Apollo API
  → Checks Redis: "apollo_requests_this_minute" = 87 (limit: 100)
  → 87 < 100 → allowed. Increments counter to 88. Makes the API call.

Worker B wants to call Apollo API (at the same time)
  → Checks Redis: "apollo_requests_this_minute" = 88
  → 88 < 100 → allowed. Increments counter to 89. Makes the API call.

Worker C wants to call Apollo API (a moment later)
  → Checks Redis: "apollo_requests_this_minute" = 100
  → 100 = 100 → BLOCKED. Worker C waits.
  → After the 1-minute window resets → counter resets to 0 → Worker C proceeds.
```

In Laravel, this uses the built-in `RateLimiter` facade backed by Redis:

```php
// Before every provider API call
$executed = RateLimiter::attempt(
    key: "provider:{$provider->getName()}",
    maxAttempts: $provider->getRateLimit(),  // e.g., 100 per minute
    callback: fn () => $provider->lookup($company),
    decaySeconds: 60,
);

if (!$executed) {
    // Release the job back to the queue with a delay
    $this->release(delaySeconds: 30);
}
```

Because Redis is shared across all workers/processes, the counter is global — no matter how many workers run simultaneously, the total requests per minute never exceed the provider's limit.

#### What happens when a provider starts failing (circuit breaker)

If Apollo starts returning HTTP 429 (rate limited) or 500 (server error), we don't want every worker to keep hammering a failing API. The circuit breaker pattern prevents this:

```
CLOSED state (normal operation):
  → All requests go through.
  → Track failures. If 5 consecutive failures occur → switch to OPEN.

OPEN state (provider is down):
  → ALL requests to this provider are immediately skipped (not sent).
  → Jobs that needed this provider are retried later.
  → After a cooldown period (e.g., 60 seconds) → switch to HALF-OPEN.

HALF-OPEN state (testing recovery):
  → Allow ONE request through as a probe.
  → If it succeeds → switch back to CLOSED (provider recovered).
  → If it fails → switch back to OPEN (still down, wait longer).
```

This is stored in Redis as a state key per provider (`circuit:apollo = open|closed|half-open`) so all workers share the same circuit state.

#### Scale levels

| Scale level | Volume | Workers | Rate limiting | Special considerations |
|---|---|---|---|---|
| **Small** | ≤ 1,000, single trigger | 1 (Artisan command) | Simple sleep between batches | None needed |
| **Medium** | 1K–10K, occasional concurrent triggers | 2–4 queue workers | Redis-based shared rate limiter per provider | Circuit breaker for fault tolerance |
| **Large** | 10K+, frequent concurrent triggers | 4–8 queue workers | Rate limiter + provider-specific queues + circuit breaker | **Queue priority** (high-value accounts first). **Cost cap** (daily spend limit per provider — halt jobs when reached). |
| **Enterprise** | 100K+, multi-tenant | 8+ workers, horizontal scaling | All of the above + per-tenant fair-share quotas | **Tenant isolation** (each client gets max N requests/min to prevent one client from starving others). **API key pooling** (multiple keys per provider to increase total throughput). |

## Data sources — where the data comes from

**We do NOT scrape websites.** All data comes from structured APIs that provide business data as a service. This is legally safe because:
- Business registry data is public record.
- Directory APIs (Google Places) provide their data via paid API access with explicit terms of service.
- Enrichment APIs (Apollo, Hunter.io) aggregate publicly available business information and sell access via licensed API contracts.

**For this challenge**: we use the mocked providers in `challenge/mocks/` that simulate these API responses.

**In production**, the source types and their real-world APIs:

| Source type | Real API | What it gives | Cost per lookup |
|---|---|---|---|
| **Business registries** | OpenSOSData API (US), OpenCorporates (international) | Owner / registered agent name, entity status | $0.03 (US) / $0.10–0.50 (international) per query |
| **Business directory** | Google Places Text Search API | Phone, website URL, address verification | $0.032 per request (5K free/month) |
| **Contact enrichment** | Apollo.io API | Decision-maker name, title, email | $0.05–0.06 per lookup |
| **Email verification** | NeverBounce / MyEmailVerifier | Valid email, catch-all detection, personal vs. business domain | $0.003–0.008 per check |
| **Phone verification** | Twilio Lookup API / NumVerify | Format validation, carrier, line type (mobile/landline) | $0.005–0.02 per check |

### Why not scraping?

- **Legal risk**: Scraping violates most websites' Terms of Service. The hiQ v. LinkedIn case established some protections for public data, but scraping remains legally gray and can result in IP blocks, cease-and-desist letters, or lawsuits.
- **Fragile**: Website layouts change constantly. A scraper that works today breaks tomorrow.
- **Unnecessary**: Structured APIs exist for every data type we need. They're more reliable, return cleaner data, and come with legal coverage.

## Cost estimation (for 1,000 companies)

| Source | Queries | Unit cost | Subtotal |
|---|---|---|---|
| Business registry (OpenSOSData) | 1,000 | $0.03 | **$31** |
| Google Places Text Search (Pro) | 1,000 | $0.032 | **$32** (within 5K free/month tier) |
| Contact enrichment (Apollo Basic) | 1,000 | $0.06 | **$60** |
| Email verification (NeverBounce) | ~800 (only emails found) | $0.008 | **$6** |
| Phone verification (Twilio Lookup) | ~600 (only phones found) | $0.01 | **$6** |
| **Total API cost** | | | **~$135** |

| Infrastructure | Cost |
|---|---|
| Compute (single run, existing Laravel server) | $0 (uses existing infrastructure) |
| Queue worker (for recurring batches) | Included in existing server |
| Log storage | ~$0/month at this scale |

**Total cost per batch of 1,000 companies: ~$135 (~$0.135 per company).**

### Cost at scale

As volume increases, per-company cost decreases because: (a) providers offer volume discounts at higher tiers, (b) Google Places' free tier absorbs more of the base, and (c) infrastructure costs are amortized. However, infrastructure costs grow.

| Batch size | API cost per company | API total | Infrastructure (monthly) | Total per batch |
|---|---|---|---|---|
| **1,000** (one-time) | $0.135 | $135 | $0 (local run) | **~$135** |
| **10,000** / month | $0.095 | $950 | ~$50 (queue workers, Redis) | **~$1,000/month** |
| **50,000** / month | $0.080 | $4,000 | ~$200 (multiple workers, monitoring) | **~$4,200/month** |
| **100,000** / month | $0.070 | $7,000 | ~$500 (horizontal workers, Redis cluster, logging) | **~$7,500/month** |

Volume discount assumptions: Apollo drops from $0.06 to ~$0.04/lookup at 50K+; OpenSOSData stays flat at $0.03; Google Places free tier covers 5K/month so only the excess is billed; email/phone verification drops below $0.005 at volume.

**Cost guardrails in code**: a configurable daily spend cap per provider. If the cap is reached, remaining jobs are paused until the next day. This prevents a runaway batch or a bug from generating an unexpected bill.

## Sources & strategy

### Global strategy (not US-only)

The sample dataset contains US addresses, but the system must not assume all accounts will be US-based. A global logistics company may onboard debtors from any country. The strategy adapts per-region:

| Region | Business registry source | Directory source | Enrichment source | Compliance regime |
|---|---|---|---|---|
| **US** | OpenSOSData (all 50 states + DC) | Google Places | Apollo.io | TCPA, CAN-SPAM, CCPA |
| **EU/UK** | OpenCorporates, national registries (Companies House UK, Handelsregister DE) | Google Places | Apollo.io (limited EU data), Lusha | **GDPR** — requires lawful basis; "legitimate interest" must be documented; data subject rights apply |
| **Canada** | Corporations Canada, provincial registries | Google Places | Apollo.io | CASL (anti-spam), PIPEDA |
| **Latin America** | National registries vary (CNPJ for Brazil, etc.) | Google Places | Limited coverage | LGPD (Brazil), local laws |
| **APAC** | Varies widely | Google Places | Limited coverage | PDPA (Singapore), APP (Australia) |

**The architecture handles this through the provider interface.** Each region gets a different set of provider implementations bound to the same interface. The pipeline doesn't care which region — it calls `lookup()` on whatever providers are registered for that company's country.

```php
// Provider resolution based on company country
$providers = $this->providerRegistry->getProvidersForCountry($company->country);
```

For countries where no business registry API exists, the system relies more heavily on directory and enrichment APIs, and the confidence score will be lower (since the highest-authority source is unavailable). This is an honest "unverified" result, not a failure.

**Strategy: triangulate, don't trust any single source.**

1. Start with **business registries** (highest authority for ownership data — legally filed).
2. Layer in **directory APIs** for phone/website (highest coverage for local businesses).
3. Use **enrichment APIs** for role-specific contacts (CFO, AP manager, email addresses).
4. **Cross-validate**: if sources agree on a name → high confidence. If only one source → lower confidence, flag for review.

**What I expect to fail:** Small sole-proprietors (locksmiths, tailors, bakeries) are the hardest — they're often absent from enrichment databases and may not have websites. For these, the best we may get is a phone number from a directory listing with no named contact. That's a valid "not_found" / "unverified" outcome, not a failure.

## Quality

### Confidence scoring formula

The confidence score combines four quality dimensions using a weighted linear model. Below we first justify **why these dimensions** and **why these weights** using Bayesian reasoning and industry data, then present the formula.

#### Theoretical basis

**Bayesian justification for source agreement (why it gets the highest weight).**

Consider a claim C (e.g., "John Smith is the owner of Cedar Ridge Plumbing") reported by independent sources. Using Bayes' theorem, if source S has sensitivity `p(confirm|true)` and false-positive rate `p(confirm|false)`:

```
P(true | S confirms) = P(confirm|true) × P(true)
                       ─────────────────────────────────────
                       P(confirm|true) × P(true) + P(confirm|false) × P(false)
```

With a neutral prior P(true) = 0.5 and realistic source parameters:

| Source | Sensitivity | False-positive rate | P(true \| confirms) |
|---|---|---|---|
| State registry alone | 0.85 | 0.05 | **0.94** |
| Enrichment API alone | 0.70 | 0.10 | **0.88** |
| Directory API alone | 0.60 | 0.20 | **0.75** |

When **two independent sources** (registry + enrichment) both confirm:

```
P(true | both confirm) = P(true) × 0.85 × 0.70
                         ──────────────────────────────────────────────
                         P(true) × 0.85 × 0.70 + P(false) × 0.05 × 0.10

                       = 0.2975 / 0.3 = 0.99
```

This demonstrates mathematically that **independent agreement is the single strongest quality signal** — it raises posterior probability from 0.75–0.94 (single source) to 0.99 (two sources). This justifies giving Agreement the highest weight (0.40).

**Industry data for recency weight.**

B2B contact data decays at documented rates (sources: Marketing Sherpa, Landbase, A-Leads Research):

| Data field | Monthly decay | Annual decay |
|---|---|---|
| Overall B2B contacts | 2.1% | 22.5% |
| Email addresses | 3.6% | ~36% |
| Job titles | 5.5% | ~65% |
| Phone numbers | 3.6% | ~43% |
| Company addresses | 3.5% | ~42% |

After 12 months, ~70% of B2B contacts have at least one changed field. This justifies penalizing stale data, but recency alone doesn't determine accuracy (a fresh record from a low-quality source is still unreliable), which is why Recency gets the lowest weight (0.15).

**Information quality dimensions (ISO 25012 alignment).**

Our four dimensions map to established data quality standards:
- **Agreement** → Accuracy (ISO 25012): degree to which data correctly represents the real-world entity
- **Authority** → Credibility: trustworthiness of the data source
- **Completeness** → Completeness (ISO 25012): degree to which all required data fields are present
- **Recency** → Currentness (ISO 25012): degree to which data is up-to-date

#### Weight calibration

The weights (0.40, 0.20, 0.25, 0.15) are ordered by **information gain** — how much each dimension reduces our uncertainty about the contact's correctness:

| Dimension | Weight | Why this weight |
|---|---|---|
| Agreement | 0.40 | Strongest signal: two independent sources confirming raises P(true) from ~0.88 to ~0.99. No other single factor has this effect. |
| Completeness | 0.25 | A contact missing name or role is not actionable for outreach regardless of accuracy. Completeness gates utility. |
| Authority | 0.20 | Source reliability matters (registry vs. web scrape), but is already partially captured by Agreement (high-authority sources are more likely to agree with truth). |
| Recency | 0.15 | Real but secondary: 22.5% annual decay means ~78% of data is still valid after 12 months. Important but less decisive than agreement or completeness. |

**These weights are initial estimates.** In production, they should be calibrated against ground truth. Here is how that calibration works:

**Step 1 — Collect labeled data.** Run the pipeline on a batch of ~500 companies. Have a human reviewer verify each result as `correct_contact`, `wrong_contact`, or `outdated_contact`. This produces a labeled dataset of ~500 rows, each with the four component scores (Agreement, Authority, Completeness, Recency) and a binary label (1 = correct, 0 = incorrect).

**Step 2 — Fit a logistic regression.** The model learns which component scores best predict a correct contact:

```
P(correct) = σ(β₁ × Agreement + β₂ × Authority + β₃ × Completeness + β₄ × Recency + β₀)
```

Where σ is the sigmoid function. The learned coefficients β₁–β₄ become the new weights (after normalizing to sum to 1.0).

**Step 3 — Validate.** Split the labeled data 80/20 (train/test). Check that the calibrated weights improve precision and recall compared to the initial weights. If agreement was even more predictive than expected, its weight may increase from 0.40 to, say, 0.50. If recency barely mattered for these small businesses (which change hands less often than tech companies), its weight may drop from 0.15 to 0.05.

**Step 4 — Update and re-deploy.** The weights are stored as configuration values (not hardcoded), so updating them is a config change, not a code deployment. Re-calibrate quarterly or after every ~1,000 human-reviewed contacts.

**Minimum viable calibration**: even without logistic regression, a manual review of 50 contacts — checking which scored high but were wrong, and which scored low but were correct — reveals obvious weight adjustments. For example, if most errors come from stale registry data, Recency needs a higher weight.

#### Formula

For a contact candidate `C` found for company `K`:

**Inputs:**
- `S` = set of sources that returned this contact
- `n` = |S| (number of agreeing sources)
- `w(s)` = authority weight of source `s`

**Source authority weights:**

| Source | Weight w(s) | Basis |
|---|---|---|
| Business registry | 0.90 | Government-filed data; P(true\|confirms) = 0.94 |
| Contact enrichment API | 0.70 | Aggregated from multiple secondary sources; P(true\|confirms) = 0.88 |
| Business directory API | 0.50 | Good for phone/address; P(true\|confirms) = 0.75 |
| Web / other | 0.30 | Unstructured; no reliability guarantee |

**Component scores:**

```
Agreement(S):
  if |S| = 0  → 0.0    (no data)
  if |S| = 1  → 0.5    (single unverified source)
  if |S| ≥ 2  → 1.0    (independent corroboration)

Authority(S):
  max( w(s) for each s in S )

Completeness(C):
  required = { name, role, contact_channel }
  contact_channel = email OR phone (at least one present)
  score = |present_fields ∩ required| / |required|

  Example: name + role + email    → 3/3 = 1.00
           name + email (no role) → 2/3 = 0.67
           phone only             → 1/3 = 0.33

Recency(S):
  Based on the most recent data timestamp across sources
  < 6 months old   → 1.0   (~95% of records still valid, based on 2.1%/month decay)
  6–12 months old  → 0.7   (~85% still valid)
  > 12 months old  → 0.4   (~70% have at least one stale field)
  Unknown age      → 0.5   (conservative middle ground)
```

**Final formula:**

```
confidence = round(
  ( 0.40 × Agreement(S)
  + 0.20 × Authority(S)
  + 0.25 × Completeness(C)
  + 0.15 × Recency(S)
  ) × 100
)
```

**Worked examples:**

| Scenario | Agreement | Authority | Completeness | Recency | Score |
|---|---|---|---|---|---|
| Registry + enrichment API agree on owner, have name + role + email, fresh data | 1.0 | 0.90 | 1.0 | 1.0 | **90** |
| Single enrichment API, name + role + email, fresh | 0.5 | 0.70 | 1.0 | 1.0 | **74** |
| Single directory, name + phone (no role), unknown age | 0.5 | 0.50 | 0.67 | 0.5 | **54** |
| Single directory, phone only, unknown age | 0.5 | 0.50 | 0.33 | 0.5 | **46** |
| No sources returned data | 0.0 | 0.0 | 0.0 | 0.0 | **0** |

### Why 70 is the confidence threshold

The threshold of **70** is derived from the formula's behavior and the Bayesian posteriors:

- **Score ≥ 70 requires either**: (a) two agreeing sources with decent data (P(true) ≈ 0.99), or (b) a single high-authority source with complete fields and fresh data (P(true) ≈ 0.88–0.94). Both represent reasonable evidence for automated outreach.
- **Score < 70 means**: single source + incomplete data, or low-authority source, or stale data. P(true) drops below 0.88, making false positives too likely for automated debt collection outreach.

A single enrichment API result with full data and fresh timestamps scores exactly 74 (the minimum "passable" single-source case). Remove any one factor — drop the role, or the data is stale — and it falls below 70. This creates a natural boundary.

For a debt collection context, contacting the wrong person is worse than not contacting anyone (reputation damage, potential compliance violations), so a threshold that errs toward human review is correct.

### Verification status (derived from confidence + source count)

`verification_status` is **not a separate input** — it is computed from the confidence score and source data:

```
if no sources returned data:
    verification_status = "not_found"
    needs_human_review  = true

else if sources disagree on name or role for the same company:
    verification_status = "conflicting"
    needs_human_review  = true

else if confidence ≥ 70 AND |sources| ≥ 2:
    verification_status = "verified"
    needs_human_review  = false

else:
    verification_status = "unverified"
    needs_human_review  = true
```

**The relationship is one-directional**: confidence_score + source metadata → verification_status → needs_human_review. The verification_status is a human-readable label that summarizes the confidence analysis.

### Provenance

Every field in the output carries a `sources` array so a human reviewer can trace exactly where each piece of data came from:

```json
{
  "company_name": "Cedar Ridge Plumbing LLC",
  "contact_name": "John Smith",
  "contact_name_sources": ["state_registry", "enrichment_api"],
  "contact_role": "Owner",
  "contact_role_sources": ["state_registry"],
  "contact_email": "john@cedarridgeplumbing.com",
  "contact_email_sources": ["enrichment_api"],
  "contact_phone": "(402) 555-0173",
  "contact_phone_sources": ["directory_api"],
  "confidence_score": 87,
  "verification_status": "verified",
  "needs_human_review": false,
  "source": "state_registry,enrichment_api,directory_api"
}
```

### Deduplication

**Company-level deduplication** (before enrichment):

1. Normalize the company name: lowercase, strip legal suffixes (LLC, Inc, Co, Corp, Ltd, GmbH, S.A., etc.), remove punctuation, collapse whitespace.
   - `"Cedar Ridge Plumbing LLC"` → `"cedar ridge plumbing"`
   - `"Cedar Ridge Plumbing, L.L.C."` → `"cedar ridge plumbing"`
2. Normalize the address: standardize abbreviations (`St` → `Street`, `Ave` → `Avenue`, `Rd` → `Road`), extract city + state/region + postal code.
3. Generate a fingerprint: `hash(normalized_name + city + state_or_region)`.
4. If two rows share the same fingerprint, flag as potential duplicate for manual review. Do NOT auto-merge — false deduplication (merging two different businesses with similar names) is worse than a duplicate record.

**Contact-level deduplication** (after enrichment, within a single company):

1. Two contacts match if: fuzzy name similarity > 85% (Levenshtein on full name) AND same company.
2. Handle initial expansion: `"J. Smith"` matches `"John Smith"` by expanding initials against known first names from other sources.
3. If roles differ for the same person (`"Owner"` vs. `"President"`), keep both roles — they're both valid.

### Contact field validation

Before outputting any contact, we validate both email and phone fields:

**Email validation:**

1. **Domain check**: reject emails from known personal email providers (gmail.com, yahoo.com, outlook.com, hotmail.com, aol.com, etc.). These are personal emails and using them for debt collection outreach violates our compliance rules.
2. **Deliverability check**: use an email verification API to confirm the email is deliverable (not bounced, not a catch-all domain).
3. **Pattern check**: emails like `info@`, `contact@`, `hello@`, `support@` are generic — they don't identify a decision-maker. These score lower on completeness (no named person behind them).

**Phone validation:**

1. **Format validation**: parse phone number using a library (libphonenumber) to confirm it's a valid format for the company's country. Normalize to E.164 international format (e.g., `+14025550173`).
2. **Line type detection**: determine if the number is mobile, landline, or VoIP. This matters for TCPA compliance — autodialed calls to mobile numbers require prior express consent.
3. **Active check**: use a carrier lookup API to verify the number is currently active and not disconnected.
4. **Business vs. personal**: if the phone number is associated with a residential listing rather than a business listing (detectable via carrier data), flag it similarly to personal emails.

### False-positive risk

The biggest risks for this dataset:
- **Stale ownership**: Small businesses change hands frequently. A 2-year-old registry entry may list the previous owner.
- **Role inflation**: Enrichment APIs sometimes label anyone they find as "Owner" even if they're an employee.
- **Wrong company match**: "Maple Leaf Bakery" could match the wrong business in another city or country. The mailing address is the tiebreaker — always validate that the returned address matches or is close to the input address.
- **Generic contacts**: Returning `info@company.com` or a front-desk phone number when we need a decision-maker. These should score low on completeness and be flagged.

## Audit logging

Every provider call is logged as a structured JSON entry via Laravel's Log facade. This creates an audit trail for compliance and debugging.

**What we log:**

```json
{
  "timestamp": "2026-06-17T12:00:00.000Z",
  "level": "info",
  "event": "provider_lookup",
  "companyId": "cedar-ridge-plumbing-llc",
  "companyName": "Cedar Ridge Plumbing LLC",
  "provider": "state_registry",
  "requestDurationMs": 342,
  "resultStatus": "found",
  "fieldsReturned": ["owner_name", "registered_agent"],
  "matchConfidence": "exact_name_match",
  "country": "US",
  "batchId": "batch-2026-06-17-001"
}
```

**What we do NOT log:**
- Actual contact data values (PII) — these go only into the output/database, not the log.
- API keys or credentials.

**Where the logs go:** Laravel's configured log channel (daily rotating files in local/staging, streamed to a centralized service like CloudWatch or Datadog in production) with appropriate retention policies.

## Observability & monitoring

The system must be fully observable — if a batch stalls, a provider degrades, or costs spike, we know within minutes, not hours.

### Metrics (Prometheus / StatsD → Grafana)

| Metric | Type | What it tells us |
|---|---|---|
| `enrichment_batch_progress` | Gauge | How many companies in the current batch are done vs. total |
| `enrichment_provider_latency_ms` | Histogram | Response time per provider — detect degradation early |
| `enrichment_provider_errors_total` | Counter | Error count per provider, partitioned by error type (429, 500, timeout) |
| `enrichment_provider_circuit_state` | Gauge | 0=closed, 1=half-open, 2=open — dashboard shows provider health at a glance |
| `enrichment_confidence_distribution` | Histogram | Distribution of confidence scores per batch — detects if a provider is returning lower quality data |
| `enrichment_cost_accumulated_usd` | Counter | Running total API spend per day — triggers alert before hitting cost cap |
| `enrichment_queue_depth` | Gauge | Number of pending enrichment jobs — detects backlog buildup |
| `enrichment_human_review_rate` | Gauge | Percentage of contacts flagged for review — detects if quality is dropping |

### Alerts

| Alert | Condition | Action |
|---|---|---|
| Provider down | Circuit breaker opens for any provider | Notify on-call, continue with remaining providers |
| Batch stalled | No progress on `batch_progress` for > 10 minutes | Notify on-call, check for dead workers |
| Cost spike | `cost_accumulated_usd` exceeds 80% of daily cap | Notify team, auto-pause if 100% reached |
| Quality drop | `human_review_rate` > 60% for a batch (normally ~30%) | Notify team, likely a provider data quality issue |
| Queue backlog | `queue_depth` > 5,000 for > 15 minutes | Scale up workers or investigate slow provider |

### Health check endpoint

A `/health/enrichment` endpoint returns:

```json
{
  "status": "healthy",
  "providers": {
    "state_registry": { "status": "up", "latency_p95_ms": 280, "circuit": "closed" },
    "directory_api": { "status": "up", "latency_p95_ms": 150, "circuit": "closed" },
    "enrichment_api": { "status": "degraded", "latency_p95_ms": 2100, "circuit": "half-open" }
  },
  "queue": { "depth": 42, "workers": 4 },
  "last_batch": { "id": "batch-2026-06-17-001", "progress": "850/1000", "started_at": "..." }
}
```

### Tracing

In production, each enrichment request carries a `traceId` (batch ID + company ID) that flows through logs, metrics, and provider calls. If a contact has a bad result, we can trace the exact API calls that produced it.

## Security

### Input validation

The CSV input is an attack surface. Company names and addresses are user-provided strings that flow into API calls and database queries.

| Threat | Vector | Mitigation |
|---|---|---|
| **CSV injection** | A company name like `=CMD("malicious")` could execute if opened in Excel | Sanitize: strip leading `=`, `+`, `-`, `@` characters from all CSV fields on ingest |
| **SQL injection** | Malicious company names injected into database queries | Laravel's Eloquent ORM uses parameterized queries by default. No raw SQL with string interpolation. |
| **API parameter injection** | A crafted company name could manipulate the API query string | URL-encode all parameters before sending to providers. Validate that company names contain only printable characters. |
| **Log injection** | A company name with newlines could forge log entries | Structured JSON logging (Monolog) escapes all values. No string-concatenated log messages. |
| **Oversized input** | A CSV with 10M rows could exhaust memory or disk | Validate row count on ingest (configurable max, default 10,000). Stream-parse the CSV, never load entirely into memory. |

### API key management

- API keys are stored in environment variables, **never in code or config files committed to git**.
- In production, keys are managed via a secrets manager (AWS Secrets Manager / Laravel Vault).
- Each provider gets its own API key — if one is compromised, revoke it without affecting others.
- API keys are rotated on a schedule (quarterly) and immediately if a breach is suspected.

### Data protection

- **At rest**: PostgreSQL encrypts the data directory via OS-level encryption (LUKS/dm-crypt) or cloud-managed encryption (AWS RDS encryption). All PII contact fields (name, email, phone) are additionally encrypted at the column level via `pgcrypto` for every company — defense in depth, not just for regulated records.
- **In transit**: All API calls use HTTPS (TLS 1.2+). Database connections use SSL. Redis connections use TLS in production.
- **Access control**: The enrichment system runs with a dedicated database user that has access only to the `enrichment_*` tables — not to the broader application database. Laravel's authorization policies restrict which application users can view contact results.

### Penetration testing considerations

Before going to production, the system should be tested for:

1. **OWASP Top 10 checks**: injection, broken authentication (if API-exposed), sensitive data exposure, security misconfigurations.
2. **Provider impersonation**: verify that the system validates SSL certificates on all outbound API calls (no `verify: false`).
3. **Rate limiter bypass**: test that the Redis rate limiter cannot be circumvented by spawning workers faster than the counter updates (Redis atomic increments via `INCR` prevent this).
4. **Data exfiltration**: verify that audit logs do not contain PII, that error messages do not leak provider responses, and that stack traces in production do not expose API keys.
5. **Dependency audit**: run `composer audit` to check for known vulnerabilities in PHP dependencies. Automate this in CI.

## Performance optimization

### Pipeline performance targets

| Metric | Target | Why |
|---|---|---|
| Throughput | 1,000 companies in < 30 minutes | Dominated by API latency (~300ms/call), not compute. 3 providers in parallel = ~300ms per company. 1,000 companies in batches of 10 = 100 batches × ~1.3s = ~130s for API calls + overhead. |
| Memory usage | < 256MB peak | Stream-parse CSV, process in batches of 10, never hold all results in memory simultaneously |
| Database writes | < 5ms per contact | Bulk insert per batch (10 contacts at once), not one-by-one |

### Optimization strategies

**HTTP connection pooling.** Reuse TCP connections across provider calls within the same batch. Laravel's HTTP client uses Guzzle under the hood, which supports persistent connections via `curl_multi`. This avoids the overhead of a new TLS handshake per request (~100ms saved per call).

**Streaming CSV parsing.** `league/csv` reads the CSV as a stream — it never loads the entire file into memory. For a 1,000-row CSV this doesn't matter, but at 100K rows the difference is ~200MB vs. ~2MB memory usage.

**Batch database inserts.** Instead of one INSERT per contact, we use `DB::table('enrichment_contacts')->insert($batchArray)` — one query per batch of 10. This reduces 1,000 individual INSERTs to 100 batch INSERTs, cutting DB write time by ~90%.

**Provider response caching.** If the same company appears in multiple batches (e.g., recurring uploads), we cache provider responses in Redis with a 90-day TTL (aligned with data decay rates — beyond 90 days, data should be re-fetched). Cache hit rate for recurring batches is expected to be ~20-40%, directly reducing API costs.

**Lazy enrichment for low-priority sources.** If the first two providers (registry + directory) already produce a confidence score ≥ 85, skip the enrichment API call entirely for that company. This saves API cost and time for high-confidence results. Expected skip rate: ~15-20% of companies.

## Test strategy

Tests live in `tests/Unit/` and `tests/Feature/`, following the existing project structure. Run with `php artisan config:clear && php artisan test --parallel`.

### Unit tests (pure logic, no DB/HTTP)

**Confidence scoring** — the most critical logic to test because it determines whether contacts go to automated outreach or human review.

```
ConfidenceCalculatorTest
├── test_two_agreeing_sources_with_complete_data_scores_above_90
├── test_single_high_authority_source_with_complete_data_scores_around_74
├── test_single_directory_source_with_partial_data_scores_below_70
├── test_no_sources_returns_zero
├── test_stale_data_reduces_score_by_expected_amount
├── test_missing_role_reduces_completeness_to_067
├── test_phone_only_reduces_completeness_to_033
├── test_score_is_always_between_0_and_100
├── test_agreement_component_caps_at_1_for_three_plus_sources
├── test_authority_uses_highest_source_weight
```

Each test supplies known inputs (source list, field presence, recency) and asserts the exact expected score. This catches regressions if weights or formula logic change.

**Verification status derivation:**

```
VerificationStatusTest
├── test_verified_requires_confidence_gte_70_and_two_sources
├── test_unverified_when_single_source_above_threshold
├── test_conflicting_when_sources_disagree_on_name
├── test_not_found_when_no_sources_return_data
├── test_needs_human_review_true_for_all_non_verified_statuses
├── test_needs_human_review_false_only_when_verified
```

**Company normalization and deduplication:**

```
CompanyNormalizerTest
├── test_strips_llc_suffix
├── test_strips_inc_corp_ltd_gmbh_sa
├── test_lowercases_and_collapses_whitespace
├── test_normalizes_street_abbreviations
├── test_generates_consistent_fingerprint
├── test_same_company_different_formatting_produces_same_fingerprint
├── test_different_companies_produce_different_fingerprints

ContactDeduplicatorTest
├── test_j_smith_matches_john_smith_same_company
├── test_different_roles_same_person_keeps_both_roles
├── test_different_people_same_company_not_merged
├── test_levenshtein_threshold_rejects_low_similarity
```

**Contact field validation:**

```
EmailValidatorTest
├── test_rejects_gmail_yahoo_hotmail_domains
├── test_accepts_business_domain_emails
├── test_flags_generic_prefixes_info_contact_hello
├── test_handles_empty_and_null_emails

PhoneValidatorTest
├── test_normalizes_to_e164_format
├── test_rejects_invalid_phone_formats
├── test_identifies_residential_vs_business
```

**Input sanitization:**

```
CsvSanitizerTest
├── test_strips_csv_injection_characters
├── test_rejects_oversized_csv
├── test_handles_utf8_company_names
├── test_handles_empty_rows_gracefully
```

### Feature tests (with DB, mocks, full pipeline)

```
EnrichmentPipelineTest
├── test_full_pipeline_with_mock_providers_produces_expected_output
├── test_pipeline_resumes_from_checkpoint_after_interruption
├── test_batch_results_are_persisted_atomically
├── test_duplicate_csv_upload_is_rejected_by_file_hash
├── test_regulated_company_uses_restricted_sources_only
├── test_provider_failure_triggers_circuit_breaker
├── test_rate_limiter_blocks_when_limit_reached
├── test_export_command_produces_valid_json
├── test_export_command_produces_valid_csv
├── test_audit_log_records_every_provider_call
├── test_pii_fields_are_encrypted_in_database
├── test_regulated_company_access_is_logged
```

### Weight calibration tests

```
WeightCalibrationTest
├── test_calibration_script_with_known_labeled_data_produces_expected_weights
├── test_calibrated_weights_sum_to_one
├── test_calibrated_weights_are_all_positive
├── test_calibration_improves_accuracy_over_default_weights
├── test_calibration_output_is_valid_config_format
```

### Test coverage targets

| Area | Minimum coverage | Rationale |
|---|---|---|
| Confidence scoring | 100% line + branch | This is the core business logic. A bug here sends wrong contacts to outreach. |
| Verification status | 100% | Direct impact on `needs_human_review` flag |
| Normalization / dedup | 90%+ | Edge cases in name parsing are common |
| Provider interface | 80%+ | Test each mock provider returns expected shape |
| Pipeline integration | Key paths | Happy path + failure recovery + checkpoint resume |

## Weight auto-calibration script

An Artisan command `php artisan contacts:calibrate-weights` automates the weight calibration process described in the scoring section.

**Input**: a CSV file of human-reviewed contacts with columns: `company_id`, `is_correct` (1 or 0), plus the raw component scores from the enrichment output (`agreement_score`, `authority_score`, `completeness_score`, `recency_score`).

**Process:**

```
1. Load labeled data from CSV
2. Split 80/20 into train/test sets
3. Fit logistic regression on train set:
   P(correct) = σ(β₁×Agreement + β₂×Authority + β₃×Completeness + β₄×Recency + β₀)
4. Extract coefficients β₁–β₄
5. Normalize to sum to 1.0:
   weight_i = |βᵢ| / Σ|βⱼ|
6. Evaluate on test set:
   - Compare accuracy of new weights vs. current weights
   - Compare precision/recall at the 70 threshold
7. Output:
   - New weights as a config array
   - Before/after accuracy comparison
   - Recommendation: "apply" or "keep current" (if new weights aren't better)
```

**Output** (printed to console + saved to file):

```
Current weights:  [agreement: 0.40, authority: 0.20, completeness: 0.25, recency: 0.15]
Proposed weights: [agreement: 0.47, authority: 0.18, completeness: 0.22, recency: 0.13]

Accuracy on test set:
  Current weights:  82.3%
  Proposed weights: 86.1%  (+3.8%)

Precision at threshold 70:
  Current:  0.89
  Proposed: 0.93

Recommendation: APPLY new weights.

To apply: php artisan contacts:apply-weights --weights="0.47,0.18,0.22,0.13"
```

The `apply-weights` command updates the config file (`config/enrichment.php`) where weights are stored. Weights are never hardcoded in business logic.

**Dependencies**: uses PHP-ML (`php-ai/php-ml`) for logistic regression, or alternatively the script can export data to a Python one-liner if PHP-ML is not desired:

```bash
python3 -c "
import json, sys
from sklearn.linear_model import LogisticRegression
import numpy as np
data = json.load(sys.stdin)
X = np.array([[r['agreement'], r['authority'], r['completeness'], r['recency']] for r in data])
y = np.array([r['is_correct'] for r in data])
model = LogisticRegression().fit(X, y)
weights = np.abs(model.coef_[0])
weights = weights / weights.sum()
print(json.dumps(dict(zip(['agreement','authority','completeness','recency'], weights.tolist()))))
"
```

**When to run**: after collecting ~500+ human-reviewed contacts (quarterly, or after every major provider change).

## Privacy / compliance

**What I WILL do:**
- Only use structured API access to public business data (registries, licensed directories, enrichment APIs). No scraping.
- Validate that emails are business-domain emails, not personal email addresses.
- Validate that phone numbers are business lines, not personal/residential.
- Treat all collected data as PII — encrypt at rest, restrict access.
- Maintain a full audit log of every data source accessed per company (see logging section above).
- Respect API rate limits and terms of service.
- Minimize data collection — only the fields needed for outreach (name, role, email/phone).
- For GDPR-covered companies (EU/UK): document "legitimate interest" basis and implement data subject rights (access, deletion).

**What I will NOT do:**
- Scrape any website (no web crawling, no HTML parsing from live sites).
- Scrape personal social media profiles (Facebook, Instagram personal accounts).
- Use LinkedIn data obtained in violation of their ToS.
- Purchase data from brokers without verifiable consent chains.
- Store SSNs, financial data, or other sensitive personal information that may appear in registry data.
- Contact individuals at personal (non-business) email addresses or phone numbers.
- Bypass CAPTCHAs, authentication walls, or access-restricted databases.

**Compliance considerations:**
- TCPA compliance if phone outreach is planned (no autodialed calls to cell phones without consent).
- CAN-SPAM compliance for email outreach (valid sender, opt-out mechanism, physical address).
- GDPR compliance for EU-based companies (lawful basis, data subject rights, right to erasure).
- State-level privacy laws (CCPA for CA businesses) — our data collection is defensible as a "legitimate business interest" since these are unpaid B2B accounts with an existing business relationship.

### Regulated industry handling (built-in, not optional)

The sample data already contains a dental clinic (Magnolia Family Dental) and a veterinary clinic (Brookside Veterinary Clinic). We assume the full dataset will contain more healthcare-adjacent and potentially financial businesses. The system handles these by default, not as an afterthought.

**During the Ingest stage**, each company is tagged with a `regulated_industry` classification:
- Inferred from company name keywords (dental, medical, clinic, veterinary, financial, insurance, accounting, CPA).
- Cross-referenced with SIC/NAICS codes returned by the business registry during the Enrich stage (if available).
- If either signal matches, the company is tagged. False positives (tagging a non-regulated company) are acceptable — they get stricter handling, which is safe. False negatives (missing a regulated company) are the real risk, so we err on the side of over-tagging.

**What changes for regulated companies:**

All PII fields (contact_name, contact_email, contact_phone) are **encrypted at column level for every company** — not just regulated ones. Encryption is not a special case; it's the default. Regulated companies get additional protections on top:

| Aspect | All companies (default) | Additional for regulated companies |
|---|---|---|
| **Field encryption** | All PII encrypted at column level (`pgcrypto`) + DB-level encryption at rest | Same (already maximum) |
| **Access control** | Normal application permissions | Elevated permission role required to view/export |
| **Audit logging** | Write events logged | Every read AND write logged, including who accessed and why |
| **Data retention** | 1 year after enrichment | 90 days after outreach completes, then auto-purged |
| **Enrichment sources** | All providers used | Enrichment APIs excluded (they might return PHI or financial data). Only registry + directory. |
| **Confidence impact** | Normal scoring | Fewer sources available → lower confidence scores expected → higher `needs_human_review` rate |

**Scope boundary:** This system **finds contacts**. It does not send emails or make calls. The outreach mechanism (email templates, sending infrastructure, call scripting) is a separate system. Our output is a structured contact list with confidence scores and review flags that feeds into whatever outreach system the client uses.

## Clarifying questions

1. **What outreach channels will be used with the contacts we find? (email, phone, physical mail, or a combination?)**
   - Why it matters: Determines which contact fields are essential vs. nice-to-have. If email-only, we can skip phone lookups entirely — saving ~30% in API costs and removing TCPA considerations. If phone outreach is planned, TCPA compliance becomes critical (cell phones require prior consent for autodialed calls), and we must add phone line-type detection. If physical mail, we already have the mailing address and the main value-add is identifying the decision-maker's name for personalization.
   - Default assumption: Email + phone (most flexible for the outreach team).
   - What changes if answered: Email-only → drop phone enrichment and validation, reduce cost by ~$38/batch, simplify compliance to CAN-SPAM only. Phone-only → add TCPA consent verification step, phone line-type detection becomes mandatory. Physical mail → simplify to name-only enrichment with higher tolerance for "unverified" contacts (lower risk in mail vs. email/phone).

2. **Is this a one-time batch of ~1,000 companies, or will new unpaid accounts arrive regularly? And will only our team trigger enrichment, or will multiple enterprise clients trigger it independently?**
   - Why it matters: A one-time, single-operator batch is an Artisan command that runs once. Recurring batches need persistent infrastructure: a database to track already-enriched companies, dedup across batches, caching to avoid re-querying the same company, monitoring, and a review dashboard. If multiple clients trigger enrichment independently (multi-tenant), we must also isolate their data, apply per-tenant rate limits (to prevent one client from consuming all API quota), and ensure one client cannot see another client's contact results.
   - Default assumption: Single-tenant, one-time batch. Build an Artisan command that processes a CSV and outputs results.
   - What changes if answered:
     - **Recurring, single-tenant** → add database persistence (Eloquent models), cross-batch dedup, provider response caching (TTL-based, ~90 days based on data decay rates), scheduled execution via Laravel Scheduler, and a status dashboard.
     - **Multi-tenant** → all of the above, plus: tenant-scoped database queries, per-tenant API rate limiting (Redis-based), tenant-specific provider configurations (some clients may have their own API keys), tenant data isolation (each client's results visible only to them), and per-tenant cost tracking/billing.

3. **In what format should the enrichment results be delivered? (database table, JSON/CSV file, API endpoint, PDF report, or a combination?)**
   - Why it matters: The output format determines the last stage of the pipeline and what downstream systems can consume the results. A JSON file is simplest to build but requires manual handling. A database table enables the outreach team to query, filter, and sort contacts directly. An API endpoint allows other systems (CRM, outreach tools) to fetch results programmatically. A PDF report is useful for management review but not machine-readable.
   - Default assumption: **Database table + JSON export**. Results are persisted in the `enrichment_contacts` table (queryable, filterable) and can also be exported as a JSON or CSV file via Artisan command (`php artisan contacts:export --batch=<id> --format=json`).
   - What changes if answered:
     - **API endpoint** → add a RESTful API (`GET /api/enrichment/batches/{id}/contacts?min_confidence=70`) with authentication, pagination, and rate limiting. Adds development time but enables CRM integration.
     - **PDF report** → add a PDF generation step (using a library like DomPDF or Laravel Snappy) with summary statistics (total found, confidence distribution, review queue size) and a per-company table. Useful for stakeholder review but not for automation.
     - **Direct CRM push** → add an integration layer that pushes high-confidence contacts directly into the client's CRM (Salesforce, HubSpot) via their API. Eliminates manual import but requires per-client configuration.
     - **CSV only (no database)** → simplify to file-only output, skip database persistence. Faster to build but loses queryability and cross-batch dedup.

## Development time estimate (with AI agent)

Estimated effort for a single developer using an AI coding agent (Cursor / Claude Code), working full-time. The AI accelerates boilerplate, test generation, and repetitive code, but architectural decisions, provider integration, and edge case handling still require human judgment.

### Phase 1 — Foundation (Day 1)

| Task | Hours | Notes |
|---|---|---|
| Database migrations (4 tables, indexes, pgcrypto setup) | 1h | AI generates migration files from schema; human reviews |
| Eloquent models + relationships | 1h | Models, casts, encrypted attributes |
| `ContactProviderInterface` + mock providers (from challenge mocks) | 1.5h | Interface design is human; mock implementations are AI-assisted |
| CSV ingestion + normalization (company name, address) | 1.5h | `league/csv` parsing, suffix stripping, address normalization |
| Company fingerprinting + dedup logic | 1h | Hash generation, duplicate flagging |

### Phase 2 — Core pipeline (Day 2)

| Task | Hours | Notes |
|---|---|---|
| Enrichment pipeline orchestrator (batch processing, Http::pool) | 2h | Batch loop, parallel provider calls, result collection |
| Provenance tracking (ProvenanceField value object, merge logic) | 1h | AI generates VO; merge logic needs human care |
| Confidence scoring calculator (formula + verification status) | 1.5h | Formula implementation + edge cases |
| Contact deduplication (fuzzy name matching, initial expansion) | 1h | Levenshtein + initial matching |
| Email + phone validation logic | 1h | Domain blocklist, E.164 formatting, generic prefix detection |
| Checkpoint / resume logic (DB transaction per batch) | 1h | Atomic writes, processed-ID tracking |

### Phase 3 — Infrastructure (Day 3)

| Task | Hours | Notes |
|---|---|---|
| Rate limiter (Redis-based, per provider) | 1h | Laravel RateLimiter facade |
| Circuit breaker (Redis state machine) | 1.5h | Open/closed/half-open logic, shared state |
| Audit logging (structured JSON, PII exclusion) | 1h | Monolog formatter, event listeners |
| Artisan command: `contacts:enrich` | 1h | CLI argument parsing, progress bar, output formatting |
| Artisan command: `contacts:export` (JSON/CSV) | 0.5h | Query + format + write |
| Regulated industry tagging (keyword + SIC/NAICS matching) | 0.5h | Keyword list, Ingest-stage hook |
| Config file for weights, thresholds, provider settings | 0.5h | `config/enrichment.php` |

### Phase 4 — Tests (Day 4)

| Task | Hours | Notes |
|---|---|---|
| Unit tests: confidence scoring (10+ test cases) | 1.5h | AI generates test scaffolding; human writes assertions for edge cases |
| Unit tests: verification status (6 cases) | 0.5h | |
| Unit tests: normalization, dedup, validation | 1.5h | |
| Unit tests: input sanitization | 0.5h | |
| Feature tests: full pipeline, checkpoint, circuit breaker | 2h | Requires DB + Redis, mock HTTP |
| Feature tests: encryption verification, audit logging | 1h | |

### Phase 5 — Polish + calibration (Day 5)

| Task | Hours | Notes |
|---|---|---|
| Weight calibration script (`contacts:calibrate-weights`) | 2h | Logistic regression integration (PHP-ML or Python bridge) |
| Observability: health check endpoint, Prometheus metrics | 1.5h | Custom metric collectors, /health route |
| Security hardening: input validation review, dependency audit | 1h | `composer audit`, CSV injection edge cases |
| Performance tuning: batch size optimization, connection pooling | 1h | Benchmarking with 1K mock rows |
| Documentation: code comments for non-obvious logic | 0.5h | |
| End-to-end smoke test with full 30-row sample CSV | 0.5h | |


### Total estimate

| Phase | Duration | Cumulative |
|---|---|---|
| Foundation | 6h (Day 1) | Day 1 |
| Core pipeline | 7.5h (Day 2) | Day 2 |
| Infrastructure | 6h (Day 3) | Day 3 |
| Tests | 7h (Day 4) | Day 4 |
| Polish + calibration | 6.5h (Day 5) | Day 5 |
| **Total** | **33 hours** | **~5 working days** |

**Without AI agent**: estimated 2–3x longer (~10–12 working days), primarily because test generation, boilerplate code, and migration files would be manual.

**For the challenge "minimal slice"** (Stage B — mocked providers, no real APIs, no infra): approximately **8–10 hours** (Phases 1 + 2, reduced scope). This covers the pipeline, scoring, mock providers, provenance, and basic tests.
