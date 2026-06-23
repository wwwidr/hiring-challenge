# ABOUT

## Why this role
I like systems where the interesting problem isn't the code, it's the judgment —
deciding what to trust, what to flag, and where a human still has to stand. An AI-native
collections shop is exactly that: the model does the volume, and the engineering is the
guardrail that makes the volume safe to act on.

## How I work with AI tools
I direct AI with constraints and evaluation criteria, not open-ended prompts. For the slice,
I handed Claude Code my scoring rules as hard requirements rather than letting it invent its
own, because the scoring logic was the actual deliverable. I read everything it produced and
overrode it where it was wrong — e.g. it tried to resolve a two-different-people conflict by
silently picking one source; I made it cap confidence and flag for review instead. I trust the
model for plumbing and speed; I keep the judgment calls and the verification design for myself.

## My last project (Lobbyside — Live Visitors)
- **One ambiguity:** "live visitor count" was never defined — tabs, idle, mid-transition were
  counted differently across widget, dashboard, and backend. I wrote the definition down, got it
  confirmed, then made the code match it.
- **One tradeoff:** I chose periodic reconciliation against source of truth over pure
  event-driven presence. More server cost, but disconnect events are unreliable (closed laptops,
  dropped networks) and were producing phantom counts. A presence number people don't believe
  isn't worth shipping.
- **One mistake:** I fixed a "2 people ahead of you" phantom-queue bug but only tested the
  single-visitor happy path, which hid the real bug — abandoned join-flows. I should have
  reproduced it with several visitors dropping off at once from the start.
- **One review comment that changed my mind:** I'd planned to add a field to track visitor
  state; a reviewer noted the widget already passed identifying info we were discarding (why
  known visitors showed as "Anonymous"). I dropped the new field, used the existing payload,
  and closed a second bug at the same time.

## Anything I'd improve about this challenge / CLAUDE.md
The auto-review GitHub Action in the repo (`.github/workflows/review-candidate.yml`) is keyed to
the older Laravel tickets — it greps changed files for company-search / observer / payment and
runs PHPUnit. A language-agnostic Python submission for this Contact Finder challenge matches
none of those branches, so that bot won't score this task meaningfully. Worth pointing out since
the challenge is explicitly language-agnostic now.