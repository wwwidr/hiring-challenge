# ABOUT.md — Tamagn Zewdu

## Why this role

What drew me in wasn't the AI stack — it was the engineering culture: plan before you build, challenge with data then commit, measure by what you ship. That's how I've always worked best — I joined a 5-year Django codebase and was contributing meaningfully within weeks; I led an IoT platform migration in 2 months with zero downtime, not because I knew the stack but because I understood the problem first. The part that genuinely excites me is building the infrastructure that makes the whole team faster — AI tooling, automation pipelines, systems with real leverage — and AgentCollect is one of the few places where that's the job, not a side project.

## How you work with AI tools

I use Claude Code and GitHub Copilot daily. My pattern: trust the model for structure and speed, override it for judgment calls. On this challenge I had the model draft the scoring function skeleton, then rewrote the weighting table myself after reading the clarifications — because the model couldn't know that "Registered Agent" deserved a penalty or that the threshold should sit at 70, not 60. I also set the workflow gates: nothing got committed without my review, personal content came from me, business logic decisions stayed mine. I think of AI as a very fast junior who needs direction on anything that requires actually reading the brief.

## Your last project

**BlueClerk — IoT SaaS Platform Migration** (React / Node.js / MongoDB → Next.js / NestJS / PostgreSQL, 2 months, zero data loss, zero downtime)

- **One ambiguity** I faced and how I resolved it:
  The MongoDB documents had evolved organically for 4+ years — no schema, no migration guide, original engineer unavailable. I had to decide: enforce a strict PostgreSQL schema or keep a JSONB escape hatch for irregular fields? I spent two days sampling real production documents across tenants before committing to a hybrid — normalised tables for the stable 80%, JSONB for the irregular 20% with a clear path to normalise later. The ambiguity was never resolved from the outside; I defined the schema and owned it.

- **One tradeoff** I made and why:
  I chose tenant-isolated batch migration over a single big-bang cutover. Safer — but it meant running dual writes (MongoDB + PostgreSQL) for three weeks, which doubled write complexity and introduced sync bugs I had to hunt under production load. I chose batches because enterprise contracts made a failed weekend cutover unacceptable. One late-night race condition corrupted 11 device records (caught by integration tests before the dashboard saw it). Worth it — but I'd build the sync layer with an explicit event log from day one next time.

- **One mistake** I made and what I changed:
  I wrote the NestJS service layer before finalising the PostgreSQL schema. Two weeks in, a client requirement changed a one-to-many I had modelled as many-to-many — the refactor cascaded into every service, DTO, and test. The lesson wasn't "design longer before coding." It was: write one query per core use case against real data first, validate the schema, then build the service layer. I now treat schema validation as a hard gate before any service code starts.

- **One review comment** that made me change my mind:
  At Ellatech I wrote a utility to push a Hono app's routes to Postman — a quick developer convenience. My tech lead commented: *"This is thinking too small. What if it handled Zod validation too? This could be a standalone tool."* That reframe changed everything. I spent a weekend generalising it, added Zod schema introspection, and published it as **[Postfame](https://www.npmjs.com/package/postfame)** — a CLI that auto-generates Postman collections from any Hono + Zod app. I now apply that "solve the class of pain, not just this pain" lens to every utility I write.

## Anything you'd improve about this challenge or CLAUDE.md

The two-stage gating is exactly right — it genuinely separates planners from divers. One small improvement: the `PLAN.template.md` prompts for clarifying questions but doesn't surface the "default assumption" field as a required slot — candidates only see that in the PROBLEM.md prose. Adding `Default if unanswered:` directly to the template would raise the quality of questions across the board, since the real signal is whether someone states a position, not just asks.
