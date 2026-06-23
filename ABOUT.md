# ABOUT.md — Sameer Ray

## Why this role

AI-native debt collection is a hard, real problem. Voice agents that negotiate,
handle FDCPA compliance across 50 states, and adapt brand voice per Fortune 500
client — this is exactly the kind of AI systems work I want to do. I built
AirDial (production voice AI SaaS: Twilio + Deepgram + ElevenLabs + Claude,
sub-2s latency, Stripe billing) and NexMesh (7,100-line distributed multi-agent
system with MCP servers). AgentCollect's stack is what I have been building toward.

## How I work with AI tools

Daily: Claude Code for architecture reasoning and review, Cursor for in-editor
generation. I trust the model for boilerplate and first drafts. I override it when:

1. It optimizes for the wrong metric — the model defaulted to maximizing contacts
   found. I switched to precision because false positives in debt collection carry
   FDCPA legal risk. That was my call, not the model's.

2. It skips error states — Claude wanted to skip cannot-verify rows. I added
   explicit handling: confidence=0, empty contact, needs_human_review=true.
   Never fabricate is a hard rule I enforced manually.

3. It over-engineers — Claude suggested building a service with async queues.
   I kept it as a CLI script because the problem scope did not warrant it.
   One-time batch, CSV in, CSV out.

## My last project — AirDial (Voice AI SaaS)

- **One ambiguity:** Did not know if latency bottleneck was STT (Deepgram),
  LLM (Claude), or TTS (ElevenLabs). Had to instrument all three before
  architecting. ElevenLabs TTS was the long pole. Switched to streaming output.

- **One tradeoff:** Streaming TTS over batch — cut latency 4s to sub-2s but
  made error handling harder (audio plays before LLM finishes). Accepted it
  because silence kills calls, a mid-sentence correction does not.

- **One mistake:** Stored call state in Python dict, assumed single process.
  Added a second worker and sessions broke silently. Should have used Redis
  from day one. Fixed it, lost 2 days.

- **One review comment:** Had Stripe webhook verification inside the handler.
  Reviewer pointed out: if handler throws before persisting, Stripe retries
  a different code path. Moved to idempotency-keyed event log first, then
  process. Better architecture I would not have reached alone.

## What I would improve about this challenge

CLAUDE.md is Laravel-specific (php artisan, module structure, PR templates).
The Python path has no equivalent conventions doc. A language-agnostic addendum
for non-Laravel submissions would reduce friction. The challenge design itself —
plan-first gating via git timestamps — is the right filter. Hard reject for
diving into code is exactly correct for a team that codes daily with the founder.
