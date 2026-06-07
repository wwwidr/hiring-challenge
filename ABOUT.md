# About — KweekGraph

## Ambiguity

**Where to store photos — and how**

Before launching KweekGraph, I had no clear answer on media storage. Supabase was already in the stack for everything else, so storing photos there was the easy path — but at scale, storing full HD images per shoot would become expensive fast. The ambiguity was whether to keep everything in one place for simplicity, or split storage by data type and accept the added complexity. I had no reference point for how active photographers actually use their galleries after delivery, so I couldn't predict access patterns with confidence.

## Tradeoff

**Latency for cost — tiered storage architecture**

I landed on a split architecture: thumbnails in Supabase (fast, always warm), full-HD originals in Cloudflare R2 (cheap, durable). On top of that, I moved photos older than 15 days into cold storage. The tradeoff was deliberate — retrieving an older gallery takes a few extra seconds, but the cost profile stays sustainable even as the photo library grows. For a platform where most access happens in the first week after a shoot, that was a trade worth making.

## Mistake

**Building features before validating the problem**

I spent months adding features to KweekGraph before I talked to a single paying customer. Gallery sharing, watermarking, multi-album organization — I built what I assumed photographers would want. When I finally did talk to users, most of it wasn't what they needed first. The mistake wasn't building the wrong features; it was sequencing product before problem. I now default to identifying a sharp, specific problem, shipping the minimal solution for it, and letting usage tell me what comes next.

## Review comment that changed my mind

**"It's complicated to use"**

One of our first clients told me the app was hard to use. My first instinct was to improve the onboarding flow — add tooltips, write a help doc. But I pushed myself to actually understand the workflow these photographers live in every day. Turns out: WhatsApp. That's where they manage every client relationship, send previews, follow up. I had built a product that asked them to change behavior they'd had for years. I redesigned the delivery interface to mirror WhatsApp's conversation model — familiar thread structure, simple photo send, no new mental model to learn. Retention improved immediately.
