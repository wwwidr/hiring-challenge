#!/usr/bin/env python3
"""
contact_finder.py — Stage B: Contact Finder (AgentCollect hiring challenge)
===========================================================================
Reads challenge/data/companies.csv and challenge/mocks/enrichment_responses.json,
merges signals from three mock providers (registry, listing, enrichment),
scores each company's best decision-maker contact, and writes output.

Design notes (adapted from PLAN.md after reading CLARIFICATIONS.md):
  - Threshold updated to 70 (CLARIFICATIONS.md)
  - Role priority: AP Manager > Owner/Founder > CFO > Office Manager > Registered Agent
  - Precision over recall: below threshold → empty contact, needs_human_review=true
  - Every contact value is attributed to a source_url (provenance requirement)
  - Name agreement across sources is the strongest corroboration signal

Usage:
    python challenge/contact_finder.py
    python challenge/contact_finder.py --input path/to/companies.csv --output path/to/out.csv
"""

import argparse
import csv
import json
import os
import re
from dataclasses import dataclass, field
from typing import Optional

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
BASE_DIR   = os.path.dirname(os.path.abspath(__file__))
CSV_PATH   = os.path.join(BASE_DIR, "data", "companies.csv")
MOCKS_PATH = os.path.join(BASE_DIR, "mocks", "enrichment_responses.json")
OUTPUT_DIR = os.path.join(BASE_DIR, "output")
OUTPUT_CSV = os.path.join(OUTPUT_DIR, "contacts.csv")

# ---------------------------------------------------------------------------
# Constants (per CLARIFICATIONS.md)
# ---------------------------------------------------------------------------
CONFIDENCE_THRESHOLD = 70  # below this → needs_human_review=true, contact cleared

# Role priority per CLARIFICATIONS.md:
# AP Manager first, then owner/founder for small biz, CFO/finance for larger,
# office manager as fallback. Registered Agent is a legal formality — lowest.
ROLE_PRIORITY: dict[str, int] = {
    "accounts payable": 5,
    "ap manager":       5,
    "cfo":              4,
    "chief financial":  4,
    "finance":          4,
    "owner":            3,
    "founder":          3,
    "president":        3,   # small-biz president ≈ owner
    "office manager":   2,
    "manager":          1,
    "registered agent": 0,   # legal formality, not the AP contact
}

# Nickname normalisation for fuzzy name matching
NICKNAMES: dict[str, str] = {
    "bob":   "robert", "rob":   "robert",
    "jim":   "james",  "jimmy": "james",
    "bill":  "william","will":  "william",
    "mike":  "michael",
    "tom":   "thomas",
    "dan":   "daniel",
    "dave":  "david",
    "sue":   "susan",
    "liz":   "elizabeth", "beth": "elizabeth",
    "kate":  "katherine", "kathy": "katherine",
    "jen":   "jennifer",
    "joe":   "joseph",
    "sam":   "samuel",
    "pat":   "patricia",
}


# ---------------------------------------------------------------------------
# Data types
# ---------------------------------------------------------------------------

@dataclass
class ProviderData:
    """Raw data from a single mock provider for one company."""
    name:                Optional[str] = None
    role:                Optional[str] = None
    phone:               Optional[str] = None
    email:               Optional[str] = None
    provider_confidence: int           = 0
    source_url:          Optional[str] = None


@dataclass
class ScoredContact:
    """Final scored contact for one company."""
    contact_name:            str
    contact_role:            str
    contact_email_or_phone:  str          # cleared to "" when needs_human_review
    confidence_score:        int
    sources:                 list[str]
    needs_human_review:      bool
    score_breakdown:         str          # human-readable explanation of score
    raw_contact_channel:     str          # preserved for human reviewers even when flagged


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def company_slug(name: str) -> str:
    """
    Convert company name to a stable slug for domain plausibility checks.
    Strips legal suffixes (LLC, Inc, Co) before slugging.
    """
    lower = name.lower()
    lower = re.sub(
        r"\b(llc|inc|co\.?|corp|ltd|group|services?|shop|supply|clinic)\b", "", lower
    )
    return re.sub(r"[^a-z]+", "-", lower).strip("-")


