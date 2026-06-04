# PLAN.md

## Architecture

I would build this as a small pipeline using the Strategy pattern for providers.

The process begins by reading and normalizing company information from the input CSV. Multiple independent sources are then consulted for each company. Candidate contacts are collected, compared, deduplicated, and evaluated based on the quality and consistency of the available evidence.

The system assigns a confidence score to each candidate and determines whether the contact can be considered verified or requires human review. All outputs retain provenance information so every returned value can be traced back to its source.


Possible outcomes for each company are:

1. Verified contact.
2. Needs human review.
3. Cannot verify.

### Main components flow:

- `CsvReader`: reads `company_name` and `mailing_address`.
- `CompanyNormalizer`: cleans names and addresses for matching.
- `ContactProvider`: shared interface for all sources.
- `RegistryProvider`, `ListingProvider`, `EnrichmentProvider`: provider strategies.
- `CandidateMerger`: combines results and removes duplicates.
- `ConfidenceScorer`: calculates a final 0-100 score.
- `ResultWriter`: writes the output rows.


## Sources & strategy

No single source should be considered authoritative.

The system should combine multiple independent sources because each source has different strengths and weaknesses.

-  Business registry sources are useful for identifying company ownership or official business records, but information may be outdated.
- Business listings are useful for validating company existence and business contact details, but often contain generic information.
- Enrichment providers can help discover contact information, but may return low-confidence matches or incomplete data.
- Company websites can provide public business contact information and role verification when allowed.

For this challenge, only the mocked providers will be used. No real APIs, scraping, or external services should be used.

The strategy is to gather evidence from multiple sources, compare results, and increase confidence when independent sources agree on the same contact information.

## Quality
The system should optimize for precision over recall. A verified contact is more valuable than multiple unverified guesses.

### Confidence Scoring

Confidence should be based on the quality, consistency, and independence of the evidence.

Factors that increase confidence include:

* Agreement across multiple independent sources.
* Matching company information across sources.
* Presence of a decision-maker role aligned with the target hierarchy.
* Direct business contact information that can be traced to a source.
* Consistent names, roles, phone numbers, or email addresses.

Factors that reduce confidence include:

* Information coming from only one source.
* Missing decision-maker role.
* Conflicting contact information between sources.
* Weak source confidence.
* Inability to verify the relationship between the contact and the company.

The final score should be transparent, explainable, and capped between 0 and 100.

### Dedupe

Duplicate candidates should be merged using normalized company names, contact names, email addresses, phone numbers, and role information.

### Provenance

Every returned value should retain provenance information showing where it originated.

This allows every contact, phone number, email address, and role to be traced back to the supporting source.

### Cannot Verify

If evidence is insufficient, conflicting, or below the accepted confidence threshold, the system should not return a contact as verified.

Instead, the contact information should remain empty and the record should be flagged for human review.

The system should be explicit about uncertainty rather than presenting low-confidence guesses as facts.


### Initial Confidence Scoring Approach

For the first version I would use a transparent rule-based scorer, not a complex model. In a later production version, I would consider ideas from Entity Resolution / Record Linkage, especially Fellegi-Sunter-style probabilistic matching, if we need stronger matching across many noisy sources.

- Base by source:
  - Registry match: +30.
  - Listing match: +20.
  - Enrichment match: +20 max, scaled by provider confidence.
- Agreement:
  - Same name across 2 sources: +20.
  - Same phone/email across sources: +15.
  - Strong company/address match: +10.
- Contact quality:
  - Has decision-maker role: +10.
  - Has direct email or phone: +10.
  - Generic phone only: +3.
- Risk penalties:
  - Missing role: -10.
  - Only one source: -15.
  - provider confidence below 60: -20.
  - Conflicting names between sources: -25.
- Final score is clamped to 0-100.


Every returned value should keep provenance through its source_url. If a contact cannot be verified or is below the accepted threshold, I would leave contact fields empty and set needs human review as true.


## Privacy / compliance

I would only use approved business-relevant sources and public company information.

I would not:

- scrape private or personal social media,
- guess contacts without marking low confidence,
- bypass source terms or robots.txt,
- send automated outreach to low-confidence contacts,
- hide where a value came from.

The system should be honest about uncertainty.

## Clarifying questions

1. **Question:** Who is the preferred contact persona?
   - Why it matters: owner, CFO, AP manager, and office manager are different search targets.
   - Default assumption: for small businesses, prioritize owner first, then office/AP manager, then CFO.
   - What changes if answered: provider ranking and confidence scoring would favor the confirmed persona.

2. **Question:** What confidence threshold is acceptable before outreach?
   - Why it matters: a wrong contact can damage the collections process.
   - Default assumption: anything below 70 should require human review.
   - What changes if answered: the `needs_human_review` rule and output behavior would change.

3. **Question:** Which sources are allowed in production?
   - Why it matters: contact enrichment has compliance, privacy, and terms-of-service risk.
   - Default assumption: use only business registries, business listings, company websites, and approved enrichment vendors.
   - What changes if answered: the provider list and provenance rules would change.

4. **Question:** Should low-confidence candidates be returned, or should contact fields stay empty?
   - Why it matters: some teams want leads for manual review, but showing weak guesses as contacts is risky.
   - Default assumption: leave contact fields empty when confidence is below threshold.
   - What changes if answered: I could add a separate `candidate_contact` field for review while keeping verified fields clean.
