# Execution Guide

## Prerequisites

- **PHP 8.2+** with extensions: openssl, mbstring, curl, fileinfo, zip, sqlite3
- **Composer** (or `composer.phar` in the project root)

### Installing on Windows (WinGet)

```powershell
winget install PHP.PHP.8.2
```

After installing, restart your terminal so the PATH updates. If `php` is still not recognized, refresh the PATH manually:

```powershell
$env:Path = [Environment]::GetEnvironmentVariable("Path","User") + ";" + [Environment]::GetEnvironmentVariable("Path","Machine")
```

### Installing dependencies

```bash
composer install
```

Or if you downloaded `composer.phar` directly:

```bash
php composer.phar install
```

---

## Web Interface

The project includes a web frontend with two pages: **Enrichment** (run the pipeline and view results) and **Training** (validate contacts and auto-calibrate weights).

### Starting the server

```bash
php artisan serve
```

This starts a local development server at `http://localhost:8000`.

### Enrichment page (`/`)

1. Open `http://localhost:8000`
2. Optionally upload a CSV file (leave empty to use the demo data with 30 companies)
3. Click **Run Pipeline**
4. Results appear in a table with color-coded confidence scores and verification statuses

### Training page (`/training`)

1. Open `http://localhost:8000/training`
2. Optionally upload a CSV file (leave empty to use the demo data)
3. Click **Generate Training Set** — this runs the pipeline and randomly selects 20% of enriched results
4. For each company, review the contact found and toggle **Yes** / **No** to mark whether the result is correct
5. Click **Validate & Calibrate Weights** — this runs logistic regression on your labels and auto-updates `config/enrichment.php` if the new weights improve accuracy

---

## Architecture

### System overview

```
                          ┌──────────────────────────────┐
                          │  php artisan contacts:enrich  │
                          │     (EnrichContactsCommand)   │
                          └──────────────┬───────────────┘
                                         │ companies.csv
                                         ▼
                          ┌──────────────────────────────┐
                          │     EnrichmentPipeline        │
                          │        (orchestrator)         │
                          └──────────────┬───────────────┘
                                         │
              ┌──────────────────────────┼───────────────────────────┐
              │                          │                           │
              ▼                          ▼                           ▼
   ┌─────────────────┐      ┌─────────────────┐         ┌─────────────────┐
   │  Stage 1: INGEST │      │ Stage 2: RESOLVE │         │ Stage 3: ENRICH │
   │                  │      │                  │         │                 │
   │  CsvSanitizer    │      │ CompanyNormalizer │         │ For each company│
   │    ↓ strip =+@-  │      │   ↓ deduplicate  │         │ query providers │
   │  Parse CSV rows  │      │   by fingerprint  │         │ with rate limit │
   │    ↓ normalize   │      │                  │         │ + circuit break │
   │  CompanyNormalize│      │                  │         │                 │
   └─────────┬───────┘      └────────┬─────────┘         └───────┬─────────┘
             │                        │                           │
             │  NormalizedCompany[]   │  NormalizedCompany[]     │
             └────────────────────────┘                           │
                                                                  │
                          ┌───────────────────────────────────────┘
                          │
          ┌───────────────┼───────────────────────────┐
          │               │                           │
          ▼               ▼                           ▼
 ┌──────────────┐ ┌──────────────┐ ┌──────────────────────┐
 │   Registry   │ │   Listing    │ │    Enrichment        │
 │   Provider   │ │   Provider   │ │    Provider          │
 │              │ │              │ │                      │
 │ authority:   │ │ authority:   │ │ authority:           │
 │   0.90       │ │   0.50       │ │   0.70               │
 │              │ │              │ │                      │
 │ Returns:     │ │ Returns:     │ │ Returns:             │
 │  name, role  │ │  name, phone │ │  email, phone,       │
 │              │ │              │ │  provider_confidence  │
 └──────┬───────┘ └──────┬───────┘ └──────────┬───────────┘
        │                │                     │
        │  ProviderResult (nullable per company)
        └────────────────┼─────────────────────┘
                         │
                         ▼
              ┌─────────────────────┐
              │  Stage 4: SCORE     │
              │                     │
              │  ContactMerger      │
              │   ├─ pickBestName   │  (highest authority source)
              │   ├─ pickBestRole   │  (AP Mgr > Owner > CFO > Office Mgr)
              │   ├─ pickBestEmail  │  (non-generic preferred, reject personal)
              │   ├─ pickBestPhone  │  (highest authority source)
              │   └─ validateFields │  (reject gmail/yahoo/hotmail)
              │                     │
              │  ContactValidator   │
              │   └─ isPersonalEmail│
              │                     │
              │  ConfidenceCalculator
              │   ├─ Agreement      │  0/1 sources=0.0, 1=0.5, 2+=1.0
              │   │   └─ nickname   │  Bob↔Robert, S.↔Sean (initials)
              │   ├─ Authority      │  max(provider weights)
              │   ├─ Completeness   │  (name + role + email|phone) / 3
              │   │   └─ generic    │  info@/contact@ reduce score
              │   └─ Recency        │  0.5 (mock default)
              │                     │
              │  Formula:           │
              │  score = (0.40×Agr  │
              │    + 0.20×Auth      │
              │    + 0.25×Comp      │
              │    + 0.15×Rec)×100  │
              │                     │
              │  RegulatedIndustry  │
              │   └─ dental/vet →   │
              │     healthcare      │
              │                     │
              │  VerificationStatus │
              │   ├─ verified:      │  score≥70 AND 2+ agree
              │   ├─ unverified:    │  single source (needs review)
              │   ├─ conflicting:   │  sources disagree on name
              │   └─ not_found:     │  no provider returned data
              └──────────┬──────────┘
                         │
                         ▼ ScoredContact[]
              ┌─────────────────────┐
              │  OUTPUT              │
              │                     │
              │  Console table      │
              │  + results.json     │
              │                     │
              │  Per company:       │
              │   contact_name      │
              │   contact_role      │
              │   contact_email     │
              │   contact_phone     │
              │   confidence_score  │
              │   verification_status
              │   needs_human_review│
              │   provenance (per   │
              │     field: which    │
              │     provider + URL) │
              └─────────────────────┘
```