def role_priority(role: Optional[str]) -> int:
    """Return priority weight for a role string. Higher = closer to AP decision-maker."""
    if not role:
        return 0
    lower = role.lower()
    for key, weight in ROLE_PRIORITY.items():
        if key in lower:
            return weight
    return 1  # labelled-but-unknown role beats an unlabelled one


def normalise_for_compare(name: Optional[str]) -> list[str]:
    """
    Tokenise, lowercase, strip punctuation, and expand nicknames.
    Returns canonical tokens for comparison.
    """
    if not name:
        return []
    clean = re.sub(r"[^a-z ]", "", name.lower()).strip()
    return [NICKNAMES.get(t, t) for t in clean.split() if t]


def names_agree(a: Optional[str], b: Optional[str]) -> bool:
    """
    Return True if two name strings most likely refer to the same person.

    Handles:
      - Exact match after normalisation  ("Daniel Ortega" == "Daniel Ortega")
      - Nickname expansion               ("Bob Kowalski"  ≈ "Robert Kowalski")
      - First-initial abbreviation       ("S. Murphy"     ≈ "Sean Murphy")
      - Surname-only match (weak signal, used for scoring)
    """
    if not a or not b:
        return False

    ta = normalise_for_compare(a)
    tb = normalise_for_compare(b)

    if not ta or not tb:
        return False

    # Full token-set match (order-insensitive)
    if set(ta) == set(tb):
        return True

    # Abbreviated first name: one list has a single-char token where the other
    # has the full token starting with that character.
    def abbreviated_match(short: list[str], full: list[str]) -> bool:
        if len(short) != len(full):
            return False
        for s, f in zip(short, full):
            if len(s) == 1:
                if not f.startswith(s):
                    return False
            elif s != f:
                return False
        return True

    if abbreviated_match(ta, tb) or abbreviated_match(tb, ta):
        return True

    # Surname match only (last token) — weaker but still a positive signal
    if ta[-1] == tb[-1]:
        return True

    return False


def email_domain_matches_slug(email: Optional[str], slug: str) -> bool:
    """
    Return True if the email's domain plausibly contains slug words.
    Light corroboration signal — not a hard validation.
    """
    if not email or "@" not in email:
        return False
    domain = email.split("@", 1)[1].lower()
    domain_stem = re.sub(r"\.(com|net|io|biz|co|org|us|me)$", "", domain)
    slug_words  = [w for w in slug.split("-") if len(w) > 3]  # skip short noise words
    return any(w in domain_stem for w in slug_words)


def parse_provider(raw: Optional[dict]) -> Optional[ProviderData]:
    """Safely coerce a raw provider dict into typed ProviderData. Returns None if absent."""
    if raw is None:
        return None
    return ProviderData(
        name                = raw.get("name"),
        role                = raw.get("role"),
        phone               = raw.get("phone"),
        email               = raw.get("email"),
        provider_confidence = raw.get("provider_confidence", 0),
        source_url          = raw.get("source_url"),
    )


# ---------------------------------------------------------------------------
# Scoring engine
# ---------------------------------------------------------------------------

