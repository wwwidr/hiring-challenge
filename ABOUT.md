# ABOUT.md (put this at the root of your submission repo)

## Why this role
I've built AI agent workflows and enterprise integrations (Laravel/PHP + Kafka) in production for two years, and I use AI tools daily as a core part of how I architect and ship. AgentCollect is one of the few places where the AI infrastructure is an actual product, and the CLAUDE.md conventions and founder-reviewed PRs tell me the engineering bar here matches what I'm looking for.

## How you work with AI tools
I use Claude for architecture, scaffolding, and refactoring. I direct it with explicit constraints and review every output. I trust the model for structure and boilerplate, override it on domain logic and edge cases. For this challenge: used Claude to draft the scoring table and dedup logic, then manually adjusted persona priority and the human review threshold after reviewing the clarifications.

## Your last project (structured — this is the pre-filter)
My last project was titled “Hybrid SQL Hint Generation using AST Analysis, Constraint Modelling and Safe LLM Guidance.”
- **One ambiguity** The hardest design question was how to give helpful hints without revealing the answer. What counts as “non-revealing” varies by student, so I had to decide whether to enforce it through prompting, output filtering, or both, without clear ground truth upfront.
- **One tradeoff** I chose AST-based analysis over semantic similarity for SQL error diagnosis. Semantic similarity was easier to build, but AST analysis better catches structural mistakes like incorrect JOINs or missing GROUP BY clauses. The tradeoff was higher implementation complexity for more accurate feedback.
- **One mistake** Initially, the LLM generated hints without being constrained to the concept under assessment. While accurate, some hints introduced unrelated SQL concepts. I later added concept-based constraints to keep feedback focused. I should have built that from the start.
- **One review comment** My advisor argued that execution differencing was unnecessary since AST analysis already caught most errors. Benchmarking showed it added value in only about 15% of cases. I kept it, but as a secondary signal rather than the primary one.

## Anything you'd improve about THIS challenge or our CLAUDE.md
The `names_match` heuristic is a known gap; "Robert" / "Bob" won't match because the function checks first initial, not nickname pairs. Worth noting in a real submission. The challenge itself is thoughtfully designed. Starting with a planning phase and introducing clarifications later provides a good opportunity to assess how candidates learn, adapt, and refine their approach.
