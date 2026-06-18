# Contact Finder slice

This is a minimal Node.js CLI implementation for Stage B of the challenge. It uses only the mocked provider fixtures in `challenge/mocks/` and writes one output row per company.

## Run

From the repository root:

```bash
node challenge/contact-finder/index.js
```

Output is written to:

```text
challenge/contact-finder/output.csv
```

## Notes

- Confidence cutoff is 70.
- Rows below 70 return a blank `contact_email_or_phone` and `needs_human_review = true`.
- Human review can also be triggered by conflict, missing contact detail, single-source evidence, missing provenance, or a weak/non-payment-relevant role.
- The `source` column carries `mock://` provenance URLs from the providers.