### Detailed call flow for a single company

```
"Cedar Ridge Plumbing LLC, 4821 Maple Ave, Lincoln, NE 68504"
  │
  ├─ 1. CsvSanitizer.sanitizeField()         → strip injection chars (=,+,-,@)
  ├─ 2. CompanyNormalizer.normalize()         → "cedar ridge plumbing" + fingerprint
  ├─ 3. CompanyNormalizer.deduplicate()       → skip if same fingerprint seen
  │
  ├─ 4. RateLimiter.attempt("registry", 100)  → allowed? yes
  │     CircuitBreaker.isAvailable("registry") → CLOSED (ok)
  │     MockRegistryProvider.lookup()          → {name:"Daniel Ortega", role:"Owner"}
  │     CircuitBreaker.recordSuccess()
  │
  ├─ 5. RateLimiter.attempt("listing", 100)
  │     CircuitBreaker.isAvailable("listing")
  │     MockListingProvider.lookup()           → {name:"Daniel Ortega", phone:"+1-402-555-0148"}
  │     CircuitBreaker.recordSuccess()
  │
  ├─ 6. RateLimiter.attempt("enrichment", 100)
  │     CircuitBreaker.isAvailable("enrichment")
  │     MockEnrichmentProvider.lookup()        → {email:"d.ortega@cedarridgeplumbing.com", confidence:84}
  │     CircuitBreaker.recordSuccess()
  │
  ├─ 7. ContactMerger.merge()
  │     ├─ pickBestName()       → "Daniel Ortega" (registry, authority 0.90)
  │     ├─ pickBestRole()       → "Owner" (priority 2, from registry)
  │     ├─ pickBestEmail()      → "d.ortega@cedarridgeplumbing.com" (non-generic, business domain)
  │     ├─ pickBestPhone()      → "+1-402-555-0148" (listing)
  │     ├─ validateFields()     → email ok (not personal domain)
  │     │
  │     ├─ ConfidenceCalculator.calculate()
  │     │   Agreement:    1.0   (registry + listing both say "Daniel Ortega")
  │     │   Authority:    0.90  (max of 0.90, 0.50, 0.70)
  │     │   Completeness: 1.0   (name ✓, role ✓, email ✓)
  │     │   Recency:      0.5   (mock default)
  │     │   Score: (0.40×1.0 + 0.20×0.90 + 0.25×1.0 + 0.15×0.5) × 100 = 91
  │     │
  │     ├─ RegulatedIndustryDetector.detect() → null (plumbing is not regulated)
  │     │
  │     └─ determineVerificationStatus()
  │         2 agreeing names + score 91 ≥ 70 → VERIFIED, needs_human_review=false
  │
  └─ 8. Output:
       {
         "company_name": "Cedar Ridge Plumbing LLC",
         "contact_name": "Daniel Ortega",
         "contact_role": "Owner",
         "contact_email": "d.ortega@cedarridgeplumbing.com",
         "contact_phone": "+1-402-555-0148",
         "confidence_score": 91,
         "verification_status": "verified",
         "needs_human_review": false,
         "provenance": {
           "name":  { sources: ["registry", "listing"] },
           "role":  { sources: ["registry"] },
           "email": { sources: ["enrichment"] },
           "phone": { sources: ["listing", "enrichment"] }
         }
       }
```