def score_company(
    registry:   Optional[ProviderData],
    listing:    Optional[ProviderData],
    enrichment: Optional[ProviderData],
    slug: str,
) -> ScoredContact:
    """
    Merge three provider responses into a single scored contact.

    Scoring philosophy (PLAN.md §Quality, adapted from CLARIFICATIONS.md):
      - More independent agreeing sources → higher score
      - Registry is most authoritative for identity; enrichment for contact channel
      - Name agreement is the strongest cross-source corroboration signal
      - Registered Agent role is penalised (legal formality, not the AP contact)
      - Sole enrichment with low provider confidence is penalised heavily
      - Conflicts between sources depress score and surface for human review
      - Below threshold: contact fields cleared (precision > recall)
    """
    score     = 0
    sources:    list[str] = []
    breakdown:  list[str] = []

    # ── extract raw values ─────────────────────────────────────────────────
    reg_name  = registry.name     if registry   else None
    reg_role  = registry.role     if registry   else None
    lst_name  = listing.name      if listing    else None
    lst_phone = listing.phone     if listing    else None
    enr_email = enrichment.email  if enrichment else None
    enr_phone = enrichment.phone  if enrichment else None
    enr_conf  = enrichment.provider_confidence if enrichment else 0

    # ── registry signals ───────────────────────────────────────────────────
    if reg_name and registry and registry.source_url:
        score += 30
        sources.append(registry.source_url)
        breakdown.append(f"+30 registry name ({reg_name} / {reg_role})")

        if reg_role and "registered agent" in reg_role.lower():
            score -= 10
            breakdown.append("-10 registered-agent role penalty")

    # ── listing signals ────────────────────────────────────────────────────
    if lst_name and listing and listing.source_url:
        score += 20
        if listing.source_url not in sources:
            sources.append(listing.source_url)
        breakdown.append(f"+20 listing name ({lst_name})")

        if reg_name:
            if names_agree(reg_name, lst_name):
                score += 15
                breakdown.append("+15 name agreement registry ≈ listing")
            else:
                # Irreconcilable conflict — two different people claimed
                score -= 20
                breakdown.append(f"-20 name conflict ({reg_name!r} ≠ {lst_name!r})")

    elif listing and listing.source_url and lst_phone:
        # Listing is present but name-less — phone is still useful provenance
        if listing.source_url not in sources:
            sources.append(listing.source_url)

    # ── enrichment signals ─────────────────────────────────────────────────
    if enr_email and enrichment and enrichment.source_url:
        score += 15
        if enrichment.source_url not in sources:
            sources.append(enrichment.source_url)
        breakdown.append(f"+15 enrichment email ({enr_email})")

        if email_domain_matches_slug(enr_email, slug):
            score += 5
            breakdown.append("+5 email domain matches company slug")

        if enr_conf >= 80:
            score += 5
            breakdown.append(f"+5 provider confidence ≥ 80 ({enr_conf})")

        # Heavy penalty: enrichment is the ONLY signal and it's not confident
        if not reg_name and not lst_name and enr_conf < 50:
            score -= 20
            breakdown.append(f"-20 sole-enrichment with low confidence ({enr_conf})")

    if enr_phone and enrichment and enrichment.source_url:
        score += 10
        if enrichment.source_url not in sources:
            sources.append(enrichment.source_url)
        breakdown.append("+10 enrichment phone")

    # ── cross-source contact attribution bonus ─────────────────────────────
    # We have a verified identity (name from any source) AND a reachable channel
    # (email or phone from any source) without an unresolved name conflict.
    name_conflict = bool(
        reg_name and lst_name and not names_agree(reg_name, lst_name)
    )
    has_name    = bool(reg_name or lst_name)
    has_channel = bool(enr_email or enr_phone or lst_phone)

    if has_name and has_channel and not name_conflict:
        score += 15
        breakdown.append("+15 cross-source: verified identity + reachable channel")

    # ── no name in any source ──────────────────────────────────────────────
    if not reg_name and not lst_name:
        score -= 30
        breakdown.append("-30 no name found in any source")

    # ── clamp ──────────────────────────────────────────────────────────────
    score = max(0, min(100, score))

    # ── resolve best contact values ────────────────────────────────────────
    # Prefer registry name (most authoritative); fall back to listing
    resolved_name = reg_name or lst_name or ""
    resolved_role = reg_role or ""

    # Prefer email as the outreach channel; fall back to enrichment phone,
    # then listing phone
    raw_channel = enr_email or enr_phone or lst_phone or ""

    # Per CLARIFICATIONS.md precision-over-recall: clear the contact channel
    # when below threshold so callers never auto-send to an unverified contact
    needs_review   = score < CONFIDENCE_THRESHOLD
    output_channel = "" if needs_review else raw_channel

    return ScoredContact(
        contact_name           = resolved_name,
        contact_role           = resolved_role,
        contact_email_or_phone = output_channel,
        confidence_score       = score,
        sources                = sources,
        needs_human_review     = needs_review,
        score_breakdown        = " | ".join(breakdown) if breakdown else "no signals",
        raw_contact_channel    = raw_channel,
    )


