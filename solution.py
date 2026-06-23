#!/usr/bin/env python3
"""
Contact Finder — AgentCollect Hiring Challenge
Author: Sameer Ray | 2026-06-07
Stage B: Built after reading CLARIFICATIONS.md
Precision-first: threshold=70, provenance on every field, no fabrication.
"""

import json, csv, re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional

CONFIDENCE_THRESHOLD = 70
DATA_CSV   = Path("challenge/data/companies.csv")
MOCKS_JSON = Path("challenge/mocks/enrichment_responses.json")
OUTPUT_CSV = Path("output/contacts.csv")

@dataclass
class Contact:
    company_name: str
    contact_name: Optional[str]      = None
    contact_role: Optional[str]      = None
    contact_email_or_phone: str      = ""
    confidence_score: int            = 0
    source: str                      = ""
    needs_human_review: bool         = True
    _breakdown: dict                 = field(default_factory=dict)

def normalize_phone(p):
    if not p: return None
    d = re.sub(r"\D", "", p)
    if len(d) == 10: return f"+1{d}"
    if len(d) == 11 and d[0] == "1": return f"+{d}"
    return p

def normalize_email(e):
    return e.strip().lower() if e else None

def compute_confidence(registry, listing, enrichment):
    score = 0
    breakdown = {}
    provenance = {}

    if registry and registry.get("name"):
        score += 40
        breakdown["registry_name_role"] = +40
        provenance["name"] = registry.get("source_url", "mock://registry")
        provenance["role"] = registry.get("source_url", "mock://registry")

    if listing and listing.get("phone"):
        score += 20
        breakdown["listing_phone"] = +20
        provenance["listing_phone"] = listing.get("source_url", "mock://listing")

    if enrichment:
        if enrichment.get("email"):
            score += 25
            breakdown["enrichment_email"] = +25
            provenance["email"] = enrichment.get("source_url", "mock://enrichment")
            pc = enrichment.get("provider_confidence", 50)
            if pc >= 80:
                score += 15
                breakdown["provider_confidence_bonus"] = +15
            elif pc < 50:
                score -= 10
                breakdown["provider_confidence_penalty"] = -10
        elif enrichment.get("phone"):
            score += 15
            breakdown["enrichment_phone"] = +15
            provenance["enr_phone"] = enrichment.get("source_url", "mock://enrichment")

    if registry and enrichment and enrichment.get("email"):
        name_parts = (registry.get("name") or "").lower().split()
        email = (enrichment.get("email") or "").lower()
        if any(len(p) > 2 and p in email for p in name_parts):
            score += 15
            breakdown["name_email_agreement"] = +15

    if listing and enrichment:
        lp = normalize_phone(listing.get("phone"))
        ep = normalize_phone(enrichment.get("phone"))
        if lp and ep and lp == ep:
            score += 10
            breakdown["phone_agreement"] = +10

    if not (registry and registry.get("role")):
        score -= 5
        breakdown["no_role_penalty"] = -5

    return max(0, min(100, score)), breakdown, provenance

def enrich(name, mocks):
    c = Contact(company_name=name)
    data = mocks.get(name, {})
    registry   = data.get("registry")
    listing    = data.get("listing")
    enrichment = data.get("enrichment")

    if not any([registry, listing, enrichment]):
        c.source = "not_found"
        c._breakdown = {"note": "no provider returned data"}
        return c

    score, breakdown, provenance = compute_confidence(registry, listing, enrichment)
    c.confidence_score = score
    c._breakdown = {**breakdown, "provenance": provenance}

    if registry and registry.get("name"):
        c.contact_name = registry["name"]
        c.contact_role = registry.get("role") or "Owner"
    elif listing and listing.get("name"):
        c.contact_name = listing["name"]
        c.contact_role = "Business Contact"

    sources = []
    if enrichment and enrichment.get("email"):
        c.contact_email_or_phone = normalize_email(enrichment["email"])
        sources.append(enrichment.get("source_url","mock://enrichment"))
    elif enrichment and enrichment.get("phone"):
        c.contact_email_or_phone = normalize_phone(enrichment["phone"])
        sources.append(enrichment.get("source_url","mock://enrichment"))
    elif listing and listing.get("phone"):
        c.contact_email_or_phone = normalize_phone(listing["phone"])
        sources.append(listing.get("source_url","mock://listing"))

    for src in [registry, listing]:
        if src and src.get("source_url") and src["source_url"] not in sources:
            sources.append(src["source_url"])

    c.source = " | ".join(s for s in sources if s)

    if score < CONFIDENCE_THRESHOLD:
        c.needs_human_review = True
        c.contact_email_or_phone = ""
    else:
        c.needs_human_review = False

    return c

def main():
    with open(MOCKS_JSON) as f:
        mocks = json.load(f)

    companies = []
    with open(DATA_CSV, newline="", encoding="utf-8") as f:
        reader = csv.DictReader(f, delimiter=",")
        for row in reader:
            companies.append(row["company_name"].strip())

    results = [enrich(name, mocks) for name in companies]

    OUTPUT_CSV.parent.mkdir(exist_ok=True)
    fields = ["company_name","contact_name","contact_role",
              "contact_email_or_phone","confidence_score","source","needs_human_review"]

    with open(OUTPUT_CSV, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        for c in results:
            w.writerow({
                "company_name":           c.company_name,
                "contact_name":           c.contact_name or "",
                "contact_role":           c.contact_role or "",
                "contact_email_or_phone": c.contact_email_or_phone,
                "confidence_score":       c.confidence_score,
                "source":                 c.source,
                "needs_human_review":     str(c.needs_human_review).lower(),
            })

    total    = len(results)
    verified = sum(1 for r in results if not r.needs_human_review)
    review   = sum(1 for r in results if r.needs_human_review)
    nofind   = sum(1 for r in results if r.source == "not_found")

    print(f"\n{chr(61)*60}")
    print(f" Contact Finder — Sameer Ray")
    print(f"{chr(61)*60}")
    print(f" Total:              {total}")
    print(f" Contacts verified:  {verified}")
    print(f" Needs human review: {review}  (cannot-verify: {nofind})")
    print(f" Threshold:          {CONFIDENCE_THRESHOLD}")
    print(f" Output:             {OUTPUT_CSV}")
    print(f"{chr(61)*60}\n")
    print(f"{chr(45)*80}")
    print(f"  {'Company':<34} {'Score':>5} {'Review':>7}  Contact")
    print(f"{chr(45)*80}")
    for r in results:
        contact = r.contact_email_or_phone or r.contact_name or "NOT FOUND"
        print(f"  {r.company_name:<34} {r.confidence_score:>5} "
              f"{'YES' if r.needs_human_review else 'NO':>7}  {contact}")

if __name__ == "__main__":
    main()
