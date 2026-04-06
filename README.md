# Respaid Hiring Challenge

> **Note:** Your submission is reviewed automatically by our AI system within 24h.

## Setup
1. Clone this repo (do NOT fork it publicly)
2. `composer install`
3. `cp .env.example .env && php artisan key:generate`
4. `php artisan migrate`
5. `php artisan test` (should pass)

## Your Task
1. Read `CLAUDE.md` - this is how we work. Follow it.
2. Pick ONE ticket from the `/tickets` folder
3. Create your OWN private GitHub repo
4. Push your code with a clean branch: `feat/TICKET-ID-your-name`
5. Add an `ABOUT.md` file to your repo (template below)
6. Screen record your entire session

## ABOUT.md Template (REQUIRED)

Create an `ABOUT.md` at the root of your repo with these 5 sections:

```markdown
# About Me

## Why Respaid (3 sentences max)
[Why this role, why now]

## PHP/Laravel experience
[X years. Be specific about Laravel versions you've shipped to production]

## My AI workflow
[Which tools you use daily (Claude Code, Cursor, MCP, etc.) and how. 2-3 lines]

## Something I shipped I'm proud of
[1 paragraph + link or screenshot. Real production work]

## What I'd improve about your CLAUDE.md
[Optional but strong signal. After reading our CLAUDE.md, what would you change?]
```

Submissions without an `ABOUT.md` are auto-declined. We use this to filter for engineers who follow instructions.

## How to Submit
**Do NOT open a PR on this repo.** Your submission must be in a private repo so other candidates cannot see your work.

1. Create a **private** GitHub repo with your solution
2. Add `wwwidr` as a collaborator (Settings > Collaborators > Add `wwwidr`)
3. Reply to the job listing on YC Work at a Startup with:
   - Link to your private repo
   - Link to your screen recording (Loom, YouTube unlisted, or asciinema)

## What We Evaluate
- **ABOUT.md quality** - did you fill all 5 sections with substance?
- Did you follow CLAUDE.md conventions?
- Did you write tests?
- Did you check your work before submitting?
- How do you use AI tools?
- Your commit quality and cleanliness
- **Your screen recording (50% of the evaluation)** - we watch HOW you work
