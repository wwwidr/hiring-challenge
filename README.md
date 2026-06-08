# Respaid / AgentCollect — Hiring Challenge

Welcome. This challenge is **language-agnostic** and **plan-first**. We are not testing whether you know our stack (Laravel + React). We are testing **how you think**: do you plan before you build, and does your pipeline survive contact with messy, real-world data?

> **Use AI tools.** Claude Code, Cursor, Copilot — we expect and want it. We evaluate how you *direct* AI, not whether you use it.

## Start here → the Contact Enrichment challenge

Everything is in [`challenge/PROBLEM.md`](challenge/PROBLEM.md): build a pipeline that takes raw, messy debtor rows from `sample_invoices.xlsx` (25 anonymized real rows) and, for each one, finds the best reachable contact — at minimum a valid corporate email — with a confidence score and per-contact evidence a human reviewer can trust.

The dataset (`sample_invoices.xlsx`) is sent with the challenge email — see [`challenge/DATA_README.md`](challenge/DATA_README.md). Real web search / scraping is fair game; this is a real-data task, not a mocked one.

## How to submit
- Your own repo (private is fine — add `johnbanr` as a collaborator), with `PLAN.md` committed **first** (git timestamps are part of the signal), then your slice + output + write-up.
- **Do not squash or rewrite commits** before submitting — we read the commit timeline.
- Process evidence: a screen-recording link **or** a clean commit timeline (your choice — async, no webcam required).
- An `ABOUT.md` at the repo root — template: [`ABOUT.template.md`](ABOUT.template.md).

## How we score
See the rubric in [`challenge/PROBLEM.md`](challenge/PROBLEM.md#how-we-evaluate). We weigh correctness (did you target the **debtor**, not the creditor?), judgment on the long tail and "don't know" cases, reliability (no hallucinated emails — failures are explicit), code quality, communication, and how you steered the AI. **Hard reject** if you enriched the creditor instead of the debtor, or faked precise contacts with no confidence/evidence handling.

## Conventions
[`CLAUDE.md`](CLAUDE.md) shows how we work. You don't need to follow our Laravel conventions for this language-agnostic challenge, but skim it — how we think about conventions matters.

---

### Legacy (optional, ignore unless asked)
The `tickets/` folder + the Laravel sandbox app are from our previous stack-specific challenge. The Contact Enrichment challenge above is the current one. Do not do both.