### File structure

```
app/Modules/ContactFinder/
├── Console/
│   ├── EnrichContactsCommand.php        ← CLI entry point
│   └── CalibrateWeightsCommand.php      ← weight training
├── Contracts/
│   └── ContactProviderInterface.php     ← provider abstraction
├── Enums/
│   ├── VerificationStatus.php           ← verified/unverified/conflicting/not_found
│   └── CircuitState.php                 ← closed/open/half_open
├── Providers/
│   ├── ContactFinderServiceProvider.php ← DI bindings
│   ├── AbstractMockProvider.php         ← shared mock logic
│   ├── MockRegistryProvider.php         ← simulates state registry API
│   ├── MockListingProvider.php          ← simulates directory API
│   └── MockEnrichmentProvider.php       ← simulates enrichment API
├── Services/
│   ├── EnrichmentPipeline.php           ← 4-stage orchestrator
│   ├── CompanyNormalizer.php            ← name normalization + dedup
│   ├── ConfidenceCalculator.php         ← weighted scoring formula
│   ├── ContactMerger.php               ← multi-source merge + provenance
│   ├── ContactValidator.php            ← email/phone validation
│   ├── CsvSanitizer.php               ← injection protection + row limits
│   ├── RateLimiter.php                ← per-provider request limits
│   ├── CircuitBreaker.php             ← fault tolerance state machine
│   └── RegulatedIndustryDetector.php  ← healthcare/finance tagging
└── ValueObjects/
    ├── NormalizedCompany.php            ← normalized name + fingerprint
    ├── ProviderResult.php               ← single provider response
    ├── ProvenanceField.php              ← field origin tracking
    └── ScoredContact.php                ← final scored output
```

---

## Running the Enrichment Pipeline

### Basic usage

```bash
php artisan contacts:enrich challenge/data/companies.csv
```

This prints a summary table to the console showing every company with its contact, role, confidence score, verification status, and whether it needs human review.

### Export results to JSON

```bash
php artisan contacts:enrich challenge/data/companies.csv --output=results.json
```

The JSON file contains the full output per company, including detailed provenance (which provider returned each field).

**Reading the output:**

| Status | Meaning |
|---|---|
| `verified` (NO review) | 2+ independent sources agree, score >= 70. Safe for automated outreach. |
| `unverified` (YES review) | Single source or score >= 70 but not independently verified. Human should confirm. |
| `conflicting` (YES review) | Sources disagree on the contact name. Human must resolve. |
| `not_found` (YES review) | No provider returned data for this company. Manual research needed. |

---

## Running Tests

```bash
php artisan config:clear
php artisan test
```

This runs 112+ tests covering:

