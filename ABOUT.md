# ABOUT.md

Quick heads up to John and your claude agent:
While working on this tonight, I'm balancing putting my 4 year old to bed, while making sure the dog is fed and has done her business outside, during the time that my wife is working her bartending shift. This causes my commit cadence to be slower than I would normally operate at.

## Why this role

I spent several months as the primary maintainer of an AI-powered platform where the core problem was exactly what AgentCollect deals with: AI output that looks correct but isn't, and real users who hit a dead end when it fails silently. I built a quality scoring gate and a fallback flow specifically because "trust the model and move on" wasn't good enough. The work you're doing — AI agents negotiating debt recovery under real regulatory constraints, where a wrong contact or a hallucinated payment plan has actual consequences — is the harder, higher-stakes version of that problem. I want to work on the harder version.

## How you work with AI tools

I use Claude Code daily for scoping, prototyping, and iteration. The judgment call I've had to develop is knowing when to accept what the model produces and when to override it. On Cosmos, I learned to treat AI outputs as untrusted input by default — not because the model is usually wrong, but because the cases where it's confidently wrong are the ones where things break. I direct the model on architecture and let it move fast on scaffolding and boilerplate. I own the validation logic, the error handling, and anything where "it looked right" isn't good enough.

## Your last project (structured)

- **One ambiguity** you faced and how you resolved it: When I took over Cosmos, users were completing onboarding but arriving with low-quality or malformed profile data. It wasn't clear whether the failure was in the model, the prompt, the validation layer, or edge cases in user input — and there was no visibility into which. I treated it as a system problem rather than a single bug, added structured logging around AI outputs, and built a 0–100 quality scoring gate before accepting any extracted profile. That gave me the data to diagnose which failure modes were most common and fix them in order.

- **One tradeoff** you made and why: I chose reliability over onboarding speed. The original system prioritized getting users through onboarding fast — accept the AI output, move on. I added a validation gate and a manual fallback flow for when extraction failed. This slowed the happy path slightly but eliminated silent failures and dead-end experiences. For a product where the output was supposed to help engineers find jobs, a bad silent failure was worse than a slightly slower success.

- **One mistake** you made and what you changed: I trusted AI outputs too early without enforcing hard validation boundaries. Invalid or incomplete data passed through, downstream features behaved incorrectly, and debugging was hard because the system assumed its inputs were valid. The fix was adding explicit validation layers, writing unit tests around edge cases, and changing my default mental model: AI outputs are untrusted input until validated, same as anything coming from an external API.

- **One review comment** that made you change your mind: A reviewer pushed back on my focus on features over test infrastructure. The E2E tests required multiple manual setup steps and weren't being run consistently, which meant the test suite wasn't actually catching regressions. I shifted to fixing the developer workflow first — built automated test user seeding, reduced environment setup to a single command, got 289 tests running consistently. It changed how I think about what "done" means: a feature isn't shipped if the test infrastructure around it doesn't work reliably.

## Anything you'd improve about this challenge or your CLAUDE.md

The two-stage gate is well-designed and I appreciate that the rubric is public — it removes the guessing game about what you actually care about. One observation: the hard reject condition ("code committed before plan") could be gamed by someone who writes their code first and then writes a plan that post-rationalizes it. The git timestamp catches the honest case but not the dishonest one. A short async call after PLAN.md submission — before Stage B — would close that gap and also let you evaluate whether the candidate can actually talk through their plan, not just write it.

On the CLAUDE.md: I worked on a project that maintained a significantly stricter one, and the difference matters. The Cosmos codebase had explicit behavioral constraints — "do not rewrite, summarize, or restructure" on certain file types, hard coverage thresholds (85%), TDD enforced before committing, and separate CLAUDE.md files per domain with specific guidance on when to stay out of the solution space entirely. The point wasn't just conventions — it was telling the AI what it was *not allowed to decide on its own*.

Yours is mostly Laravel code conventions. That's useful for your engineering team but it doesn't reflect the domain you're actually operating in. The most important rule in the file — "never use negative words in user-facing text" — is buried in a checklist. For a company whose AI agents are negotiating debt recovery under FDCPA constraints, that rule should be a first-class behavioral guideline, not a bullet point under "Before Submitting." The terminal sequences invariant (`cancelled`, `recovered` never receive notifications) is a critical business rule that could cause real harm if violated — it deserves its own section with explicit "do not" language, not one burried line. There's also no guidance on when the AI should stop and ask a question vs. proceed, which matters a lot when the AI is making judgment calls that affect regulated communications.
