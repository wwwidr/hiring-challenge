# Hiring Challenge v3 — Recording-First (Contact Finder)

Status: drafted 2026-06-04, codex-reviewed (verdict: PASS with the hardening below baked in).
Replaces: the v2 two-stage markdown "plan-first Contact Finder". Reason: the markdown grades a
polished artifact (AI-polishable, outsourceable, fakeable timestamps) and does NOT surface the
candidate's working *method* or mindset. A v2 candidate (2026-06-04) shipped strong code but the
live call revealed an "AI hallucinates so I do it by hand" mindset — disqualifying for an
AI-native shop, and invisible in the artifact. v3 observes HOW they start.

What we select FOR: directs AI tools (plan-mode), turns ambiguity into a plan, asks high-value
questions, and when AI/vendor output is wrong, builds a VERIFICATION SYSTEM (provenance,
confidence, human-in-loop) instead of reverting to manual row-by-row lookup.
What we screen OUT: "I don't trust AI so I do it manually" as the primary strategy.

---

## The brief sent to the candidate (deliberately under-specified)

> We sent you a unique spreadsheet of companies (a mix of large and very small businesses) with
> addresses, plus a sample "vendor/AI enrichment" output for some rows. Some of that enrichment is
> wrong on purpose.
>
> Goal: for each company, find the **top 2 people most likely to pay** — the people we should
> contact to get an invoice paid. Complete what's missing.
>
> Record **8–10 minutes** of yourself working:
> - First ~5 min: how you START — fresh.
> - Then, after you've worked ~20–30 min, ~3 min explaining how you decide whether to TRUST or
>   REJECT the provided enrichment, and how you'd design that verification at scale (do NOT fix it
>   row by row — design the guardrail).
>
> Use any AI tool or tooling you prefer. The dataset is **synthetic and approved for AI use**.
> Questions are encouraged — if no one is available to answer, state your assumptions and proceed.
> You may **speak or type** your reasoning. You are judged on problem framing, AI direction,
> assumptions, and verification approach — **not** presentation style or a polished spreadsheet.

Deliverables: (1) the continuous unedited recording (primary), (2) the completed spreadsheet
(secondary, only to confirm the work is real), (3) optionally the AI session/prompts used.

---

## Hardening (from codex review 2026-06-04 — all required)

1. **Per-candidate randomized data**, generated at unlock time: varied company sizes, missing
   fields, duplicates, ambiguous subsidiaries, and 1–2 planted traps (wrong-but-plausible
   enrichment rows). Defeats rehearsal/outsourcing.
2. **Forced verification trap** (the core test): the provided enrichment contains plausible wrong
   contacts. The 3-min segment asks them to design how they'd use/reject it — this is where the
   manual-vs-guardrail mindset shows. Without this, axis (c) may never appear in 5 min.
3. **Time-boxed unlock + continuous unedited recording**; ask them to show the challenge-page
   timestamp + dataset ID on screen at the start. Optional: AI transcript/prompts export.
4. **Score the first ~2 min of framing + the verification design more than the final spreadsheet.**

## Scoring rubric (score behavior, not polish/charisma — 5 axes)

1. Did they turn ambiguity into a plan?
2. Did they direct AI with constraints, examples, and evaluation criteria?
3. Did they ask or write high-leverage clarifying questions?
4. Did they design provenance / confidence / human-review guardrails?
5. Did they AVOID defaulting to manual lookup as the primary strategy?

False-failure guards (do NOT penalize): inspecting data before opening AI; declining to paste
real PII into a model (data is synthetic, but the instinct is fine); thinking silently / typing
instead of speaking; using pandas/Excel first; using a non-Claude AI tool; non-native English.

## Dataset + light compliance (JB decision 2026-06-04: drop the heavy consent text)

- **Dataset = real publicly-listed companies** (name + public business address, the kind anyone can
  find publicly) plus synthetic missing fields / planted-trap enrichment rows. No personal/private
  data in the challenge data, so no data-privacy burden on the candidate side.
- **No webcam** required (screen only). Offer an **accommodation / alternate-format** path on request.
- Warn candidates not to expose THEIR OWN personal accounts, API keys, or private tabs while recording.
- **Automation triages only — never auto-rejects.** Bot transcribes + first-pass scores the 5 axes;
  a human reviews all low/mid before any decision.
- Consent text intentionally dropped (JB call): we're a small startup, dataset is public, triage is
  human-final. NOTE (non-blocking): IF you ever hire specifically for NYC or Illinois roles, the
  recording-AI-analysis rules (NYC AEDT, Illinois AI Video Act) can re-apply — revisit then, not now.