- **CompanyNormalizerTest** — name normalization, suffix stripping, fingerprint generation, deduplication
- **ConfidenceCalculatorTest** — scoring formula, agreement calculation, nickname/initial matching
- **ContactMergerTest** — source merging, role prioritization, generic email handling, provenance
- **ContactValidatorTest** — personal email rejection, generic email detection, phone normalization
- **CsvSanitizerTest** — CSV injection protection, oversized file rejection
- **RateLimiterTest** — per-provider request limits
- **CircuitBreakerTest** — CLOSED → OPEN → HALF_OPEN state transitions
- **RegulatedIndustryDetectorTest** — healthcare/finance keyword detection
- **EnrichmentPipelineTest** — full integration test with mock providers

---

## Weight Calibration (Training)

The confidence scoring formula uses four weights that determine how much each quality dimension matters:

```
confidence = (0.40 × Agreement + 0.20 × Authority + 0.25 × Completeness + 0.15 × Recency) × 100
```

These weights are **configurable, not hardcoded** — stored in `config/enrichment.php`.

### How to train

**Step 1 — Generate labeled data.**

Run the pipeline on a batch of companies. The output JSON includes component scores per contact. Export them into a CSV with enough context for a human reviewer to label each row:

```csv
company_name,contact_name,contact_role,contact_email,contact_phone,sources,agreement_score,authority_score,completeness_score,recency_score,confidence_score,verification_status,is_correct
Cedar Ridge Plumbing LLC,Daniel Ortega,Owner,d.ortega@cedarridgeplumbing.com,+1-402-555-0148,"registry, listing, enrichment",1.0,0.90,1.0,0.5,91,verified,1
Northgate HVAC Services,Thomas Reed,Registered Agent,,,registry,0.5,0.90,0.67,0.5,62,unverified,0
```

The columns `company_name` through `verification_status` give the reviewer all context to decide. The `is_correct` column is what they fill in: `1` = correct contact, `0` = wrong person or outdated. The calibration command only reads the score columns and `is_correct` — the context columns are for the human.

A sample file with real pipeline data is included at `challenge/data/sample_labeled_data.csv` (18 companies from the mock data, pre-labeled as an example).

**Step 2 — Run calibration.**

```bash
php artisan contacts:calibrate-weights challenge/data/sample_labeled_data.csv
```

This will:
1. Load the labeled data and split 80/20 into train/test sets
2. Fit a logistic regression model to learn which dimensions best predict correctness
3. Compare accuracy of current weights vs proposed weights
4. Print a recommendation (APPLY or KEEP)

**Step 3 — Apply if better.**

```bash
php artisan contacts:calibrate-weights challenge/data/sample_labeled_data.csv --apply
```

This updates `config/enrichment.php` with the new weights automatically.

### Alternative: Python (scikit-learn)

For more robust calibration with larger datasets:

```bash
python3 -c "
import csv, json
from sklearn.linear_model import LogisticRegression
import numpy as np
data = list(csv.DictReader(open('challenge/data/sample_labeled_data.csv')))
X = np.array([[float(r['agreement_score']), float(r['authority_score']),
               float(r['completeness_score']), float(r['recency_score'])] for r in data])
y = np.array([int(r['is_correct']) for r in data])
model = LogisticRegression().fit(X, y)
w = np.abs(model.coef_[0]); w = w / w.sum()
print(json.dumps(dict(zip(['agreement','authority','completeness','recency'], w.round(2).tolist()))))
"
```

### When to recalibrate

- After every ~500 human-reviewed contacts
- Quarterly as a routine check
- Immediately after adding or replacing a data provider

---

## Next Steps for Production (Replacing Mocks)

### 1. Replace mock providers with real API implementations

The system uses `ContactProviderInterface` — each provider is a class that implements `getName()`, `getAuthorityWeight()`, and `lookup()`. To go to production:

| Mock provider | Replace with | Real API |
|---|---|---|
| `MockRegistryProvider` | `StateRegistryProvider` | OpenSOSData API (US), OpenCorporates (international) |
| `MockListingProvider` | `DirectoryApiProvider` | Google Places Text Search API |
| `MockEnrichmentProvider` | `EnrichmentApiProvider` | Apollo.io API |

Each new provider implements the same interface and is registered in `ContactFinderServiceProvider`. The pipeline code does not change.

