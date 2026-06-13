# PLAN.md (committed BEFORE reading CLARIFICATIONS.md or writing solution code)

## Architecture

A **pipeline of pluggable source adapters** feeding a resolution and scoring layer.
The design is stack-neutral; each stage is a clear contract so sources can be added or
removed without touching the core.

System topology (end to end):

```
  companies.csv
       |
       v
  +---------------+   upsert (one tx)   +-------------------------+
  |   INGESTER    |-------------------->|       DB / OUTBOX        |
  | normalize +   |                     | rows + outbox entries   |
  | idempotency_  |                     | status: pending ->      |
  | key           |                     | in_progress -> done /   |
  +---------------+                     | review / error          |
                                        +-----------+-------------+
                                                    | relay (SKIP LOCKED)
                                                    v
                                        +-------------------------+
                                        |         QUEUE           |
                                        |  [fetch] [extract] DLQ  |
                                        +----+----------------+---+
            rate limiter (per-source         |                |
            token bucket) ----------------> [fetch]        [extract]
                                             v                v
                                       +-----------+    +-------------+
                                       |  FETCH    |    | EXTRACT /   |
                                       |  workers  |--->| RESOLVE     |
                                       |  (I/O)    |    | (CPU)       |
                                       +-----+-----+    +------+------+
                                             |                 |
                                             v                 v
                                       +-----------+    +-------------+
                                       |  SOURCES  |    |  SCORING    |
                                       | registry  |    | trust x     |
                                       | web / API |    | agreement x |
                                       | directory |    | role x      |
                                       +-----------+    | freshness   |
                                                        +------+------+
                                                               |
                                                               v
                                                        +-------------+
                       >= threshold                     |  DECISION   |
                  +-------------------------------------|    GATE     |
                  |                                      +------+------+
                  v                                             | < threshold /
            enriched CSV                                        |  cannot-verify
         (per-row + provenance)                                 v
                                                        +-------------+
                                                        |   HUMAN     |
                                                        |   REVIEW    |
                                                        |   QUEUE     |
                                                        +------+------+
                                                               | corrections
                                                               v
                                                        +-------------+
                                                        |   TRUST     |
                                                        | CALIBRATION |
                                                        +------+------+
                                                               | learned (source,field)
                                                               | priors feed SCORING
                                                               '----------> (closes loop)
```

Stages: normalize/resolve -> source adapters (parallel, independent) -> candidate merge
(dedupe, cluster by person) -> confidence scoring -> decision gate -> emit or flag. The
**feedback loop** (human review -> trust calibration -> scoring) is what keeps confidence
honest over time, not just at first run.

- Every adapter implements one contract: `lookup(company) -> Candidate[]`, with the raw
  evidence attached to each candidate. Adding or swapping a source never changes the core.
- **Provenance is structural, not bolted on.** Each candidate field carries
  `{ value, source, evidence_ref, fetched_at }` from the moment it is created, so every
  emitted value is traceable back to the source that produced it.
- Rows are independent and processing is idempotent, so the real 1,000-row job is trivially
  parallel / queueable. (This matches the repo's "queued jobs" convention without depending
  on it for the slice.)

### Ingestion & dedup

Ingestion and fail-safety are the same mechanism: every row is normalized and persisted
under a stable `idempotency_key` before any source work begins.

```
CSV -> normalize(name, address) -> idempotency_key = hash(norm_name + norm_address)
    -> UPSERT(idempotency_key, status = pending)     idempotent; same name+addr = no-op + logged
    -> flag rows where one name spans > 1 address     needs_human_review = ambiguous_duplicate
    -> processing stage reads `pending` rows          per-row checkpoint, resume-safe
```

1. **Read** the CSV row by row.
2. **Normalize** both name and address (address normalization is what makes "same address"
   detection reliable).
3. **Compute `idempotency_key = hash(norm_name + norm_address)`.**
4. **Upsert by `idempotency_key`** with status `pending` - never a plain insert. This is the
   fail-safety primitive: if the loader crashes at row 200 and restarts, re-reading rows
   1-200 simply no-ops instead of double-inserting.
5. **Same name + same address -> drop, but log the count.** With upsert this is free (the
   duplicate hits the same key and no-ops); we record "collapsed N exact duplicates" for the
   audit trail. Never a *silent* drop.
6. **Same name + different address -> keep both, mark both `needs_human_review`** with reason
   `ambiguous_duplicate`. Mechanically: group by `norm_name`; any name spanning more than one
   distinct `norm_address` flags all its rows. (High-cardinality generic names that span many
   addresses are down-ranked / batched into one review item rather than N separate tasks.)