# ---------------------------------------------------------------------------
# Pipeline
# ---------------------------------------------------------------------------

def load_mocks(path: str) -> dict:
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def load_companies(path: str) -> list[dict]:
    with open(path, encoding="utf-8", newline="") as f:
        return list(csv.DictReader(f))


def run(
    csv_path:   str = CSV_PATH,
    mocks_path: str = MOCKS_PATH,
    output_csv: str = OUTPUT_CSV,
) -> list[dict]:
    """End-to-end pipeline: load → score → write CSV. Returns output rows."""
    mocks     = load_mocks(mocks_path)
    companies = load_companies(csv_path)

    os.makedirs(os.path.dirname(output_csv), exist_ok=True)

    output_rows = []

    for row in companies:
        company = row["company_name"].strip()
        address = row["mailing_address"].strip()
        slug    = company_slug(company)

        mock_data  = mocks.get(company, {})
        registry   = parse_provider(mock_data.get("registry"))
        listing    = parse_provider(mock_data.get("listing"))
        enrichment = parse_provider(mock_data.get("enrichment"))

        result = score_company(registry, listing, enrichment, slug)

        output_rows.append({
            "company_name":           company,
            "mailing_address":        address,
            "contact_name":           result.contact_name,
            "contact_role":           result.contact_role,
            "contact_email_or_phone": result.contact_email_or_phone,
            "confidence_score":       result.confidence_score,
            "source":                 "; ".join(result.sources),
            "needs_human_review":     str(result.needs_human_review).lower(),
            # Transparency columns for human reviewers
            "_raw_contact_channel":   result.raw_contact_channel,
            "_score_breakdown":       result.score_breakdown,
        })

    fieldnames = [
        "company_name", "mailing_address",
        "contact_name", "contact_role", "contact_email_or_phone",
        "confidence_score", "source", "needs_human_review",
        "_raw_contact_channel", "_score_breakdown",
    ]
    with open(output_csv, "w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(output_rows)

    return output_rows


def print_summary(rows: list[dict]) -> None:
    total        = len(rows)
    verified     = sum(1 for r in rows if r["needs_human_review"] == "false")
    needs_review = total - verified

    print(f"\n{'='*80}")
    print(f"  Contact Finder -- {total} companies processed")
    print(
        f"  Verified (>={CONFIDENCE_THRESHOLD}): {verified}"
        f"  |  Needs review: {needs_review}"
        f"  |  Coverage: {verified / total * 100:.0f}%"
    )
    print(f"{'='*80}\n")

    header = f"{'Company':<35} {'Score':>5}  {'Status':>6}  {'Name':<24}  Contact"
    print(header)
    print("-" * 90)

    for r in rows:
        flag    = "[ok] " if r["needs_human_review"] == "false" else "[rev]"
        contact = r["contact_email_or_phone"] or r["_raw_contact_channel"] or "-"
        print(
            f"{r['company_name']:<35} {r['confidence_score']:>5}  "
            f"{flag}  {r['contact_name']:<24}  {contact}"
        )
    print()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Contact Finder — mock provider slice")
    parser.add_argument("--input",  default=CSV_PATH,   help="Path to companies CSV")
    parser.add_argument("--mocks",  default=MOCKS_PATH, help="Path to mock provider JSON")
    parser.add_argument("--output", default=OUTPUT_CSV, help="Path to output CSV")
    args = parser.parse_args()

    rows = run(csv_path=args.input, mocks_path=args.mocks, output_csv=args.output)
    print_summary(rows)
    print(f"  Output written to: {args.output}\n")
