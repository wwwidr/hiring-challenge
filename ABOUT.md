# ABOUT.md

## Why this role

I'm a full-stack product engineer with 15+ years shipping 0-to-1 features for fast-paced US/Canadian startups (I work via a B2B structure and am after a full-time, long-term commitment). The "stack-agnostic, AI-native" framing is exactly how I already work — I treat frameworks as tools for a business problem, not identities. My deepest production experience is TypeScript/Node, but this challenge is in Laravel/PHP and that was a deliberate choice: the problem here isn't the language, it's judgment under ambiguity and honesty about what you can't verify. That's the part I find genuinely interesting.

## How you work with AI tools

I use Claude Code daily to compress ramp-up to near zero (I built [beepulse.io](https://beepulse.io) essentially through it). The signal isn't "I use AI" — it's _where I trust the model and where I override it_. In this very challenge:

- **Plan-first, on purpose.** I committed `PLAN.md` before reading the clarifications or writing a line of solution code, and kept that commit isolated so the process is legible in git.
- **I direct, I don't accept first drafts.** I pushed the model to stress-test edge cases it hadn't surfaced — restart-after-200-rows, idempotency keys, same-name/different-address vs. same-address/different-company — until the design earned its complexity.
- **I override on judgment calls.** I turned a `-45` "magic number" that _happened_ to drop conflicts below threshold into an explicit hard rule (a business invariant shouldn't depend on arithmetic landing right). I rounded the scoring weights to human multiples of 5 so they read as deliberate, not tuned. And I held scope down — the production topology (outbox → queue → workers) is documented but **not** built into the slice, because the brief asked for minimal and honest.
- **I trust the model for the mechanical parts** — scaffolding, DTOs, test boilerplate — and spend my attention on the parts that carry risk: the confidence formula, the "cannot-verify" handling, and scope.

## Your last project (structured)

**AI Meeting Assistant desktop app (Oxolo)** — Tauri + Python, team of two, built from zero. We wore both product and engineering hats.

- **One ambiguity:** Downstream post-meeting features (summaries, action items, analytics) were shifting and ill-defined while we only needed live transcription. Instead of locking into a real-time-only pipeline, I built an intermediary backend ingestion service that piped audio to AssemblyAI for live UI _and_ buffered the raw stream to S3 — decoupling the live event from storage so future post-processing wasn't blocked. (Same decoupling instinct shows up in this challenge's `PLAN.md` outbox design.)
- **One tradeoff:** Real-time speaker diarization vs. accuracy. AssemblyAI's live speaker ID was messy in multi-speaker production calls, so I traded real-time labels for correctness: `pyannote.ai` as an async post-processing pass. UI showed fast text first, then swapped in accurate speaker tags once the meeting ended.
- **One mistake:** First architecture captured mic and system audio as two separate channels transcribed independently — which lost all cross-channel timeline context, so overlapping "who spoke when" was unreconstructable.
- **One review comment that changed my mind:** A colleague pointed out that rebuilding one conversation from two isolated streams after the fact was a losing battle chronologically. We ditched dual-stream entirely and I re-engineered the pipeline to **mix mic + system audio into a single stream before transcription**, preserving conversational timing and giving diarization full context. Accuracy jumped.

## Anything you'd improve about THIS challenge or our CLAUDE.md

Concrete friction I actually hit:

- **`CLAUDE.md` says `php artisan test --parallel`, but ParaTest isn't a dependency** — a fresh clone gets a `RequirementsException`. Either add `brianium/paratest` to `require-dev` or drop `--parallel` from the instructions.
- **A fresh clone can't bootstrap cleanly:** `composer install`'s post-autoload step fails because `bootstrap/cache` is absent and there's no `.env`. Worth committing an empty `bootstrap/cache/.gitignore` and documenting `cp .env.example .env && php artisan key:generate`.
- **The `.gitignore` doesn't exclude runtime artifacts** (`storage/app/*`, `storage/logs`, `bootstrap/cache`), so it's easy to commit generated output by accident.
