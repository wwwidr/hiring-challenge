# TICKET-005: Contact Finder — Stage A (Plan Only)

## The problem

An enterprise client (a global logistics company) onboarded ~1,000 unpaid small-business accounts. For every account we have **only**:

- `company_name`
- `mailing_address`

No owner name, no email, no phone. We need to reach the **right decision-maker** (owner, CFO, AP manager, office manager) to drive payment. A single source will never cut it, and not every contact can be found — how we handle "I cannot verify this" matters as much as the ones we find.

This ticket covers **Stage A only**: the plan and the clarifying questions. No solution code.

## The ticket (hard constraints)

- **Plan-first.** Commit `PLAN.md` **before** reading `challenge/CLARIFICATIONS.md` or writing any solution code. The git timestamp on this commit is part of the signal — commit it on its own.
- **~20 minutes.** Quality beats quantity: 3 sharp clarifying questions beat 15 shallow ones.
- **No solution code in this stage.** Architecture and reasoning only.
- Use the template in `challenge/PLAN.template.md`.

## Requirements

`PLAN.md` must cover:

- **Architecture** — how the system takes the CSV and returns contacts (stages, data flow, idempotency, parallelism).
- **Sources & strategy** — what kinds of sources to combine and why (no real ones required at this stage).
- **Quality** — dedupe, **confidence scoring**, **provenance** (every emitted value traceable to its source), **"cannot-verify" states**, and false-positive risk.
- **Privacy / compliance** — what we would and would NOT do.
- **Clarifying questions** — for **each** question state: (1) why it matters, (2) the default assumption if it's never answered, (3) what changes in the design depending on the answer.

## Files to Create/Modify

- `PLAN.md` (repo root) — committed on its own, before Stage B.

## Deliverable

The committed `PLAN.md` is the deliverable for this ticket. Stage B (clarify + build against the mocks) is tracked separately.

Delivered by commit `c53c69f` — "Add detailed architecture and processing plan to PLAN.md".

## What we actually evaluate

- **Plan quality & judgment** — is the architecture coherent, and does it take confidence/provenance/"cannot-verify" seriously rather than as bolt-ons?
- **Clarifying questions** — decision-value (why / default / what-changes), not quantity.
- A plan that dives into code with no assumptions, or one that promises precise contacts with no confidence/provenance handling, is a **hard reject**.
