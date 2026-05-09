# About Me

## Why Respaid

Debt recovery sits at the intersection of async workflows, status-machine correctness, and notification reliability, the exact problems I have been solving professionally. At GenerexAI I designed and owned an event-driven billing and notification platform integrating Stripe and Orb Billing: async webhook processing, database-level idempotency, exponential backoff retries, dead-letter queues, reconciliation pipelines, and multi-channel notifications (email, SMS, in-app). The gate-and-dispatch pattern in TICKET-003 is not abstract to me; it is the pattern I reach for every time a notification must not fire on a terminal state.

Respaid's hiring challenge itself was also a signal. A company that ships real invariants, real observer bugs left in the codebase, and a CLAUDE.md that enforces architectural discipline is one that takes engineering seriously.

## PHP / Laravel Experience

2+ years of production Laravel before transitioning to a Python-first stack. I have shipped Eloquent-backed APIs, observer-driven workflows, queued jobs, and mailables in production environments. My current primary stack is Python (FastAPI, SQLAlchemy, Celery, Redis), but the patterns in this codebase (status-machine observers, queued jobs with safety-net gates, structured logging) are ones I have implemented in both ecosystems. I picked up the current Laravel idioms from the codebase before writing a line; the architecture was immediately familiar.

## My AI Workflow

I use AI as a pair programmer, not an autocomplete engine:

- I write the plan first. The AI reviews it for gaps (missing edge cases, wrong gate placement, untested paths) before any code is generated.
- I read all referenced files before asking the AI to generate anything. Output is only as correct as the context it has.
- I verify every code path the AI writes. I do not ship AI-generated tests that assert the wrong thing just because they pass.
- I use AI for mechanical work: boilerplate, factory setup, log message formatting. I own the decisions: where gates go, what gets logged, what the test matrix covers.

The observer double-gate, the explicit `['active', 'installment']` over a magic helper, and the config-driven recipient are decisions I made; the AI executed them.

## Something I Shipped I Am Proud Of

At GenerexAI I designed an event-driven billing and notification platform from scratch integrating Stripe and Orb Billing for a US home remodeling SaaS.

The hard part was reliability under partial failure: webhooks can arrive out of order, duplicate, or not at all. I introduced database-level idempotency keys so duplicate webhook deliveries were safe to process twice, exponential backoff retries with dead-letter queues for transient failures, and a reconciliation pipeline that caught any gaps between Stripe's event log and our database state. Multi-channel notifications (email, SMS, in-app) were dispatched only after payment state was confirmed; no notification on a pending or failed charge.

What I am proud of: the idempotency design held up under a Stripe webhook storm during a billing cycle edge case that we had not anticipated. No duplicate charges, no missed notifications, no manual intervention.
I have shipped these in the past : 
<a href="https://ibb.co.com/KxJ01zwP"><img src="https://i.ibb.co.com/Jj9BhFQ1/image.png" alt="image" border="0"></a>
<a href="https://ibb.co.com/8gSZsLFc"><img src="https://i.ibb.co.com/3mLZd9tz/image.png" alt="image" border="0"></a>
<a href="https://ibb.co.com/C3RbwwRG"><img src="https://i.ibb.co.com/PvqcrrqH/image.png" alt="image" border="0"></a>
## What I Would Improve About Your CLAUDE.md

Two things:

**1. The double-gate invariant needs a precise definition.** "Status gates must be checked both at the observer level AND inside the job's handle()" is the right rule, but it is ambiguous about which gate each layer owns. A one-line clarification ("observer must check both payment status and sequence status; job re-checks sequence status as a safety net against queue lag") eliminates the ambiguity without adding length.

**2. Add a test naming convention.** The existing standards cover structure and logging well but say nothing about how tests should be named. A single line specifying the pattern (`test_{subject}_{condition}_{outcome}`) would make test suites self-documenting without extra comments.

## Adjacent Issues Noticed (out of scope, flagged for awareness)

While implementing TICKET-003 I read the adjacent notification code and found two related issues:

**1. `SequenceObserver::updated()` dispatches without a terminal status check.**
`app/Modules/Sequence/Observers/SequenceObserver.php` dispatches `NotifySequenceUpdate` on every `updated` event with no gate. A sequence transitioning to `cancelled` or `recovered` will still enqueue a notification job, violating the core invariant. The observer itself has a `// BUG: This dispatches for ALL sequences, including cancelled ones` comment acknowledging this. The job has its own separate BUG comment about its missing safety-net gate. TICKET-002 scope, not touched here.

**2. `NotifySequenceUpdate::handle()` has no safety-net gate.**
The job has an explicit BUG comment: `// BUG: No status check here, should skip terminal sequences`. Any terminal-sequence job already in the queue before a fix would execute without protection. The double-gate pattern implemented in TICKET-003 is the correct fix for this job as well.
