# PLAN.md

## Architecture

I would structure this as a contact-resolution pipeline, not as a scraper.

Data flow:

```text
companies.csv
  -> load and validate rows
  -> normalize company name and mailing address
  -> query enrichment providers
  -> convert provider output into a common ContactCandidate shape
  -> deduplicate candidates
  -> score confidence
  -> select best contact or mark cannot verify
  -> write output.csv
```

Main components:

- **CSV loader:** reads company name and mailing address, preserves raw input,
  and attaches a stable row ID.
- **Normalizer:** lightly normalizes company names, addresses, emails, domains,
  and phone numbers for matching.
- **Provider layer:** wraps each enrichment source behind the same interface.
  Provider output is treated as evidence, not truth.
- **Candidate aggregator:** merges candidates from multiple providers,
  deduplicates contacts, and preserves provenance.
- **Scoring engine:** ranks candidates by company match, role relevance, source
  quality, contact completeness, and cross-source agreement.
- **Decision layer:** returns the best candidate if confidence is high enough,
  otherwise marks the row as `needs_human_review`.

I would optimize the first implementation for a small, explainable slice:
deterministic scoring, mocked providers, clear output, and tests around
uncertainty.

## Sources & strategy

In production, I would combine multiple source types because any single source
can be stale, incomplete, or wrong.

Source categories:

- **Company-owned sources:** website, contact page, about/team page, public
  business email, public phone number.
- **Business directories / registries:** useful for company identity, addresses,
  and generic business contact data, but often stale.
- **Licensed enrichment providers:** useful for scale, but must be treated as
  probabilistic and checked for provenance, freshness, and legal usage rights.
- **Internal customer data:** invoices, CRM notes, support tickets, previous
  outreach, payment history, and account metadata if allowed.
- **Human reviewer feedback:** corrections from operations should feed back into
  future scoring.

How they fail:

- Websites may only expose generic inboxes.
- Directories may have stale addresses or old contacts.
- Enrichment providers may return plausible but wrong people.
- Internal data may be incomplete or biased toward previous failed outreach.
- AI-generated enrichment can invent certainty if not constrained.

For Stage B, I will use the mocked providers only. I will design the code as if
those mocked providers represent real enrichment sources.

## Quality

The main quality goal is to avoid false precision. A missing contact or
`cannot verify` state is better than a wrong person that looks confident.

### Dedupe

I would deduplicate by:

- normalized email
- normalized phone number
- normalized full name
- company/domain match
- provider-specific IDs, if available

When duplicates are found, I would merge evidence instead of overwriting it. If
two sources agree, confidence can increase. If they conflict, confidence should
decrease or the row should go to review.

### Confidence score

I would use a deterministic `confidence_score` from 0 to 100.

Initial scoring logic:

- strong company-name match: +25
- address or domain match: +20
- payment-relevant role: +20
- complete contact method: +15
- reliable source: +10
- corroborated by multiple sources: +10
- conflicting provider data: -20
- generic inbox only: -15
- weak company/address match: -25

Preferred role order:

1. Accounts Payable / Billing / Finance
2. CFO / Controller / Finance Director
3. Owner / Founder / Managing Director(Maybe bump this up for small businesses
   since the challenge is for 1k unpaid small businesses)
4. Operations / Office Manager
5. Generic company contact as fallback only

### Provenance

Every accepted contact should have a source. I would not return guessed emails,
untraceable people, or contacts without evidence.

The `source` field should make it clear where the recommendation came from. If
multiple sources contributed, I would preserve that information.

### Cannot verify

I would represent uncertainty with:

- empty or partial contact fields when no safe contact exists
- low `confidence_score`
- `needs_human_review = true`

Rows should go to human review when:

- no candidate is found
- confidence is below threshold
- providers disagree
- only a generic contact is available
- company identity is ambiguous
- role relevance is weak
- address/domain evidence does not match

### False-positive risk

The biggest risk is contacting the wrong person. In payment workflows, this can
waste collector time, create customer frustration, and damage trust. I would
rather route uncertain rows to human review than produce a complete-looking but
unreliable spreadsheet.

## Privacy / compliance

I would:

- use only approved sources
- prefer business contact data over personal contact data
- keep provenance for every contact decision
- minimize stored personal data
- respect provider terms of service
- support suppression, opt-out, and retention rules in a production system
- route uncertain cases to human review

I would not:

- scrape sources that prohibit scraping
- use private or sensitive personal data without legal basis
- infer personal emails without evidence
- return contacts with no provenance
- store unnecessary personal data
- make outreach recommendations that cannot be audited
- pretend AI/vendor enrichment is verified when it is only a candidate signal

## Clarifying questions

1. **Question:** Who is the preferred payment contact persona?

   - Why it matters: The best contact depends on company size and workflow. For
     a small business, the owner may be the right person. For a larger company,
     Accounts Payable or Finance may be better than the CEO.
   - Default assumption: Prioritize Accounts Payable / Billing / Finance first,
     then CFO / Controller, then Owner / Founder, then Operations / Office
     Manager, then generic company contact.
   - What changes if answered: The role-weighting part of the confidence score
     changes.

2. **Question:** Should the system optimize for precision, recall, collector
   efficiency, or payment recovery?

   - Why it matters: A precision-first system returns fewer but safer contacts.
     A recall-first system returns more candidates but creates more review work
     and more risk of wrong outreach.
   - Default assumption: Optimize for precision and trust. Prefer
     `needs_human_review` over a risky automated contact.
   - What changes if answered: The confidence threshold and fallback behavior
     change.

3. **Question:** Which source types are allowed in production?

   - Why it matters: Contact discovery can easily cross privacy, contractual, or
     terms-of-service boundaries. The architecture should not depend on sources
     that cannot be legally or commercially used.
   - Default assumption: Use approved internal data, public business sources,
     and licensed enrichment providers. Do not use private personal data or
     prohibited scraping.
   - What changes if answered: The provider layer changes, and some enrichment
     strategies may be removed entirely.

4. **Question:** What confidence threshold should trigger human review?

   - Why it matters: The threshold controls how much the system automates versus
     how much it escalates. This directly affects operational throughput and
     false-positive risk.
   - Default assumption: Use a conservative threshold, such as 70/100, and route
     anything below that to human review.
   - What changes if answered: The decision layer changes, and the output may
     contain more or fewer review rows.

5. **Question:** How should generic contacts be handled?

   - Why it matters: A generic inbox or main phone number may be useful, but it
     is not the same as finding the person most likely to pay.
   - Default assumption: Return generic contacts only as low-confidence
     fallbacks and mark them for human review unless explicitly allowed.
   - What changes if answered: Generic contacts may become acceptable fallback
     outputs or remain review-only.
