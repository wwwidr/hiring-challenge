# NOTES.md

## Pipeline

- Read the company CSV and the mock provider JSON. For each company, pull whatever the three providers returned (registry, listing, enrichment) — any of them can be missing or have null fields.
- Group the names across sources loosely so nickname and initial variants collapse to one person (Bob = Robert, "S. Murphy" = Sean Murphy), then pick the best candidate by role.
- Score confidence from the evidence: agreement between sources is the biggest factor, then whether there's a real contact channel, then role, then a small bounded nudge from the enrichment tool's own score.
- If two sources name genuinely different people, that's a conflict — cap it below the threshold and flag it instead of picking one. Same for a lone unbacked enrichment guess.
- Write a row per company with the name, role, email or phone, confidence, which providers it came from, and a needs-human-review flag (with a reason) for anything under 70 or that can't be verified. On the sample data that comes out to 5 confident and 25 flagged, which is the point — precision over recall.

## Why these sources

Three sources because they're wrong in different ways, and that's exactly what makes cross-referencing worth anything.

- Registry is good for the legal name but often gives a registered agent who doesn't pay the bills, and tiny businesses aren't always in there.
- Listing usually gets a phone, sometimes a name, often no role.
- Enrichment gives an email or phone but it's a guess with its own confidence, and a clean-looking email is the easiest thing to be fooled by — so I treat its number as one weak input, never the answer.

When two of these independently land on the same person, that agreement is the strongest signal I've got. One source alone doesn't clear the bar unless something backs it up.

## Next 30 minutes

- A second enrichment source used purely as a tie-breaker — when two sources disagree on the person, a third independent read is what would actually resolve it instead of just flagging.
- A feedback loop: when a human reviews a flagged row and confirms or corrects it, feed that back so the confidence weights get better over time instead of staying hand-tuned.
- A suppression / opt-out list checked before anything is emitted, since that's a real requirement for this kind of outreach.
- Basic run monitoring — how many verified vs flagged per batch, so a sudden swing in that ratio is visible rather than silent.

The theme is making the system better at verifying itself, not adding a person to check rows by hand.

## Confidence formula

Plain version of how the number adds up:

- A base for having any real, attributable contact at all.
- The biggest chunk when two sources independently name the same person.
- Some for actually having an email or phone, a little more when it lines up with an agreed name.
- A role bonus — owner or AP beats a manager beats a registered agent beats no role.
- A small nudge up or down from the enrichment tool's own confidence, capped so it can never push something over the line on its own.
- A hard cap below the threshold when sources name different people, or when all I've got is a single unverified guess.

Threshold is 70. Below that, the contact field comes back empty with needs-human-review set and a short reason. Nothing gets emitted that I can't trace to a source.