```php
// In ContactFinderServiceProvider, swap bindings:
$this->app->singleton(EnrichmentPipeline::class, function ($app) {
    return new EnrichmentPipeline(
        // ... same services ...
        providers: [
            $app->make(StateRegistryProvider::class),    // real API
            $app->make(DirectoryApiProvider::class),     // real API
            $app->make(EnrichmentApiProvider::class),    // real API
        ],
    );
});
```

### 2. Add database persistence

Create Laravel migrations for the four tables described in `PLAN.md`:

- `enrichment_batches` — tracks batch status, progress, idempotency
- `enrichment_companies` — normalized company data with fingerprints
- `enrichment_contacts` — scored contacts with encrypted PII fields
- `enrichment_audit_logs` — every provider call logged for compliance

Use PostgreSQL with `pgcrypto` for field-level encryption of all PII (name, email, phone).

### 3. Add queue-based processing

Replace the synchronous pipeline loop with Laravel Jobs:

```php
// Instead of processing all companies in a loop:
foreach ($companies as $company) {
    EnrichCompanyJob::dispatch($company, $batchId);
}
```

This enables:
- Parallel processing across multiple queue workers
- Automatic retry with exponential backoff on failure
- Per-batch checkpointing (resumable after crashes)
- Rate limiting via Laravel's built-in queue rate limiting

### 4. Switch rate limiter and circuit breaker to Redis

The current implementation uses in-memory counters (works for single-process). In production with multiple queue workers, switch to Redis:

```php
// Rate limiter: use Laravel's Redis-backed RateLimiter
RateLimiter::attempt(
    key: "provider:{$provider->getName()}",
    maxAttempts: 100,
    callback: fn () => $provider->lookup($company),
    decaySeconds: 60,
);

// Circuit breaker: store state in Redis
Redis::set("circuit:{$providerName}", $state->value, 'EX', $cooldownSeconds);
```

### 5. Add email and phone verification

Add two more provider types:

| Provider | API | Purpose |
|---|---|---|
| `EmailVerificationProvider` | NeverBounce or MyEmailVerifier | Confirm email is deliverable, detect catch-all domains |
| `PhoneVerificationProvider` | Twilio Lookup API | Validate format, detect carrier, identify mobile vs landline |

### 6. Add observability

- **Metrics**: Prometheus counters/histograms for provider latency, error rates, confidence score distribution
- **Health endpoint**: `GET /health/enrichment` returning provider status, circuit breaker states, queue depth
- **Alerting**: notify when a circuit breaker opens, batch stalls, or cost exceeds daily cap
- **Tracing**: each enrichment carries a `traceId` (batchId + companyId) through all logs and API calls

### 7. Add cost controls

```php
// In config/enrichment.php:
'cost_caps' => [
    'daily_total' => 500.00,        // USD
    'per_provider' => [
        'registry' => 100.00,
        'directory' => 100.00,
        'enrichment' => 200.00,
    ],
],
```

Track spend per provider per day. Pause jobs when cap is reached.

### 8. Security hardening

- Store API keys in a secrets manager (AWS Secrets Manager, Laravel Vault)
- Run `composer audit` in CI to check for dependency vulnerabilities
- Enable row-level security in PostgreSQL for multi-tenant isolation
- Ensure all outbound API calls validate SSL certificates
- Add authentication and authorization to any API endpoints exposing contact data

### Production deployment checklist

- [ ] Replace mock providers with real API implementations
- [ ] Create database migrations and run them
- [ ] Configure Redis for queue, rate limiter, and circuit breaker
- [ ] Set up queue workers (Supervisor or Laravel Horizon)
- [ ] Configure API keys in environment variables
- [ ] Add email/phone verification providers
- [ ] Set up Prometheus metrics and Grafana dashboards
- [ ] Configure alerting for provider failures and cost spikes
- [ ] Run initial weight calibration with ~500 human-reviewed contacts
- [ ] Set up scheduled recalibration (quarterly cron)
- [ ] Security audit: `composer audit`, penetration testing, access control review
- [ ] Load test with 1,000+ companies to validate throughput targets
