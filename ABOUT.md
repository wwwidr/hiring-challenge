# ABOUT

> Note: the sections below marked **[TODO — your words]** are personal and
> should be written by you. The rest reflects how this slice was actually built
> and can be edited freely.

## Why this role
**[TODO — your words, 2-3 sentences.]** What draws you to AI-native engineering
and to this problem (reaching the right person, honestly, when data is partial).

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
- **One ambiguity** you faced and how you resolved it: **[TODO — your words]**
- **One tradeoff** you made and why: **[TODO — your words]**
- **One mistake** you made and what you changed: **[TODO — your words]**
- **One review comment** that made you change your mind: **[TODO — your words]**

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
