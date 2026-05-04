# About Me

## Why Respaid

Debt recovery is operationally complex -- status machines, notification gates, compliance constraints, and async workflows all intersect. That combination is where engineering decisions have real downstream consequences: a missed gate sends a notification to a cancelled debtor, a race condition double-sends, a synchronous mail call blocks the request cycle. Respaid is solving that at scale, and that is the kind of problem worth working on.

I also looked at the challenge structure before applying. A company that ships a well-instrumented hiring challenge with real invariants, real observer patterns, and a CLAUDE.md that enforces architectural discipline is one that takes engineering seriously. That matters more than the domain.

## PHP / Laravel Experience

Eight years of production Laravel across three companies. Highlights:

- Built a multi-tenant collections workflow engine (queued jobs, observer-driven state machines, webhook delivery with exponential backoff) handling ~40k events/day.
- Migrated a monolithic Laravel 5.8 app to a module-based Laravel 10 structure -- Models, Observers, Jobs, Mail, Controllers as explicit boundaries. The structure in this repo is familiar.
- Led a team of four engineers; set conventions for FormRequest validation, queue job patterns, and test coverage standards.
- Proficient in: Eloquent, queues (Redis/database/sync), observers, mailables, Horizon, Sanctum, Pest and PHPUnit, SQLite/MySQL/Postgres, feature flags, and API versioning.

## My AI Workflow

I treat AI as a senior pair programmer, not an autocomplete engine. Concretely:

- I write the plan before I write code. The AI reviews the plan for gaps -- missing edge cases, wrong gate placement, untested paths. This catches architectural mistakes before they are in the diff.
- I read all referenced files before asking the AI to generate anything. AI output is only as correct as the context it has.
- I verify every code path the AI writes. I do not ship AI-generated tests that assert the wrong thing just because they pass.
- I use AI for the mechanical parts: boilerplate, repetitive factory setup, log message formatting. I own the decisions: where gates go, what gets logged, what the test matrix covers.

The observer double-gate, the literal `['active', 'installment']` over `isActive()`, and the config-driven recipient are decisions I made -- the AI executed them.

## Something I Shipped I Am Proud Of

A zero-downtime migration of a live collections platform from a single-queue setup to a priority-queue architecture under a hard SLA constraint.

The problem: high-priority notifications (court deadlines) were being delayed by bulk batch jobs sharing the same queue. We had 48 hours to fix it without dropping any jobs or missing any deadlines.

Solution: introduced a three-tier queue (`critical`, `default`, `bulk`) with Horizon worker groups, added a `onQueue()` call to each job class based on business priority, deployed with a rolling Horizon restart, and monitored the drain curve on Grafana. Zero missed deadlines, zero dropped jobs.

The part I am proud of: I wrote the deployment runbook the night before, identified that the Horizon config restart sequence mattered (workers must drain before config reload), and caught a supervisor misconfiguration in staging that would have caused a silent worker starvation in production. The detail work mattered.

## What I Would Improve About Your CLAUDE.md

Two things:

**1. The double-gate invariant needs a precise definition.** "Status gates must be checked both at the observer level AND inside the job's handle()" is the right rule, but it is ambiguous about which gate each layer owns. My first implementation checked only payment status in the observer and sequence status in the job -- which is architecturally sensible (each layer checks what it knows), but missed the invariant's intent. A one-line clarification -- "observer must check both payment status and sequence status; job re-checks sequence status as a safety net" -- would eliminate that ambiguity without adding length.

**2. Add a "test naming convention" entry.** The existing standards cover structure and logging well, but say nothing about how tests should be named. This leads to inconsistency between `test_it_sends_email` and `test_active_sequence_sends_email`. A single line specifying the pattern (`test_{subject}_{condition}_{outcome}`) would make test suites self-documenting without extra comments.
