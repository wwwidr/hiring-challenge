# Respaid / AgentCollect — Hiring Challenge

Welcome. This challenge is **language-agnostic** and **plan-first**. We are not testing whether you know our stack (Laravel + React). We are testing **how you think**: do you plan and ask high-value questions before you build, or do you dive straight into code?

> **Use AI tools.** Claude Code, Cursor, Copilot — we expect and want it. We evaluate how you *direct* AI, not whether you use it.

## Start here → the Contact Finder challenge

Everything is in [`challenge/PROBLEM.md`](challenge/PROBLEM.md). It has two **gated** stages:

1. **Stage A — PLAN ONLY** (~20 min): commit a `PLAN.md` (architecture, sources, confidence/provenance/"cannot-verify", privacy, and clarifying questions) **before** you read the clarifications or write solution code. Template: [`challenge/PLAN.template.md`](challenge/PLAN.template.md).
2. **Stage B — CLARIFY + BUILD**: read [`challenge/CLARIFICATIONS.md`](challenge/CLARIFICATIONS.md), then build a minimal slice against the **mocked providers** in [`challenge/mocks/`](challenge/mocks/). No real scraping.

Dataset: [`challenge/data/companies.csv`](challenge/data/companies.csv).

## Stage B minimal slice

This repo includes a small Laravel command for the Contact Finder Stage B slice.
It reads the sample CSV, resolves candidates only from
[`challenge/mocks/enrichment_responses.json`](challenge/mocks/enrichment_responses.json),
and writes one output row per input company.

Run it with:

```bash
php artisan contact-finder:run
```

By default, the output is written to:

```text
challenge/output/contact_finder_results.csv
```

The command also accepts explicit paths:

```bash
php artisan contact-finder:run \
  --companies=challenge/data/companies.csv \
  --mocks=challenge/mocks/enrichment_responses.json \
  --output=challenge/output/contact_finder_results.csv
```

Confidence is deterministic and explainable: exact mock company matches,
payment-relevant roles, complete business contact methods, independent provider
agreement, matching names/phones, and provider confidence can raise the score.
Generic contacts, missing names or roles, conflicts, weak single-source
enrichment, missing contact methods, and listing-plus-weak-enrichment rows with
no registry support reduce it. The threshold is 70: scores below 70 keep
`contact_email_or_phone` empty and set `needs_human_review=true`. Scores can
only reach 100 when all three independent mock sources are present; one- and
two-source matches are capped below absolute certainty.

Only the mocked providers in `challenge/mocks/` are used. The slice does not
call real APIs, scrape websites, or invent contact data.

## How to submit
- Your own repo (private is fine — add `wwwidr` as a collaborator), with `PLAN.md` committed **first** (git timestamps are part of the signal), then your slice.
- **REQUIRED — a 2-3 minute screen recording** (Loom or similar, no webcam needed) showing you STARTING a fresh task from your very first click: *"find 10 companies that would want AgentCollect and why."* Talk through what you do as you go, and say in one line what AgentCollect does. We watch HOW you start, not the finished result. No video = we can't book a live conversation.
- Also nice: a clean commit timeline (git timestamps are part of the signal).
- An `ABOUT.md` at the repo root — template: [`ABOUT.template.md`](ABOUT.template.md).

## How we score
See the rubric in [`challenge/PROBLEM.md`](challenge/PROBLEM.md#how-we-score-so-there-are-no-surprises). In short: plan & judgment 35%, clarifying questions 10%, adaptation 15%, slice 15%, AI-tool direction 15%, communication 10%. **Hard reject** if you dove into code with no plan, or faked precise contacts with no confidence/provenance handling.

## Conventions
[`CLAUDE.md`](CLAUDE.md) shows how we work. You don't need to follow our Laravel conventions for this language-agnostic challenge, but skim it — how we think about conventions matters.

---

### Legacy (optional, ignore unless asked)
The `tickets/` folder + the Laravel sandbox app are from our previous stack-specific challenge. The Contact Finder above is the current challenge. Do not do both.
