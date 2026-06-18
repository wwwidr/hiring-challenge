# PLAN.md

## Architecture

I would build this as a small enrichment pipeline: read the CSV, normalize each company, ask a few source providers for possible contacts, score the candidates, and output the best answer with enough evidence to explain it.

The main pieces:

1. **Input reader**
   - Read `company_name` and `mailing_address` from the CSV.
   - Keep the original values unchanged.
   - Add a stable row ID so results can always be traced back to the input.

2. **Normalizer**
   - Create matching keys for company names, for example lowercasing and removing obvious suffixes like Inc/LLC when safe.
   - Normalize addresses enough to compare location signals.
   - Be conservative. I do not want normalization to accidentally merge two different businesses with similar names.

3. **Provider layer**
   - Wrap each source behind a common interface.
   - Each provider returns zero or more candidates plus the evidence it used.
   - Providers should be treated as useful but fallible. No single provider should be trusted blindly.

4. **Candidate scoring**
   - Score candidates based on company match, address/location match, role relevance, contact detail quality, source reliability, provenance, and agreement across sources.
   - Prefer contacts that are both payment-relevant and clearly tied to the input company.

5. **Dedupe / merge**
   - Merge obvious duplicates, especially when email or phone matches exactly.
   - Keep conflicting evidence instead of hiding it, because conflicts are often the reason a row should go to review.

6. **Output**
   - Return one best contact per company.
   - Output `contact_name`, `contact_role`, `contact_email_or_phone`, `confidence_score`, `source`, and `needs_human_review`.
   - If the system cannot verify a contact, say so through the output instead of guessing.

For the implementation slice, I would probably use Node.js. It is a good fit for a small CLI-style CSV/JSON pipeline, and it keeps the solution lightweight. I would still keep the design simple enough that the same approach could be moved into another stack later.

## Sources & strategy

I would not expect one source to solve this well. Small-business contact data is messy, so the value comes from combining imperfect signals and being honest about uncertainty.

The source types I would design around are:

1. **Business registry or company profile data**
   - Good for confirming company identity, address, legal name, ownership, or status.
   - Weaknesses: records can be stale, legal names can differ from operating names, and registered agents are often not the right person to contact.

2. **Official website or public business listing data**
   - Good for business phone numbers, billing emails, contact pages, and location confirmation.
   - Weaknesses: websites can be outdated, generic, missing, or shared across locations.

3. **Contact enrichment / professional data**
   - Good for finding named people with finance, owner, or office-management roles.
   - Weaknesses: roles change, inferred titles can be wrong, and same-name people can be matched to the wrong company.

My approach would be conservative:

- Prefer contacts supported by more than one independent signal.
- Prefer business contact information over inferred personal contact information.
- Prefer payment-relevant roles.
- Send hard or ambiguous rows to human review rather than making the output look more complete than it really is.

## Quality

### Key assumptions before clarification

1. **Precision matters more than coverage**
   - I would rather return fewer contacts with clear evidence than fill every row with weak guesses.
   - Ambiguous rows should stay reviewable.

2. **One best contact per company is enough**
   - The main output should select the strongest contact for each company.
   - Other candidates can be kept internally for audit/debugging, but the required output should stay simple.

3. **Human review is not only a score threshold**
   - Low-confidence rows need review, but review should also happen when sources conflict, the company/address match is ambiguous, contact details are missing, provenance is incomplete, or the role is not clearly payment-relevant.

4. **Address/location match is a major guardrail**
   - A company-name match alone is not enough when the address or location points somewhere else.
   - This matters a lot for small businesses with similar names.

5. **Generic business contacts can be useful fallback options**
   - A clearly company-owned AP/billing email or main office phone may be useful if no named decision-maker is verified.
   - I would score these lower than a verified named contact and may still mark them for review depending on the evidence.

6. **Cannot-verify is an acceptable result**
   - If the evidence is not strong enough, the system should explicitly represent that state.
   - I would not invent names, roles, emails, or phone numbers to improve apparent coverage.