### Processing & concurrency

The DB table from ingestion doubles as a **transactional outbox**. This decouples the
long-running source work from ingestion and removes the dual-write problem.

```
ingest tx: UPSERT row + outbox entry (one transaction)
        -> relay polls undispatched outbox rows (SKIP LOCKED) -> publish to queue -> mark dispatched_at
        -> FETCH workers   (I/O-bound: call providers, fetch pages)  -- capped by rate limits
        -> EXTRACT workers (CPU-bound: parse, NER, fuzzy-match, score) -- scaled by cores
        -> persist result + provenance, set terminal status
```

- **Outbox solves dual-write.** Committing the row *and* its outbox entry in one DB
  transaction means a crash can never leave a committed row with no queued message (silent
  loss) or a message for a row that didn't commit. The relay publishes at-least-once and marks
  `dispatched_at` on ack.
- **Two worker pools, not one.** Most "scraping/searching" is I/O-bound (provider calls, page
  fetches); the CPU-bound work is the *next* step (HTML parsing, entity resolution, NER,
  scoring). Splitting them into separate queues lets each scale and size independently -
  cheap high-concurrency fetch workers, beefy core-bound extract workers.
- **Distributed rate limiting is mandatory once decoupled.** A single in-process pool gave
  backpressure for free; N independent queue consumers do not. Per-source rate limits must be
  enforced with a shared limiter (e.g. a Redis token bucket per source), or the workers
  collectively blow past provider limits and get throttled/banned. This is a real cost of the
  decoupled design.
- **Poison rows -> DLQ.** A message that exceeds `maxReceiveCount` lands in a dead-letter
  queue for inspection instead of retrying forever or halting the run - the managed version of
  the poison-row handling below.
- **At-least-once everywhere -> idempotency.** Both outbox publish and queue delivery are
  at-least-once, so a row will occasionally be processed twice. Every worker and the final
  emit step must be idempotent on `idempotency_key` - including any future "send" step, or a
  redelivery double-sends.
- **Broker behind an interface; the slice ships no infra.** SQS + split pools + DLQ +
  distributed limiter is the production topology. The dispatch seam is an interface, so the
  Stage B slice models it as outbox table -> an in-memory queue -> a worker loop over
  `pending` rows: same shape, no AWS, runnable locally against the mocks.

### Failure, restart & idempotency

The 1,000-row job must survive a crash mid-run (e.g. an error after row 200) and resume
without losing completed work, redoing it, or double-charging source APIs.

- **Per-row checkpointing, not buffer-then-write.** Each row's result + provenance is
  persisted the moment it reaches a terminal state (or in small batches), never held in
  memory until the end. A crash at row 200 leaves rows 1-200 durably stored.
- **`idempotency_key` (from ingestion) drives resume.** On restart the runner skips any key
  already in a terminal state, so re-running the whole file is a safe no-op for finished rows.
  The address is part of the key on purpose (see "Same company name, different address").
- **Explicit per-row status lifecycle:**
  `pending -> in_progress -> done | needs_human_review | error`.
  Any row found in `in_progress` at startup was interrupted -> reset to `pending` and retry.
- **Transient vs. poison failures.** A transient error (provider timeout, rate limit) retries
  that row with backoff. A poison row (reliably crashes the worker) is caught, marked `error`
  with a reason, and skipped - it never halts the whole run or retries forever.
- **Caveats.** A resumed row may be fetched much later than row 1; each value already carries
  `fetched_at`, so the staleness is recorded, not hidden. If a real downstream step ever
  *sends* something (email/CRM write), idempotency must extend to that step too, or a restart
  double-sends - which is why the decision gate and any send step stay separate and each
  individually idempotent.

### Same company name, different mailing address

Keying on company name alone is unsafe: two rows "Blue Heron Landscaping" in different states
are likely **distinct legal entities**, each an account we must reach. Name-only keys would
collide and silently drop the second account. So:

- **The work-unit key always includes the normalized address.** Each distinct
  (name, address) is its own work unit and its own output row. We never collapse two of the
  client's accounts into one.
- **"Are these the same company?" is a separate, downstream question** handled at the merge
  stage, not by the key. Same name + different address may be (a) two unrelated businesses
  sharing a generic name, (b) two branches/locations of one company, or (c) data-entry
  variants of one address.
- We may **reuse a resolved entity's contacts across rows** that confidently resolve to the
  same entity (a cache, to save API calls), but we still emit one output row per input
  account and **flag possible-same-entity** (reason code) rather than guessing a merge.

### Same mailing address, different companies

