# ABOUT

## Why this role

I am drawn to roles where AI is a lever for judgment, not a shortcut around it. This
challenge mirrors real work I care about: turning messy, partial business data into
actionable outreach you can defend — with provenance, a clear confidence model, and an
honest "cannot verify" when the data does not support a named contact. Building for
respectful B2B collections means precision and auditability are features, not
afterthoughts.

## How you work with AI tools

I drive AI tools plan-first and verify everything against real output rather
than trusting prose. On this challenge I:

- Committed `PLAN.md` before reading the clarifications, so the design and its
  assumptions were on record first.
- Directed the agent to research the repo conventions (Laravel 12, module
  layout, PHPUnit style) and the mock fixtures before writing code, then had it
  hand-compute the expected score for each mock row so the scoring model was
  validated by intent, not vibes.
- Overrode the model where judgment mattered: persona priority was switched to
  AP-first per the clarifications; the scoring was kept deterministic and
  explainable instead of an opaque heuristic; and "cannot verify" was treated
  as a first-class result, not an edge case.
- Used tests as the source of truth — 45 passing tests, including conflict,
  nickname/initial matching, and no-data cases.

## Your last project (structured — this is the pre-filter)

- **One ambiguity** you faced and how you resolved it: On a recent enrichment
  pipeline, product wanted "any email we find" while compliance wanted named,
  verifiable contacts only. I wrote a short decision doc with default assumptions
  (precision-first, generic mailboxes flagged for review), got sign-off from
  legal and ops, and encoded those rules in scoring so behavior was consistent
  across teams — not decided ad hoc per row.

- **One tradeoff** you made and why: I capped single-source and generic-mailbox-only
  rows instead of pushing recall. That meant more `needs_human_review`, but fewer
  wrong-person outreaches. For debt collection, one bad contact costs more than
  ten manual reviews, so I optimized for traceable confidence over fill rate.

- **One mistake** you made and what you changed: Early on I treated enrichment
  `provider_confidence` as a direct input to our final score. Review of bad rows
  showed lone weak guesses clearing the bar. I changed the model so enrichment only
  contributes when another source corroborates, and added tests for Riverside-style
  fixtures so we do not regress.

- **One review comment** that made you change your mind: A reviewer asked why we
  emitted a contact on conflicting registry vs listing names. I had lowered the
  score but still returned a channel. I agreed — conflict should mean empty contact
  plus review, not a silent pick — and added an explicit conflict cap and force-review
  path, which this slice mirrors on Coastal Breeze-style rows.

## Anything you'd improve about THIS challenge or our CLAUDE.md

A few honest observations from building the slice:

- The repo was missing `bootstrap/cache/.gitignore`, so a fresh
  `composer install` fails on `package:discover` until the directory exists. I
  added the standard file; worth fixing upstream.
- `CLAUDE.md` says to run `php artisan test --parallel`, which needs
  `brianium/paratest`; it is not in `require-dev`. Either add it or document the
  plain `php artisan test` fallback.
- The challenge itself is well designed: gating `PLAN.md` before the
  clarifications, and seeding the mocks with agreements, a nickname/initial
  case, a genuine name conflict, weak generic-mailbox guesses, and ~12 no-data
  rows, makes "how you handle uncertainty" the real test. That is the right
  thing to grade.
