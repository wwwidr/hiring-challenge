# Notes

## Scope: what's intentionally not here

No web UI. The plan floated a small React frontend, but the judgment this exercise tests
lives in the scoring / provenance / suppression layer, not in chrome — so a thin CLI plus
one HTTP endpoint demonstrates the behavior without expanding scope toward a CRM. A UI,
file upload, persistence, and an opt-out intake endpoint are deferred as conscious
follow-ups, not oversights. The goal was a small, honest, well-tested slice over the
mocked providers, not a build-out.

## Known boundaries & tradeoffs

These are deliberate scoping decisions in the current slice, recorded so they're visible
rather than mistaken for oversights.

1. **Contact-level suppression matches the emitted value, not every identifier.**
   A suppressed email/phone blanks the contact only when it is the value we would have
   emitted for that company. If a suppressed phone is on record but the email is what we'd
   emit, the email is still emitted. This is output-correct (we never emit a suppressed
   value), but a stricter compliance model would suppress a person across *all* their known
   identifiers. Worth hardening before real opt-out data flows in.

2. **Suppression takes precedence over the confidence threshold when labeling a row.**
   A row that is both below threshold *and* on the suppression list is labeled `suppressed`,
   not `review`. This is intentional: suppression is a hard compliance fact and should be the
   recorded reason a contact was withheld, distinct from "we weren't confident enough."

3. **Provider lookup is exact-match on `company_name`.**
   The input CSV name must match the provider key exactly. There is no fuzzy company-name
   resolution, so punctuation/casing drift between the input and the providers would read as
   "not found." Acceptable for the mocked dataset; real ingestion would need normalization.

4. **Contact selection prefers a corroborated phone, then email, then a single-source phone.**
   When no phone is corroborated across sources, an enrichment email is chosen ahead of a
   lone listing phone. This is a product choice (a name-attributable email is usually a better
   first touch than an unattributed business line), not a correctness constraint — the
   ordering lives in one place and is easy to change.
