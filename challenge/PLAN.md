# PLAN.md (commit this BEFORE reading CLARIFICATIONS.md or writing solution code)

---

## Architecture

The task is to build an AI-powered contact/data enrichment slice for Respaid — extracting and enriching debtor or company data from multiple sources, validating confidence, and surfacing it to the frontend.

**Stack decision:** Laravel (PHP) backend + React frontend, using queued jobs for enrichment and strict schema validation before any data reaches the UI — same principle as my investment research engine: LLM as fuzzy extraction layer, deterministic code does all calculation and validation.

**Layer separation:**

```
HTTP Request
    └── Controller (thin — validates input, dispatches job)
            └── EnrichmentJob (queued)
                    ├── ProviderA (mock)  ─┐
                    ├── ProviderB (mock)  ─┼── raw data
                    └── ProviderC (mock)  ─┘
                            └── LlmExtractor
                                    └── normalise + score confidence
                                            └── Store result → emit event → Frontend polls / WS
```

**Key design choices:**
- Never stream raw LLM tokens to the frontend — wait, validate JSON against a strict schema, then send. Adds ~200ms latency, eliminates frontend crashes from malformed output.
- Never let the LLM calculate or infer values — use it only as a fuzzy extraction layer. All merging, deduplication, and confidence scoring is deterministic PHP.
- Terminal-state records (`cancelled`, `recovered`) are guarded at the model layer via a `TerminalStateGuard` trait that overrides `notify()` — impossible to bypass accidentally.

---

## Sources & strategy

| Source | What it provides | Confidence |
|---|---|---|
| Mock Provider A | Company registration data (name, address, reg. number) | High — structured, deterministic |
| Mock Provider B | Contact emails / phones | Medium — often stale or imprecise |
| Mock Provider C | Free-text web snippets | Low — needs LLM extraction |
| LLM (Claude via API) | Fuzzy extraction from unstructured text | Variable — must be schema-validated |

**Merging strategy:**
1. Pull from all three mock providers in parallel (concurrent jobs).
2. For each field, apply a priority waterfall: structured provider > semi-structured > LLM-extracted.
3. Tag every field with `source` and `confidence` (`high` / `medium` / `low` / `cannot-verify`).
4. If a field cannot be verified from any source, surface it as `cannot-verify` — never invent or hallucinate a value.

**"Cannot-verify" handling:**
- Fields with no confident source are returned as `{ "value": null, "confidence": "cannot-verify", "source": null }`.
- The frontend displays these distinctly — never as empty, never silently dropped.

---

## Quality

- **Schema validation:** LLM output is validated against a strict JSON schema before being stored or returned. Malformed outputs are retried once, then marked `cannot-verify`.
- **Deterministic tests:** Unit tests for the confidence-scoring logic and schema validator run without hitting real providers or the real LLM.
- **Feature tests:** End-to-end tests use the mocked providers in `challenge/mocks/`. No real scraping.
- **Terminal-state invariant:** Tested explicitly — assert zero notifications dispatched for `cancelled` and `recovered` records.
- **No negative copy:** All user-facing strings use positive framing (`"Verification pending"` not `"Failed to verify"`).

---

## Privacy / compliance

- No PII (names, emails, phone numbers) written to logs — log entries use record IDs only.
- LLM prompts are constructed with the minimum data needed — no full raw documents sent if a field-level prompt suffices.
- Mock provider responses treated as if they were real PII — no console dumps, no debug endpoints that expose raw data.
- No hardcoded credentials or API keys in code.

---

## Clarifying questions

1. **Question:** What does the enrichment target — a single debtor/contact, or a company entity?
   - Why it matters: shapes the data model and which mock providers are relevant.
   - Default assumption: a company entity (name, registration, contacts).
   - What changes if answered: if it's a person, I'd add identity-confidence handling and stricter PII guards.

2. **Question:** Should the enrichment result be returned synchronously or via polling / webhook?
   - Why it matters: determines whether the controller waits for the job or immediately returns a `job_id`.
   - Default assumption: async — controller returns `{ job_id }`, frontend polls a `/status/{job_id}` endpoint.
   - What changes if answered: sync response simplifies the slice but breaks under slow providers.

3. **Question:** Is there a preferred confidence threshold below which a field should be withheld from the UI entirely, vs. shown as `cannot-verify`?
   - Why it matters: a hard threshold changes the schema and the frontend contract.
   - Default assumption: always surface the field with its confidence tag — never silently drop it.
   - What changes if answered: if there is a threshold, I'd add a `visible` boolean to the schema.