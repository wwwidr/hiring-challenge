# Challenge: Contact Finder (plan-first, two stages)

This challenge is **language-agnostic**. Use any language, any stack, any AI tools (Claude Code, Cursor, Copilot — we expect and want it). We are not testing whether you know our stack. We are testing **how you think**: do you plan and ask high-value questions before you build, or do you dive straight into code?

There are two stages and they are **gated**. Do Stage A before you look at Stage B.

---

## The problem

One of our enterprise clients (a global logistics company) just onboarded ~1,000 unpaid small-business accounts. For every account we have **only**:

- `company_name`
- `mailing_address`

No owner name, no email, no phone. We need to reach the **right decision-maker** (owner, CFO, AP manager, office manager) to drive payment. A sample dataset is in [`data/companies.csv`](data/companies.csv).

A single source will never cut it, and not every contact can be found. How you handle "I cannot verify this" matters as much as the ones you find.

---

## STAGE A — PLAN ONLY (do this first, ~20 minutes)

**Write `PLAN.md` and commit it BEFORE you read `CLARIFICATIONS.md` or write any solution code.** Use the template in [`PLAN.template.md`](../PLAN.template.md). The git timestamp on this commit is part of how we read your process, so commit it on its own.

Your `PLAN.md` should cover:

- **Architecture**: how you'd structure a system that takes the CSV and returns contacts.
- **Sources & strategy**: what kinds of sources you'd combine and why (you do NOT need real ones for this challenge — see Stage B mocks).
- **Quality**: how you handle dedupe, **confidence scoring**, **provenance** (every value traceable), **"cannot-verify" states**, false-positive risk.
- **Privacy / compliance**: what you would and would NOT do.
- **Clarifying questions**: the questions you'd ask us before building. For **each** question state:
  1. why it matters,
  2. your default assumption if we never answer,
  3. what changes in your design depending on the answer.

Quality beats quantity: **3 sharp questions beat 15 shallow ones.** Do not write solution code in Stage A.

---

## STAGE B — CLARIFY + BUILD (the rest of your time)

1. Read [`CLARIFICATIONS.md`](CLARIFICATIONS.md) — our answers to the most common questions (target persona, allowed sources, compliance limits, success metric, confidence threshold).
2. Build a **minimal working slice** against the **mocked providers** in [`mocks/`](mocks/) (read [`mocks/README.md`](mocks/README.md)). **Do not scrape anyone for real** — the mocks give you canned data, including some not-found / low-confidence rows on purpose.
3. Output, per input row: `contact_name`, `contact_role`, `contact_email_or_phone`, `confidence_score` (0-100, your own logic), `source` (which mock provider(s) it came from), and `needs_human_review` (true when confidence is below the threshold in CLARIFICATIONS.md or you cannot verify).

We care that your build **follows your plan** and **adapts** to the clarifications. A small, honest slice that handles "cannot verify" well beats a slick scraper that invents precise-looking but unverifiable contacts.

---

## What to submit

- Your repo with `PLAN.md` committed first (timestamps visible in git history), then the slice.
- Process evidence: a screen recording link **or** a clean commit timeline — your choice (async / no webcam required).
- An `ABOUT.md` at the repo root (see [`../ABOUT.template.md`](../ABOUT.template.md)).

## How we score (so there are no surprises)

| Dimension | Weight |
|-----------|--------|
| Plan quality & judgment | 35% |
| Clarifying questions (decision-value: why / default / what-changes) | 10% |
| Adaptation (plan → build, did you use the clarifications) | 15% |
| Implementation of the slice (against mocks) | 15% |
| AI-tool direction | 15% |
| Communication / README | 10% |

**Hard reject** (regardless of how good the code is): you committed solution code with no plan/assumptions (dove straight in), or you faked precise contacts with no confidence/provenance/"cannot-verify" handling.
