# ABOUT.md

## Why this role

I'm drawn to AI-native engineering because the hardest problems are no longer "can we build this" but "how do we know we can trust what we built." Contact enrichment is a perfect example: the pipeline is simple; the judgment — what counts as verified, when to surface uncertainty instead of a confident-looking wrong answer — is where the real work is. That gap between "it returned something" and "it returned something trustworthy" is exactly where I want to spend my time.

## How I work with AI tools

I use Claude as a reasoning partner, not a code generator. Concretely: I give it the full context (mock schema, scoring constraints, the conflict cases I spotted in the data) and ask it to draft code, then I read every line before it lands. I override it when it over-engineers (it wanted to add an async provider layer before I'd even validated the sync path worked), and I push back when it glosses over edge cases (it initially treated "Registered Agent" the same as "Owner" — I caught that in the role-priority table and corrected it). The scorer's nickname map and email-local-part matching logic both went through two rounds of revision where I rejected the first draft and tightened the boundary conditions.

## My last project (structured)

- **One ambiguity I faced and how I resolved it:**
  We were building a payment-status notification system where "cancelled" and "recovered" terminals needed to be excluded from outreach. The spec said "don't notify cancelled accounts" but didn't define what events could arrive on an already-cancelled terminal. I resolved it by writing the invariant explicitly as a test — any event on a cancelled or recovered terminal that triggers a notification is a test failure — and then built the gate logic to satisfy the test, not the other way around. The test became the spec.

- **One tradeoff I made and why:**
  In a multi-step DB operation I chose to hold a transaction open across three tables rather than using eventual-consistency with a job queue. The queue approach would have been more scalable, but the data set was small enough that lock contention was never a real risk, and the transaction gave us atomicity guarantees the queue couldn't. I documented the scale boundary explicitly so the next person would know when to revisit it.

- **One mistake I made and what I changed:**
  I shipped a confidence scorer that used the provider's self-reported confidence as the final score — no cross-source validation, no penalty for generic contacts. It looked fine on the happy-path rows. It was only when I added the "cannot-verify" test fixtures that I realized the score was meaningless: a generic `info@` email with provider_confidence=80 was sailing through as verified. I rebuilt the formula around cross-source agreement as the primary signal, with provider confidence as just the base.

- **One review comment that changed my mind:**
  A reviewer pointed out that my initial output nulled contact fields AND omitted the row's source URLs when needs_human_review was true. His comment: "the reviewer still needs to know where you looked, even if you found nothing." He was right — I changed the schema so sources are always carried through, even on zero-result rows, so reviewers can see that three sources were checked and all came back empty rather than wondering if the pipeline skipped the company.

## Anything I'd improve about this challenge or the CLAUDE.md

The challenge is well-constructed — the staged gating (plan before code, clarifications before build) is a genuine filter, not theater. One small improvement: the CLAUDE.md convention "Never use negative words in user-facing text" is a good rule but it conflicts with the `needs_human_review` field name, which is technically internal/system-facing. Worth clarifying whether that rule applies to API/internal field names or only to copy shown to end users.
