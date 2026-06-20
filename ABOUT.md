# ABOUT.md

## Why this role

What pulls me in is the voice agent problem specifically. Building an AI that handles a FedEx shipping dispute differently from a Microsoft licensing dispute — same pipeline, completely different domain logic, different language, different emotional temperature — is a genuinely hard problem. And the replacement angle matters too: offshore BPOs exist because the alternative was too expensive to automate. AI changes that math. I want to work on something where the technical difficulty and the business disruption are both real, not just one of them.

## How you work with AI tools

My workflow splits by cognitive weight. I use Claude for system design and architecture — data models, storage tradeoffs, API shape — before writing any code. That's where I need reasoning, not just output. For implementation I use Codex for mechanical tasks: boilerplate, repetitive CRUD, test scaffolding. For anything that requires real judgment — debugging a subtle multi-tenant isolation bug, reviewing a complex job queue design — I go back to Opus.

When do I override? When the model is confidently wrong about constraints I know from context. On KweekGraph, Claude suggested storing full HD photos in Supabase Storage — technically correct, cost-prohibitive at scale. I overrode it because I had the domain knowledge it didn't. I always read the full diff before committing. If I can't explain every line, I don't ship it.

## Your last project (KweekGraph — multi-tenant photo delivery platform)

- **One ambiguity** I faced and how I resolved it: No clear answer on where to store photos. Supabase was already in the stack, so storing everything there was the easy path — but full HD at scale would become expensive fast. I had no data on how active photographers actually use galleries after delivery, so I couldn't predict access patterns. I resolved it by splitting: thumbnails in Supabase (fast, always warm), full-HD originals in Cloudflare R2 (cheap, durable), with cold storage after 15 days. Accepted the complexity in exchange for a sustainable cost profile.

- **One tradeoff** I made and why: Latency for cost on older galleries. Retrieving a shoot older than 15 days takes a few extra seconds because it's in cold storage. That's a deliberate trade — most access happens in the first week after delivery, so optimizing for that window and accepting the penalty on cold reads was the right call for the economics.

- **One mistake** I made and what I changed: I spent months building features before talking to a single paying customer. Gallery sharing, watermarking, multi-album organization — I built what I assumed photographers wanted. When I finally talked to users, most of it wasn't what they needed first. The mistake wasn't the features; it was sequencing product before problem. I now default to identifying a sharp, specific problem, shipping the minimal solution, and letting usage tell me what comes next.

- **One review comment** that made me change my mind: A client told me the app was "complicated to use." My first instinct was better onboarding — tooltips, a help doc. Instead I pushed myself to understand their actual workflow. It was WhatsApp. That's where they manage every client relationship. I had built a product that asked them to change years of behavior. I redesigned the delivery interface to mirror WhatsApp's conversation model — familiar thread structure, simple photo send, no new mental model. Retention improved immediately.

## Anything you'd improve about this challenge or the CLAUDE.md

One honest gap: the CLAUDE.md defines what to build and how to structure it, but doesn't specify what good AI tool usage looks like in a PR. You score on it, but the standard is implicit. A candidate who vibe-codes everything with Claude and one who uses it deliberately for architecture and reads every diff — both might produce working code. I'd add a short section to the PR template: "AI usage — what you prompted, where you overrode, what you verified manually." That surfaces judgment, which is what you actually want to see.
