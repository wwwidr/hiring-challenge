# Take-home: Contact Enrichment Pipeline

**Time budget:** ~4–6 hours. We don't want a production system — we want to see how you reason about a
messy, real-world data problem and how you make an LLM + external APIs cooperate reliably.

## The problem
We collect overdue invoices on behalf of our clients. Each client hands us a spreadsheet of debtors that
looks like `sample_invoices.xlsx` (attached). The rows are messy exports from accounting systems: company
names carry registration codes, "Full name" is sometimes a department or the company itself, and **the
email column is almost always empty**.

To chase a payment we need a **reachable, correct contact** — ideally the person in the debtor company who
handles accounts payable, otherwise a usable corporate contact.

Your task: **build a pipeline that takes these raw rows and, for each one, finds the best contact you can
— at minimum a valid corporate email — and outputs the enriched result with enough metadata for a human
reviewer to trust (or reject) each row.**

## Input data
`sample_invoices.xlsx` — 25 real (anonymized) rows. Key columns: `Full name` (may be a person, a
department, or the company), `Address` (the **debtor's**), `Company name` (often has `(DUNS N° …)` / a
legal form appended), `Email` (**empty — this is what you find**), `Phone number`, and
`Company issuing the invoice` (**our client — ignore for enrichment, this is NOT the debtor**).

> A common mistake is enriching the creditor (the company issuing the invoice) instead of the debtor.

## What we'd like to see
Open-ended — design the pipeline yourself. A strong submission typically:
1. **Resolves the company** behind a messy name (e.g. `LAKE CABLE LLC (DUNS N° 927410308)` → the real
   company + ideally its corporate domain), and verifies it found the *right* company, not a same-named one.
2. **Finds a contact** — at least a valid email. Web search, the company site, public sources are fair
   game. Be explicit about how you avoid inventing / hallucinating emails.
3. **Validates the result** so a reviewer can trust it — a confidence signal, the evidence/source per
   contact, and a clear "couldn't enrich this one" state rather than a confident wrong answer.
4. **Outputs** the enriched data so a non-engineer could review it: a spreadsheet that looks like the
   input, with each found contact inserted on the row(s) **directly below** its source row, **fill-colored
   differently** so a reviewer sees at a glance what was added, and a **per-contact 0–1 confidence score**.

Handle the long tail gracefully: some companies are tiny or have no web presence. "No contact found,
here's why" is **better** than a guess.

## Tooling (use whatever you like — free/cheap suggestions)
- **Web search:** [Serper](https://serper.dev) — free tier (~a few thousand queries), simple JSON API.
  Enough for this task. Register on your own free account.
- **LLM:** any provider — Anthropic Claude mirrors our stack, but OpenAI / local / etc. are all fine.
  **Use your own key.** (Finalists who'd rather not spend anything: tell us and we'll hand you a small
  capped key — but free tiers cover this task.)
- Scraping a company `/contact` or `/about` page is a legitimate source. Paid contact APIs (RocketReach,
  Hunter) are **not required** — don't spend money; if your design would use one, describe where it slots in.
- Don't hardcode answers for these 25 rows — the pipeline should generalize.

## Deliverables
1. **Code** — runnable, with a short README (how to run, what env vars/keys it needs).
2. **Output** — the enriched spreadsheet for the 25 rows in the format above (colored rows + confidence).
   If you can't style cells, a clearly-labelled CSV/JSONL fallback is acceptable, but the colored sheet is
   what we ask for.
3. **A ~1-page write-up:** your approach + main design decisions; how you guard against hallucinated
   contacts; what you'd do with more time and how you'd evaluate quality at scale (1000s of rows).
4. **`ABOUT.md`** at the repo root (template provided) — who you are, how you used AI on this, and the
   single hardest judgment call you made.

## How we evaluate
| Area | What we look for |
|---|---|
| **Correctness** | Contacts plausibly right? Did you target the debtor, not the creditor? |
| **Judgment** | Sensible handling of ambiguity, the long tail, and "don't know" cases |
| **Reliability** | No hallucinated emails; failures are explicit and explained |
| **Code quality** | Clear, runnable, reasonably structured — not necessarily production-grade |
| **Communication** | The write-up shows you understand the trade-offs you made |
| **AI direction** | How you steered the LLM/tools (prompts, constraints, eval), not whether you used them |

We care more about **good reasoning on a hard subset** than enriching all 25. Tell us what you'd skip
and why.

## Process (so honest candidates aren't penalized)
- Commit a short **`PLAN.md` first** (approach + clarifying questions) **before** the solution code — git
  timestamps are part of the signal.
- **Do not squash or rewrite commits** before submitting — we read the commit timeline.
- Process evidence: a screen-recording link **or** a clean commit timeline — your choice, async, no webcam.

## How to submit
Your own repo (private is fine — add **`johnbanr`** as a collaborator), `PLAN.md` committed first, then
your slice + output + write-up + `ABOUT.md`. Questions encouraged; if no one answers, state assumptions
and proceed.
