# PLAN.md

## Architecture

```
CSV (name + address)
       │
1. Normalize  →  clean and standardize company name and address
       │
2. Registry lookup  →  query public business-registration records
       │
3. Web / map listing  →  cross-check with local-business directories
       │
4. Contact enrichment  →  find a named person and their email or phone
       │
5. Score + emit  →  merge signals, assign confidence, flag uncertain rows
```

Each step is independently fallible; the pipeline continues if a step returns nothing and simply lowers confidence for that row.

## Sources

| Source type               | Why it helps                                    | Main failure mode                              |
|---------------------------|-------------------------------------------------|------------------------------------------------|
| State business registry   | Official owner-of-record for LLCs               | Often stale; LLCs can obscure real individuals |
| Local web / maps listing  | Phone, sometimes a named contact                | Outdated or lists a generic front-desk number  |
| Professional network      | Named person with a stated role                 | Many small businesses are not registered there |
| Email / phone enricher    | Formats a direct contact address                | Pattern-derived; not independently confirmed   |

## Quality

**Confidence:** additive and explicit. Start at zero; add points for each independent source that confirms a name, a role, and a contact method. More agreeing sources = higher score. A single enrichment guess stays low. The exact threshold between "return this" and "flag for review" is question 3 below.

**Duplicates:** if two sources return the same person, merge them and record both in provenance. If they disagree on the person, keep the match with higher confidence and note the conflict.

**Unverifiable rows:** if no source confirms a real named contact, leave the contact blank and flag for review. A high review rate on hard rows is honest, not a failure. Every output field records which source it came from; nothing is filled in without attribution.

## Privacy / compliance

- Business contact info only: no home addresses, personal cell phones, or data outside the company's public identity.
- Record the source of every value so the output is fully auditable.
- Check suppression and opt out status before any row is returned.
- Do not infer anything about personal characteristics; the goal is a business role, not a personal profile.

## Clarifying questions

1. **Is there a preferred priority order among decision-maker roles?**
   - Why it matters: determines whether I stop at the first match or prefer one role, and how I score conflicting results.
   - Default assumption: owner or founder is primary for small businesses; office manager is a last resort.
   - What changes: if AP manager is preferred, I reorder role matching and treat owner-only results as lower confidence.
2. **Is a phone number acceptable, or is email strictly required?**
   - Why it matters: some sources return a business phone but no email; that changes whether those rows resolve or go to review.
   - Default assumption: either is fine as long as it reaches the right person.
   - What changes: phone-only results get routed to human review rather than returned as resolved.
3. **Where should the confidence threshold sit?**
   - Why it matters: this single number defines what ships versus what gets flagged; too low sends guesses and too high floods the review queue.
   - Default assumption: roughly 60/100, meaning two independent sources need to agree before a contact is returned.
   - What changes: lower threshold means more output with more risk; higher means fewer results but better precision.
