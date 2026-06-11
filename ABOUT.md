# ABOUT.md (put this at the root of your submission repo)

Keep it short and concrete. We read this to understand how you think, not to grade your English.

## Why this role

What excites me about AgentCollect is the commitment to a genuinely AI-native engineering environment where LLMs, tooling, and automated QA are treated as core infrastructure, not just add-on features. I want to build systems where the AI acts as a reliable, production-grade orchestrator that significantly amplifies human leverage

## How you work with AI tools

I treat modern AI tools (like Claude, Cursor, and Gemini) as highly capable junior engineers on my team: exceptional at rapid execution, but lacking systemic context and prone to confident over-engineering if left unguided.

### My Stack & Workflow

- **Code Generation & Scaffolding**: I rely on context-aware tools like Cursor or Copilot for writing boilerplates, generating unit test cases, and rapidly exploring API payloads.

- **Architectural Sparring**: I use LLM chat interfaces (like Gemini/Claude) during Stage A design phases to pressure-test my assumptions, brainstorm potential edge cases, and map out failure modes before writing code.

### When I Trust the Model

- **Routine Automation & Syntax**: I completely trust AI to generate standard regex patterns, SQL schema migrations, and repetitive test data mapping.

- **Refactoring Local Logic**: When a pure function or data transformation logic becomes verbose, I trust AI to refactor it for readability and algorithmic efficiency, provided it is bounded by clear unit tests.

### When I Override / Direct the Model (Engineering Judgment)

- **Architecture & State Boundaries**: AI tends to fixate on the immediate function it is writing, often introducing global state anomalies or tight coupling. I strictly override AI when defining boundaries between system modules or deciding data ownership.

- **Silent Failure Modes**: Models frequently generate code that swallows errors or logs them implicitly without escalating them up the execution stack. In a production pipeline, an unverified contact must fail explicitly and flow into a human-review queue rather than failing silently inside a try/catch block. I constantly audit and rewrite AI error-handling logic to enforce data integrity and strict provenance.

- **Hallucinated Complexities**: When an AI suggests pulling in heavy third-party dependencies or building premature abstractions for simple features, I override it to favor lean, maintainable, and standard idiomatic code.

## Your last project (structured — this is the pre-filter)

- **One ambiguity** you faced and how you resolved it:
- **One tradeoff** you made and why:
- **One mistake** you made and what you changed:
- **One review comment** that made you change your mind:

### Last Project: Task Management Board (React 18 + Spring Boot 3)

A full-stack Kanban application with JWT authentication, drag-and-drop task management,
role-based access control, and real-time search — built as a production-ready portfolio piece.

### One Ambiguity I Faced

**Where should task-move authorization live?**

The `PATCH /tasks/{id}/move` endpoint needed to validate that the user had access to both the source _and_ destination column — but those columns belong to boards, which belong to users. It wasn't clear whether this check belonged in the controller, the service layer, or a Spring Security expression. The spec just said "move task
between columns" without defining cross-board behavior. I had to decide: do we allow moving tasks across different boards? If not, where exactly do we enforce that boundary?

### One Tradeoff I Made

**_Zustand over Redux for frontend state management_**

The README lists Redux/Zustand as options. I chose Zustand for its minimal boilerplate,
which kept the store files lean and let me move fast. The tradeoff: Redux DevTools
and its ecosystem (redux-thunk, redux-saga) are more mature for complex async flows.
If the app scales to real-time collaboration with websockets, Redux's predictabilit would have been the safer long-term choice. For a portfolio project with a single developer, Zustand was the right call — but I documented the decision so a future team could revisit it.

### One Mistake I Made

**_I used `ddl-auto: update` in Spring Boot during early development instead of Flyway migrations_**

It felt faster to let Hibernate auto-generate the schema. The mistake became apparent when I added a `position` column to the `tasks` table — `update` silently added it without backfilling existing rows, breaking the Kanban ordering logic entirely. Switching to Flyway migrations mid-project forced me to reconcile a messy schema history.
I should have set up `ddl-auto: validate` with Flyway from day one.

### One Review Comment That Changed My Mind

**_"Your JWT filter is throwing a 500 on an expired token instead of a 401.
Don't let security exceptions bubble up to the default error handler."_**

My first instinct was that this was an edge case — expired tokens would be rare
in practice. But my reviewer pointed out that automated scanners and mobile clients
with bad clock sync hit this constantly, and a 500 response leaks stack trace details
in staging logs. I refactored the `JwtAuthFilter` to catch `ExpiredJwtException`
explicitly and write a clean 401 response directly on the `HttpServletResponse`,
bypassing Spring's error handler entirely. It made the auth layer more robust
and taught me to treat security boundaries as first-class error surfaces.

## Anything you'd improve about THIS challenge or our CLAUDE.md

<!-- Optional but a strong signal. -->

The challenge is well designed because it evaluates planning, judgment, uncertainty handling, and communication rather than framework-specific knowledge.

One improvement I would consider is adding a small sample output file alongside the mocks. The current requirements are clear, but a concrete example of a successful output row could reduce ambiguity around confidence scoring, provenance representation, and "cannot verify" cases while still leaving room for candidates to make their own design decisions.

I also like the staged approach (Plan - Clarifications - Build). It encourages candidates to think before coding and mirrors how real engineering work happens when requirements are initially incomplete.
