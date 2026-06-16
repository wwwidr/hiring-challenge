# ABOUT.md

## Why this role

I like the train of thoughts that goes with it... with AI native engineering (
and also this problem), its not just calling a model but designing the entire
system around it: verification, provenance, confidence, fallbacks and human
review. This problem interests me weirdly enough because it has a dangerous
failure mode: a wrong answer that looks confident... taking a workflow from
having real business value to losing leads. I enjoy building systems that make
AI useful and the human deploying them to have confidence in the agents and to
acknowledge their capabilities and limitations.

## How you work with AI tools

I use AI tools for planning, implementation velocity, edge-case discovery and
review. I trust them to give me options, scaffold, test and alternative
approaches but I override them when they invent certainty, dont offer provenance
on claims I'm not familiar with or cross boundaries that I've set or that are
clearly inheritable from the business constraints. My usual pattern is to ask
the model for a plan, force it to state assumptions, challenge it and have it
challenge me and verify the outputs with tests, evidence and adherence to the
agreed plan.

## Your last project (structured — this is the pre-filter)

- **One ambiguity** you faced and how you resolved it: we started with one
  client in the insurance industry as a POC. Then before we knew, we had more
  clients interested in our solution. We had to evolve the platform from a
  client-specific implementation into something where each client could plugin
  their own infrastructure, APIs, data source and workflows.

- **One tradeoff** you made and why: we chose contract-based integration layer
  and a data adapter(Plugin-like) pattern so we don't have to revamp the data
  layer for every client. But in order to do this, we had to take the decision
  that we needed a phase to rethink and rebuild the data layer in order to avoid
  more pain later which added design complexity but also made the system easier
  to extend, monitor and operate acrosos clients.

- **One mistake** you made and what you changed: early on, I underestimated how
  quickly “just one client” assumptions become platform constraints, especially
  around data contracts, review queues, confidence thresholds, and
  infrastructure ownership.

- **One review comment** that made you change your mind: someone challenged
  whether we were building a feature or a platform; that pushed me to separate
  core review capabilities from client-specific integrations and think in terms
  of service boundaries, versioned contracts, and long-term extensibility.

## Anything you'd improve about THIS challenge or our CLAUDE.md

1. Fix the borked template link from PROBLEM.md - it points to
   ../PLAN.template.md but the file is inside the challenge folder
2. Consistency for submission evidence: README.md says 2 or 3 mins recoording is
   required while challenge/PROBLEM.md says recording or commit timeline. ( or
   mention that video is a strong filter in both places )
3. CLAUDE is still too laravel/ticket specific. It says all code needs
   tests/Unit or tests/Feature and the PR titles need [TICKET-ID] which
   conflicts with the contact finder task in the challenges folder as it's not
   the same as the contact finder in tickets/ Maybe a split into Engineering
   conventions, Larvel tickets work specifics, Challenge instructions first.
   Maybe even nested CLAUDE.md as claude picks the CLAUDE.md present in sub
   folders too.
4. Add one or two address-disambiguation cases to the mocks. At the moment the
   fixture is keyed by company name so candidates can ignore mailing addres...
   even though address is part of the stated real world input
5. In CLAUDE.md it says "Never use negative words" but i think it can be
   narrowed down to customer-facing product copy, not outputs, logs, dev work or
   complicance sensitive statuses because for the challenge honest takes like
   "cannot verify" or "not found" or "needs human review" are part of it.