### Dedupe

For company rows, I would dedupe with normalized company name plus address/location. I would be careful not to merge rows just because the names look similar.

For contacts, I would first merge exact email or phone matches. After that, I would only merge same-name contacts when the company context also matches strongly. A false merge is worse than having two candidates that need review.

### Confidence scoring

I would use an explainable 0-100 score. My starting weights would be:

- Company identity match: up to 25
- Address/location match: up to 20
- Role relevance: up to 20
- Contact detail quality: up to 15
- Source reliability and provenance: up to 10
- Cross-source agreement: up to 10

Risk factors would reduce or cap the score:

- ambiguous company name
- address/location mismatch
- role is inferred instead of stated
- only one weak source supports the candidate
- conflicting source evidence
- generic or incomplete contact detail
- stale-looking evidence

Some cases should require human review even if the score is close to the threshold:

- the selected contact has no usable email or phone
- sources disagree on the company or contact identity
- the selected contact detail has no provenance
- the match depends only on company name without address/location support

A high score should mean the contact is likely correct, traceable, and useful for the payment workflow. It should not just mean the record looks complete.

### Provenance

Every selected value should be traceable. Internally, I would keep:

- source/provider name
- evidence fields used
- which source supplied each selected value
- score factors
- conflict or uncertainty notes

Even if the final output has a compact `source` field, I would keep enough detail to explain why the contact was selected.

### Cannot-verify handling

If no candidate is trustworthy enough, the row should still be returned. The contact fields can be blank where appropriate, confidence should be low, and `needs_human_review` should be true.

Common reasons:

- no candidate found
- only irrelevant roles found
- missing email/phone
- ambiguous company match
- address mismatch
- conflicting sources
- insufficient provenance

## Privacy / compliance

I would:

- use business-relevant contact data only
- keep provenance for every selected value
- design for opt-out and suppression lists
- minimize retained personal data
- avoid putting unnecessary personal data in logs
- make uncertain results reviewable before outreach
- respect source terms, rate limits, robots.txt, and applicable privacy laws in a production system

I would not:

- use personal or home contact data
- infer identity from protected characteristics
- fabricate missing contacts
- use dark-pattern or restricted scraping
- store raw provider data indefinitely without a clear purpose
- treat unverifiable inferred contacts as reliable

## Clarifying questions

1. **Question:** Which payment decision-maker should be prioritized when several plausible contacts are found?
   - Why it matters: This affects scoring and tie-breaking.
   - Default assumption: Prioritize AP/accounts payable first, then owner/founder for small businesses, then CFO/finance lead, then office manager.
   - What changes if answered: I would adjust role weights, fallback rules, and review behavior.

2. **Question:** What confidence threshold should allow a contact to be used without human review?
   - Why it matters: This sets the tradeoff between coverage and false positives.
   - Default assumption: Use a conservative threshold around 70; anything below that needs review.
   - What changes if answered: I would tune score cutoffs, caps, and how low-confidence rows are output.

3. **Question:** Are generic business contacts acceptable when no named decision-maker is verified?
   - Why it matters: Many small businesses may only expose a billing email or main office phone.
   - Default assumption: Generic AP/billing emails or main office phones are acceptable fallback contacts if clearly tied to the company.
   - What changes if answered: If allowed, I would include them as fallback candidates. If not, I would return cannot-verify for those rows.

4. **Question:** Which source categories are allowed or off-limits in production?
   - Why it matters: Compliance and source terms determine what providers can be used.
   - Default assumption: Use first-party records, official business websites, business registries, compliant directories, and approved enrichment providers.
   - What changes if answered: I would add or remove provider adapters and adjust compliance checks.

5. **Question:** Is the main goal maximum coverage, lowest false-positive rate, lowest cost, or fastest processing?
   - Why it matters: These goals lead to different thresholds and provider ordering.
   - Default assumption: Optimize for precision and traceability over recall.
   - What changes if answered: I would tune source ordering, review thresholds, and how borderline contacts are handled.