The inverse case is common - shared office buildings, strip malls, coworking spaces, and
especially **registered-agent / virtual-office / mail-drop addresses** where many LLCs are
nominally "located".

- **No collision:** the key is name + address, so different names are different work units and
  different output rows. No data loss.
- **A shared address is a trust signal, not just a label.** When N rows share one normalized
  address, the address tells us little about *which* person is the right contact; a source may
  attribute a contact to the building or the registered agent rather than the company - a
  classic false-positive source.
- **Mitigation:** detect high-cardinality addresses (count rows per normalized address). For
  those, downweight address as a matching/disambiguation signal, bias toward
  `needs_human_review`, and tag a reason code (`shared_address_low_signal`).
- **Never reuse contacts across companies just because the address matches.** Contact reuse
  (the cache above) is keyed on resolved *entity*, never on address alone.

## Sources & strategy

Combine sources chosen for **independence**, so that *agreement between them is signal*.
Real-world categories (Stage B uses the mocked providers):

- **Registry / official** (state business registry, SoS filings) - high trust for the
  *legal owner / registered agent*; weak on role and email.
- **Web / company site** (about, contact, team pages) - good for role + email; medium trust;
  staleness risk.
- **Enrichment APIs** (the kind Stage B mocks) - broad coverage, variable trust; must expose
  their own confidence so we can weight it.
- **Directory / listing** (maps, business directories) - good for phone + address
  confirmation; weak on identifying the actual decision-maker.

**How they fail:** stale data, wrong-person-same-name, role inflation, generic `info@`
catch-alls, and confident-but-wrong API hits. This is exactly why no single source is
trusted on its own.

## Quality

- **Dedupe.** Normalize the company (strip legal suffixes, canonicalize address), then
  cluster candidate *people* by (normalized name + email/phone). Merge clusters while keeping
  every source pointer.
- **Confidence (0-100).** Explainable, never a black box - see "Trust & confidence scoring"
  below for how trust feeds it.
- **Provenance.** Every output value links to source + evidence ref + timestamp (see
  Architecture).
- **"Cannot verify" is a first-class state**, never a silent blank. We emit the row with the
  best available candidate (if any) but set `needs_human_review = true` plus a reason code:
  `no_source`, `single_unverified_source`, `conflicting_sources`, `role_mismatch`,
  `generic_email_only`, `ambiguous_duplicate`, `shared_address_low_signal`. We **never
  fabricate** a precise contact to fill a cell.
- **False-positive risk.** The expensive error is contacting the *wrong* person about money.
  The gate is biased toward "human review" over false precision: a row auto-emits only with
  corroboration or a single high-trust source.

### Trust & confidence scoring

**Trust and confidence are different things.** *Trust* is how much we believe a **source** in
general (an input weight). *Confidence* (0-100) is how much we believe a **specific value for a
specific row** (the number we emit). Trust is one input to confidence, not the same thing.

- **Trust is per-source AND per-field, not one global scalar.** A directory may be reliable for
  *phone* but worthless for *who the decision-maker is*; a registry is gold for *legal owner*,
  weak for *email*. So trust is a `(source, field)` matrix, not a single number per source.
