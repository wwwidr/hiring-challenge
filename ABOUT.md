# ABOUT.md

## Why this role
AgentCollect is the exact problem I want to work on. I have been
building AI pipelines, multi-agent workflows, LLM-as-judge ranking,
voice assistants, and debt collection is one of the few domains where
AI agents can fully replace a broken manual process. The stack
(voice agents, Claude-powered negotiation, recovery pipelines) is
exactly what I want to be close to.

## How I work with AI tools
I use AI as a pair programmer, not an autocomplete. I write the
interface and the intent first, then direct the model to implement it.
I read every line it produces and push back when it drifts. For this
challenge I used Cursor to generate the finder.py implementation after
I had already designed the scoring model and pipeline structure myself.

## Last project: Conversational Vision Assistant
GitHub: https://github.com/aswinjojo/AI-Vision-Assistant
Demo: https://youtu.be/LcbvKIGiKhc
Stack: FastAPI · React/TypeScript · GPT-4o-mini · WebSocket · OpenAI TTS

**One ambiguity:** The spec said "real-time" but did not define it.
Low latency per turn, or continuous awareness between turns? I scoped
it as turn based, right for a voice assistant, and documented the
WebRTC path for continuous scene awareness if needed.

**One tradeoff:** SQLite over Postgres. Deliberate, keeps the demo
runnable without Docker or cloud dependencies. Storage is behind a
repository interface so swapping to Postgres is one line. Built for
change without over-engineering day one.

**One mistake:** I waited for the browser onend silence event before
sending a turn. That added 1 to 3 seconds of dead air every turn.
Switched to a 600ms debounce on final transcript results, the single
biggest latency win in the project.

**One review comment that changed my mind:** A reviewer flagged that
I was re-sending image frames in conversation history. I thought visual
context should persist across turns. They were right. Prior images
grow token usage quadratically with no benefit. Now only text responses
are stored in the memory window.

## What I would improve about this challenge
I would add a small test fixture alongside the mocks: a few companies
with known expected outputs so candidates can verify their scoring
logic without guessing. Even two or three rows covering a clean
three-source match, a registered-agent-only hit, and a not-found case
would catch most off-by-one mistakes in the confidence math before
submission. It also makes the precision-over-recall design goal
concrete rather than abstract.
