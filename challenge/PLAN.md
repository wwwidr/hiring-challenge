# PLAN.md

## Architecture
The system will be a batch processing pipeline with the following components:
1. **Data Ingestion**: A CSV parser that reads `data/companies.csv`, cleans up formatting (trimming whitespace), and normalizes the input addresses and company names.
2. **Provider Interface (Enrichment Engine)**: An abstraction layer to asynchronously query different contact sources. This uses a worker pool to fetch data concurrently per company to manage latency.
3. **Resolution & Deduplication Service**: A logic layer that aggregates responses, normalizes fuzzy data (e.g. comparing "John D." vs "John Doe"), filters by target roles, and calculates confidence scores.
4. **Output Formatter**: Generates the final output dataset mapped to the requested schema.

## Sources & strategy
To maximize the chance of finding the right contacts while avoiding bad data:
- **Local Business / Directory APIs (Yelp, Google Places, BBB)**: Excellent starting point for small businesses to get a verified phone number or website domain, which is crucial since small businesses often don't have established B2B profiles.
- **B2B Contact APIs (Apollo, Clearbit, ZoomInfo)**: Once we have a domain, we can query B2B providers for specific titles (Owner, CFO, AP).
- **Search API (Google / Bing)**: Used as a fallback for fuzzy searching `company_name + address + "Owner" OR "Manager"` when structured APIs fail.
*Failure modes*: Small businesses might have zero digital footprint, or outdated contacts. We handle this through cascading: attempt directories first -> then B2B providers -> then fallback search.

## Quality
- **Dedupe approach**: We will normalize names (lowercase, removing initials) and contact info (E.164 phone formats, lowering emails).
- **Confidence Scoring (0-100)**:
  - `90-100`: Exact company name and address match, explicit role (e.g., "Owner"), and multi-source agreement.
  - `70-89`: High name/address similarity, generic but relevant role ("Manager"), single trusted source.
  - `<70`: Low confidence match (e.g. only phone found, no specific name, role unclear).
- **Provenance**: We will tag each returned contact with the exact `source` provider it originated from.
- **Cannot verify**: If all sources return empty, or if the best `confidence_score` is below our threshold, the contact is marked with `needs_human_review = true` rather than inventing a hallucinated contact.
- **False-positive risk**: We will heavily penalize results where the contact's geographic location significantly deviates from the provided `mailing_address`.

## Privacy / compliance
- **Will Do**: We will rely exclusively on publicly available professional data or compliant B2B data providers (CCPA/GDPR compliant). We will respect rate limits and standard web scraping boundaries (e.g. `robots.txt`).
- **Will NOT Do**: We will NOT scrape personal social media accounts (Facebook, personal Instagram), guess emails using brute-force SMTP pinging, or purchase non-compliant dark-web lead lists. 

## Clarifying questions

1. **Question: What is the priority order of the target roles?**
   - **Why it matters**: A business might return both an Owner and an AP manager. The owner might ignore billing inquiries, while AP might handle it efficiently (or vice versa depending on authorization).
   - **Default assumption**: Target AP/Billing first as they are most likely to handle unpaid accounts, falling back to Owner/CFO if AP is absent.
   - **What changes if answered**: The Resolution Service will re-weight and sort the filtered contacts to promote the primary role requested over others.

2. **Question: What is the primary channel for outreach?**
   - **Why it matters**: If the logistics company uses a dialer to call them, a verified phone number is paramount. If they use an email sequence, we need email validation and prefer emails.
   - **Default assumption**: Both are equally acceptable, and finding either an email or phone number is considered a success.
   - **What changes if answered**: If email is preferred, we will integrate a strict email validation/bounce-check step and downrank phone-only contacts (and vice versa).

3. **Question: Are there latency and budget constraints per record?**
   - **Why it matters**: Enrichment APIs charge per query. Running a deep waterfall strategy across 5 providers for ~1,000 records might be slow or blow a budget.
   - **Default assumption**: We have a reasonable budget and batch latency is acceptable (e.g., this runs overnight). We will exhaust all sources to maximize the contact hit rate.
   - **What changes if answered**: If there's a strict budget or low latency requirement, we will order sources by cost-effectiveness and short-circuit the enrichment pipeline immediately once a contact hits an 80+ confidence score.
