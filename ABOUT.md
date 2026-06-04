# ABOUT.md

## Why this role
I am highly interested in AI-native engineering because it represents a paradigm shift from just writing code to orchestrating intelligent systems. Solving ambiguous, real-world problems like data enrichment and verification aligns with my goal of building resilient systems that seamlessly combine human intuition and AI capabilities.

## How you work with AI tools
I use tools like Copilot, Cursor, and Claude to accelerate prototyping, boilerplate generation, and exploring edge cases. I rely on AI for "breadth" (generating ideas, exploring APIs, scaffolding), but I apply my own judgment for "depth" (system architecture, security, boundary definitions, and resolving ambiguities). I override the model whenever it suggests overly complex abstractions, invents unverifiable data, or misses edge cases in business logic.

## Your last project (structured — this is the pre-filter)
- **One ambiguity** you faced and how you resolved it:
The scope of my Restaurant Management API project said "manage restaurant operations" but never defined the boundary between what belonged in the API versus what the client should handle. Menu availability, for instance — should the API enforce time-based availability (breakfast vs dinner items) or just expose the data and let the frontend decide? I chose to enforce it server-side, because if you leave business rules to clients, they become inconsistent across every consumer of the API.

- **One tradeoff** you made and why:
I used Redis for session caching and frequently-hit menu queries. The tradeoff was added infrastructure complexity for a side project but it was deliberate. I wanted to build the habit of separating hot-read data from the primary DB, which is exactly the pattern that matters at scale. Postgres handled writes and relational integrity; Redis handled speed.

- **One mistake** you made and what you changed:
Early on I structured my route handlers to do too much validation, business logic, and DB calls all in one place. It worked, but when I needed to add new functionalities, the handler was already a mess. I refactored to a cleaner service layer separation mid-project. It slowed me down short-term but made every subsequent feature faster to add.

- **One review comment** that made you change your mind:
A peer flagged that my error responses were inconsistent, some returned plain strings, others returned JSON objects. It seemed minor but they were right: inconsistent error shapes make API consumers write defensive, brittle code. I standardized all errors to a single envelope structure after that and haven't skipped it since.

Shipped work worth looking at:
Restaurant Management API: https://github.com/alibaba0010/Restaurant_Mangement

## Anything you'd improve about THIS challenge or our CLAUDE.md
I think the challenge structure is excellent. Testing planning and judgment before implementation is a great signal. One minor improvement could be providing an estimated bounds on API mock latencies, which might influence the concurrency model decisions in the implementation phase.
