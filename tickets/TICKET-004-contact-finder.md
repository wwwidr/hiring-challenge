# TICKET-004: Contact Finder at Scale

> This is the only ticket we review for new hires right now. Ignore TICKET-001/002/003 — they are archived.

## The problem

Respaid helps B2B companies recover unpaid invoices. Half the pain of our job is **finding the right person to contact**. Clients hand us spreadsheets where half the rows have nothing but a company name and an address. Somewhere inside that company there is a decision-maker who has the authority to pay — a CFO, a Controller, an AP Manager, a small-business owner. We need their name, work email, and direct phone.

This ticket asks you to solve that problem on a real sample.

## Input

`sample.csv` — 50 real US companies, 25 small businesses and 25 mid-to-large brands. Three tiers of input:

| Tier | Rows | Input | What you need to find |
|---|---|---|---|
| 1 | 20 | company_name + address | contact_name, contact_role, work_email, direct_phone |
| 2 | 20 | company_name + address + generic email (`info@`, `contact@`) | real name behind the inbox + direct phone |
| 3 | 10 | company_name + address + generic email + switchboard phone | a better phone (direct line) + the named decision-maker |

Mix of SMB and mid-large in each tier. Different company sizes need different strategies — that's the point.

## Output

A single `output.csv` with these columns:

```
row_id, company_name, contact_name, contact_role, work_email, direct_phone, confidence_0_to_1, sources, verified
```

- `sources` — pipe-separated list of every source you used for that row (e.g. `google_places | linkedin_sales_nav_trial | hunter_free | smtp_verify`)
- `verified` — `true` only if you actually confirmed the email (SMTP handshake, reply, or equivalent) AND the phone (test call, OSINT cross-reference, or equivalent). A contact on paper that you didn't verify = `false`.

## Constraints

- **Budget: $0 of paid APIs.** Free tiers only. Prove you can be creative under a spending cap. Candidates who paid for Hunter/Apollo/Clay/RocketReach will be asked to redo it on free tiers.
- **Time cap: 90 minutes of coding + 30 minutes running your pipeline.** We are evaluating thinking, not grinding.
- **Stack: your choice.** Python, Node, bash, Go, a shell script with `curl`, whatever. No Laravel requirement for this ticket.

## Deliverables (all four mandatory)

1. **Private repo** with your code — runnable, `README.md` at the root explaining how to run it and what you built.
2. **`output.csv`** — 50 rows enriched, using the exact schema above.
3. **Screen recording with your camera on and your voice narrating**:
   - Face visible in a corner of the screen
   - You explaining, in real time, what you are doing and **why**
   - Raw — no edits, no polished presentation
   - Max 60 minutes
4. **`NOTES.md`** — written reflection, 1–2 pages:
   - Per-tier coverage % (how many rows got any contact)
   - Per-tier precision % (of 10 random rows you manually verified, how many were correct)
   - Cost per row (should be $0 or near-zero)
   - Time per row
   - "What would break at 10,000 rows, and how would I fix it?"

## How we evaluate

- **Creativity beats polish.** A hacky, verified, high-coverage pipeline beats a clean one with generic answers.
- **"Sub-agents" and "web search" are not answers.** Those are labels. We want to see the actual chain: which tool first, which fallback, how you verify, what you do when the primary fails.
- **We watch the whole video.** The 50% of the evaluation is *how* you think and adapt, not just the final CSV. Fake-perfect edited videos fail instantly.
- **SMB vs mid-large must use different strategies.** A single pipeline applied to both = no signal on judgment.
- **AI is welcome, but the video must show YOU driving it.** We want to see your prompts, your rejections, your iterations. A candidate who only pastes AI output without thinking will fail the anti-fraud check.

## Anti-fraud check (explicit)

We make offers fast — 48 hours from submission to yes/no. In return, we verify upfront that **you** built what you submitted:
- The video must show your face and your voice. No stand-ins.
- We may follow up with a 15-minute live call where we ask you to extend your pipeline on the spot with a tweak (e.g. "add a WHOIS fallback for Tier 1"). No video = no call = no offer.
- If the `output.csv` quality does not match the skill shown on the video, we stop there.

This is not adversarial. It's how we hire fast without hiring fraud.

## Submission

Do NOT open a PR on this repo. Your work must be in a private repo so other candidates cannot see it.

1. Create a **private** GitHub repo with your code + `output.csv` + `NOTES.md`.
2. Add `wwwidr` as a collaborator (Settings → Collaborators → add `wwwidr`).
3. Reply to our job listing or the email thread with:
   - Link to your private repo
   - Link to your screen recording (Loom unlisted, YouTube unlisted, or Google Drive)
   - One sentence: "Camera was on during the full recording — confirmed."

You will hear back within 48 hours.
