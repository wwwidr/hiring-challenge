# ABOUT.md

## Why this role

I am interested in AI-native engineering because I like building systems where AI is useful but still needs strong engineering judgment around quality, failure modes, and trust. This contact-finder challenge is interesting to me because the hard part is not just finding data, but deciding when the data is reliable enough to use.

## How you work with AI tools

I use AI tools to speed up planning, implementation, and review, but I do not treat model output as automatically correct. I usually ask AI to generate options, edge cases, and first drafts, then I narrow the solution based on constraints, source evidence, and the actual behavior of the code. For this challenge, I used AI to help structure the plan and implementation, but I kept the main decisions conservative: commit the plan first, use only mocked providers, preserve provenance, and prefer human review over weak guesses.

## Your last project (structured — this is the pre-filter)

Project: **NielAI**, a containerized edge-inference computer vision platform for optimizing and deploying object detection models locally while reducing dataset class imbalance.

- **One ambiguity** you faced and how you resolved it:
  The target "edge deployment environment" was unclear. It was not obvious whether the platform would run only on high-performance GPU gateways or also on resource-constrained IoT nodes. I resolved this with a short discovery phase around baseline hardware profiles, then designed a tiered runtime layer that adapts optimization parameters based on the detected hardware footprint.

- **One tradeoff** you made and why:
  I integrated Vision Language Models through OpenRouter to support data augmentation for imbalanced datasets. The tradeoff was adding an external API dependency during data preparation instead of relying only on local deterministic scripts. I accepted that because it accelerated feature discovery and improved training data quality, which helped us ship the edge pipeline faster.

- **One mistake** you made and what you changed:
  I initially coupled the ingestion pipeline too closely to local file-system storage. Under simulated high-concurrency edge traffic, disk I/O became a bottleneck and increased latency. I fixed this by refactoring ingestion to use Redis as an in-memory staging layer, separating event processing from storage persistence.

- **One review comment** that made you change your mind:
  A senior colleague pointed out that the single-node deployment plan had poor resilience: if an edge gateway crashed mid-inference loop, we would lose state visibility and telemetry sync. That changed my mind. I redesigned the deployment strategy around multi-node Docker Swarm, which added workload failover, improved availability, and reduced cloud infrastructure operating costs through smarter local node scheduling.

## Anything you'd improve about THIS challenge or our CLAUDE.md

The plan-first gate is useful because it forces candidates to show judgment before implementation. One thing I would improve is making the expected Stage B deliverable format slightly more explicit, for example whether an extra `review_reason` column is welcome as supporting evidence.
