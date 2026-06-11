# PLAN.md (commit this BEFORE reading CLARIFICATIONS.md or writing solution code)

> Delete the prompts below and replace with your own. Keep it tight.

## Architecture
<!-- How would you structure a system that takes the CSV and returns decision-maker contacts? Components, data flow. -->
**Input (Reading Data):**
The system first reads the *companies.csv* file line by line. Each company is taken and added as a "task" into an Input Queue.

**Concurrency & Rate Limiting:**
In the background, there is a Thread Pool or Async Workers system. These workers take companies from the queue and process them at the same time. To avoid being blocked by servers (API/Web), a Rate Limiter controls how fast the workers take data from the queue.

**Data Enrichment:**
Each worker uses the company name and address to search on the internet (using test services in Stage B). It tries to find contact information of important people like CEO, CFO, or Owner.

**Output & Database:**
To avoid data problems and not lose data, each worker sends results to an Output Queue. One single writer takes data from this queue and safely saves it into a SQLite database (with Companies and Contacts tables) or as a JSON file.

**Final Result:**
When the process finishes, the data is exported from the database. The final output is clean and organized, showing each company with its correct contacts (for example, in CSV or JSON format).


## Sources & strategy
<!-- What kinds of sources would you combine and why? How do they fail? (You'll use the mocked providers in Stage B.) -->
**Layer 1: Local Directories & Map Services**

Strategy: First, we use the raw company name and mailing address data in this layer. The goal is to verify the physical address and find the company’s official website URL (domain), official phone number, or registered full business name.

Goal: Convert raw text data into a searchable digital identity (URL) on the internet.

**Layer 2: Automated Web Crawling & String Parsing**

Strategy: Automated workers (crawlers/bots) send requests to the website URLs found in Layer 1. The system scans static pages such as About Us, Contact, and Our Team. Then, it uses Regular Expressions (Regex) and Pattern Matching to extract company email addresses (for example, @companyname.com) and the names of decision-makers.

Goal: Automatically transform the company’s publicly available information into useful and structured data.

**Layer 3: Professional Networks & B2B Databases**

Strategy: Using the company name or domain obtained from Layer 1, searches are performed on professional business networks (such as LinkedIn-style databases). The system filters active employees whose job titles contain keywords like Owner, CEO, CFO, Founder, or Manager, and collects their names and positions.

Goal: Identify key decision-makers and leadership contacts within the company.

**Cross-Referencing & Validation Strategy**

Strategy: Contact information collected from different layers is compared and verified. For example, a name found on the company website is checked against information from professional networks. Using a Majority Voting approach, people whose information matches across multiple sources are given higher priority and selected for the final dataset.

Goal: Improve data accuracy and reliability through cross-validation from multiple independent sources.

## Quality
<!-- Dedupe approach. Your confidence_score logic. Provenance. How you represent "cannot verify". False-positive risk. -->
**Deduplication**
To avoid duplicate processing, the database will enforce a UNIQUE constraint on the contact's identifier (e.g., email address). 
Workers will utilize INSERT OR IGNORE SQL queries, offloading the deduplication workload entirely to the database engine and avoiding any race conditions or complex in-memory locks.

**Confidence Scoring & False-Positive Risk**
Every extracted contact will receive a confidence score (0-100) based on source reliability (e.g., official website cross-checked with professional network profiles). 
Data with scores below a certain threshold will be flagged for manual review to prevent false positives.

**Provenance & Cannot-Verify State** 
Every record will have metadata showing its exact source URL and timestamp. 
Accounts with zero digital presence will be explicitly marked as UNVERIFIED rather than being left empty, 
ensuring clear transparency.

## Privacy / compliance
<!-- What you will and will NOT do. -->

**What We WOULD Do** 
We will only extract publicly available business data (B2B).
We will rely on official company websites, public professional network directories,
and registered business listings where contact information is intentionally shared for commercial purposes.

**What We WOULD NOT Do**
We will never scrape or store private Personally Identifiable Information (PII) such as personal phone numbers,
private emails (e-mail addresses ending in @gmail.com, @yahoo.com, etc.), or non-professional social media profiles.
We will strictly avoid using leaked or unverified third-party databases and will respect target servers' robots.txt directives to maintain ethical 
scraping boundaries

## Clarifying questions
<!-- For EACH question: (a) why it matters, (b) your default assumption if unanswered, (c) what changes in your design depending on the answer. 3 sharp > 15 shallow. -->

1. **Question:** What are the budget or cost constraints regarding third-party API usage (e.g., Maps/Data Enrichment APIs)?
   - **Why it matters:** It dictates whether we should build a high-precision but costly multi-API lookup pipeline or optimize for a cost-effective, selective search strategy.
   - **Default assumption:** We assume there are no strict budget constraints for this volume (~1,000 records), so we will design the pipeline to prioritize maximum data accuracy using layered lookups.
   - **What changes if answered:** If budget constraints are tight, we will shift toward open-source/free alternatives and reduce the number of external API calls per record.

2. **Question:** Do you prefer a single primary contact per account, or should we extract multiple decision-makers (e.g., both CFO and CEO) if available?
   - **Why it matters:** This directly affects our database schema design and data collection depth per company.
   - **Default assumption:** We assume that collecting multiple relevant stakeholders is better for driving payments, so we will use a One-to-Many schema to store all discovered decision-makers.
   - **What changes if answered:** If only a single primary contact is needed, we will simplify our database schema to a One-to-One relationship and stop searching once the top-ranking executive is found.

3. **Question:** What is the minimum acceptable confidence score threshold for a contact to be considered "verified"?
   - **Why it matters:** It defines the boundaries between automated approval and logging data into the output versus flagging uncertain results for manual verification.
   - **Default assumption:** We will enforce a default threshold of 60%. Any result scoring below 60% will be filtered out from the final automated list.
   - **What changes if answered:** If a higher threshold (e.g., 80%) is required, our filters will tighten, reducing the volume of the final output but significantly increasing data precision.

4. **Question:** Should the system process and stream the input data in chunks (batching), or is it acceptable to load the initial dataset into memory at once?
   - **Why it matters:** If the input dataset scales significantly in the future, loading everything into RAM at once will cause memory leaks or crashes. Processing data line-by-line keeps the RAM footprint low and constant.
   - **Default assumption:** Since our current dataset is small (~1,000 records), we assume loading it initially into our input queue is acceptable for this scope.
   - **What changes if answered:** If streaming/batching is strictly required, we will rewrite the input reader layer to use lazy iterators instead of a bulk array load, ensuring a constant $O(1)$ memory complexity.