- **Trust priors are calibrated, not guessed-and-frozen.** Start from sensible priors per source
  category, then **learn them from the human-review feedback loop** (bounce rates, "wrong
  person" corrections) and any seed/ground-truth data. Static priors drift into lies.
- **Agreement counts only between *independent* sources.** Two directories that both scrape the
  same upstream are one vote, not two; corroboration between correlated sources is fake
  confidence. The agreement term discounts sources known to share lineage.
- **Vendor self-reported confidence is discounted, never passed through.** An enrichment API's
  "95%" is a claim, weighted by *our* trust in that API - not taken at face value.
- **Conflict lowers confidence.** When sources disagree on a value we do not just pick a winner;
  disagreement reduces the score and can trigger `conflicting_sources` / human review.
- **The number must mean something (calibration).** A confidence of 80 should correspond to
  "~80% of these are correct," validated against review outcomes - otherwise the threshold (see
  clarifying Q9) is arbitrary. We calibrate against the feedback loop over time.
- **Sketch of the formula (explainable):**
  `confidence = base(best field value) x independent-agreement boost x role-fit x freshness-decay x contactability`,
  each factor in `[0,1]` (or a bounded boost), clamped to 0-100. Every row emits its
  contributing factors alongside the score, so a reviewer sees *why* it scored what it did.

## Privacy / compliance

- **Will:** use only publicly available or licensed business-contact data; prefer role-based
  business contacts; log lawful basis + source; honor opt-out / suppression; retain provenance
  for auditability.
- **Will NOT:** scrape sources whose terms forbid it; acquire or exploit personal data beyond
  a business-contact purpose; generate email permutations and present guesses as verified;
  treat any unverified guess as fact. (Exact GDPR/CCPA scope is a clarifying question below.)

## Clarifying questions

We keep each question decision-shaped (why / default / what-changes) rather than padding the
list. They are grouped: **core** questions reshape the architecture or scoring; **operational**
questions shape integration, cost, and process. We answer each with a default so the build can
proceed unanswered, and adapt when answers arrive.

### Core (reshape design or scoring)

1. **Which contact role takes priority, and is a substitute acceptable when it's missing?**
   - Why it matters: "right decision-maker" spans owner -> CFO -> AP manager -> office
     manager; this drives the role-fit scoring weight and what counts as a usable hit.
   - Default assumption: prioritize the AP / billing contact, then the owner; accept an office
     manager as a verified fallback.
   - What changes if answered: the role-fit weight in confidence, and which rows clear the gate
     vs. get flagged.

2. **What false-positive rate is acceptable - do we optimize for coverage or precision?**
   - Why it matters: contacting the *wrong* person about money is usually far costlier than a
     miss; this tolerance, not a guessed number, is what should set the confidence threshold.
   - Default assumption: precision over coverage - emit only well-corroborated or high-trust
     hits; everything else `needs_human_review = true`.
   - What changes if answered: the numeric threshold, whether single-source hits may auto-emit,
     and the precision/recall balance.

3. **Is there a human-review team for flagged rows, and what daily volume can it absorb?**
   - Why it matters: the entire `needs_human_review` / `ambiguous_duplicate` gate assumes a
     human loop exists; without one the design must collapse to "auto-emit + tag uncertainty."
   - Default assumption: a review team exists with modest throughput, so we triage to send only
     genuine ambiguity to a person.
   - What changes if answered: whether the review queue exists at all, how aggressively we flag,
     and whether we tune the threshold to a review-capacity budget.

4. **Is this a one-time ~1k batch or a continuous feed, and how fresh must a contact be?**
   - Why it matters: this justifies or kills the outbox -> queue -> multi-worker topology and
     sets the staleness / re-verification policy.
   - Default assumption: one-time batch; treat a contact as trustworthy for ~6 months, no
     automatic re-verification.
   - What changes if answered: whether we build the scale-out topology now, and whether we add a
     re-verification scheduler and freshness decay in scoring.

5. **What is the compliance envelope - jurisdiction, allowed sources, personal vs. business data?**
   - Why it matters: defines which sources are legal/usable and what we may store; this bounds
     the entire source strategy and retention design.
   - Default assumption: US-only, public or licensed business data only, no ToS-violating
     scraping, business-role contacts preferred.
   - What changes if answered: which adapters exist at all, data retention, and what we are
     allowed to keep as provenance.

### Operational (integration, cost, process)

6. **Is there a cost ceiling per account for paid enrichment sources?**
   - Why it matters: paid lookups x ~1,000 add up; budget caps how many independent sources we
     can combine to corroborate, which directly affects achievable confidence.
   - Default assumption: a modest per-account budget - try free/official sources first, escalate
     to paid only when cheaper sources are inconclusive.
   - What changes if answered: source ordering, how many sources we require for corroboration,
     and the cost/precision trade.

7. **What outreach channel will use this - email, phone, or physical mail?**
   - Why it matters: it decides which contact field must be verified hardest (email vs phone)
     and what "usable" means per row.
   - Default assumption: email-first, phone as fallback; verify whichever channel we emit.
   - What changes if answered: the contactability weight in scoring and which field gates a row.

8. **Do you already have any contacts/CRM data we can seed or validate against?**
   - Why it matters: existing ground truth lets us validate sources, calibrate confidence, and
     skip rows already solved.
   - Default assumption: none - cold start, no seed data.
   - What changes if answered: we add a validation/calibration step and a "already known" skip.

9. **What confidence threshold should gate auto-emit, or do you want us to propose one?**
   - Why it matters: it's the single knob separating auto-emit from human review.
   - Default assumption: we propose a conservative starting threshold and expose it as config to
     tune against real review feedback.
   - What changes if answered: the cutoff value and whether it's fixed or adaptive.

10. **How should we deliver output, and do you want one best contact or several ranked?**
    - Why it matters: enriched CSV vs API vs CRM push changes the output contract; one-vs-many
      changes the dedup/merge and the schema.
    - Default assumption: enriched CSV matching the input plus the required fields, one best
      contact per row, with provenance available on request.
    - What changes if answered: the output adapter, the schema, and whether we emit ranked
      alternates.
