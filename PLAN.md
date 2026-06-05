# PLAN.md

## Architecture
I'll build this as a few stages rather one big function. This will allow me to differentiate between "who is this person actually" and "how sure am I". These are different questions and I don't want to conflate them.

Rough flow:
- Read the CSV, clean up the company names (trimming, lowercasing, etc.) so I can get a stable key to look things up with
- For each company, hit the three providers: registry, listing, enrichment. For each provider. None of them are reliable on their own, so I need to go through each one check if one is missing or returns junk data, and then use the others to fill in the gaps.
- Take whatever comes back and figure out the actual person: group the names across the sources (not too strictly, more loosely because of initials, nicknames, variations, etc), then I'll pick the best one by role
- Use the algorithm to see how confident I am, separate from the step above
- Write out the row: name, role, phone or email, confidence score, the sources, whether it needs a human review, and if so why.

So basically, CSV -> clean -> query providers -> figure out the personn -> score confidence -> write out results. The values I write need to have a source URL. If I can't find the source, it doesn't go in the output.

## Sources & strategy
Three sources and I will use all of them. They can be wrong in different ways, but when they agree that's usually a good sign.

- Registry: this is the official registry of companies. It's usually pretty accurate for the legal name, but sometimes it can give me the registered agent - a lawyer or filling company, not the actual person paying the bills.
So I will trust the name, but not the title unless it's clearly the owner or officer.
- Listing: is the web listing. Usually get a phone number, sometimes a name, sometimes no role or just a generic number. It's fine as a way to reach them, but it's pretty weak as a way to figure out who they are.
- Enrichment is basically the email/phone lookup tool. It will hand me a guess plus it's confidence score on the guess. It's often wrong; sometimes it might give a nice looking email but it's not actually the right email and it doesn't work. So I treat the confidence score as a very rough signal, or a weak input into my own confidence score, but I don't take it at face value.

I'm using everything I can get, but I have to be careful about how much I trust each source and how I combine them. The more they agree, the more confident I can be, but if they disagree or if one of them gives me a weird result, I need to be cautious. So it's mostly corroboration.

## Quality
Dedupeing is important here. I will group the candidates by company by comparing them loosely: lowercaseing them, remove title like "Dr." or "Mr.", stuff like nicknames or initials (with last name matching) will need to be expanded. Same group = Same person.

Confidence:
- If two sources agree on the name, that's a strong signal and the biggest signal
- Some points for having the email or phone, moreso if it lines up with the name
- Some points for the role: if one of them says "owner" or "CEO" or something like that, that's a strong signal. If they say "manager" or "registered agent", that's a weaker signal.
- The enrichment confidence score is a very weak signal, so i can use it to nudge the confidence up or down, but I won't take it at face value.
- If one of the sources gives me a really weird result (like a name that doesn't match the company at all, or a role that doesn't make sense, or two conficting sources), that's a negative signal. It should knock the score down below the threshold for human review.

Provenance: every field I output will trace to at least one source. If I can't find a source for it, it doesn't go in the output. This is important for both quality and for compliance reasons.

## Privacy / compliance
What I will do: US business contacts, business info only, keep a source URL for everything, keep a human in the loop of anything shady, support a suppression list.

Things I will not do: Don't touch anyone's personal info or home info, guess someone's identity, scrape anything from shady sources, or do anything that could be considered doxxing or harassment. 

No real scraping involved, it's all mocks, but i'd still like it to hold up.

## Clarifying questions

1. **Question: How bad is a wrong contact compared to a missing contact?**
   - Why it matters: A wrong contact being reached is basically wasting resources, it can cause compliance issues. So the question decides how much I should be cautious when scoring
   - Default assumption: A wrong contact is worse than a missing contact, because it can lead to wasted time, frustration, and potential damage to the relationship with the company. A missing contact can be followed up on or researched further, but a wrong contact can lead to dead ends or even negative interactions if they are contacted by mistake.
   - What changes if answered: If a wrong contact is really costly, I'll need two agreeing sources before anything will count as ready. If it's not that bad, I can be more lenient and allow a single source to be enough if it's a strong source.
2. **Question: If I have a row that I cannot confirm, what do you want me to hand to the reviewer?**
   - Why it matters: This determines how much work the human reviewer has to do. If I can give them a clear summary of the evidence and the confidence score, they can make a more informed decision. If I just give them the raw data, they have to do more work to figure out to do, and they might be tempted to just rubber-stamp it.
   - Default assumption: I'll attach the best guess with it's low score and source URLs, but I'll leave the actual contact field empty with a good reaso, so that they are informed but nothing looks "pre-approved".
   - What changes if answered: If you want them to go though things fast, I can give them a short ranked list instead of one guess. If you want no anchoring, I can just give them the raw data and let them make their own decision, but that might lead to more variability in the results.
3. **Question: How loosely should I match names, and how much should a role override a match?
   - Why it matters: The could have a nickname and intial that are the same person, there could be a case where two sources are basically different people, and registered agents who aren't the actual contact. So I need to decide how much to trust the name matching vs the role matching.
   - Default assumption: I'll match names pretty loosely, allowing for initials, nicknames, and minor variations, as long as the last name matches. Different names are obviously a conflict. But for registed agents and roleless contacts, I'll push them lower than actual owners and AP people.
   - What changes if answered: If you want to be really cautious, I can require a pretty close name match and a strong role to consider it a match. If you want to be more lenient, I can allow for looser name matching and give more weight to the role, even if the name is different.
