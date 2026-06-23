#!/usr/bin/env python3
"""
contact_finder.py

Cross-references up to three mock data providers (registry, listing,
enrichment) for each company in a CSV and emits a single best business
contact per company, with a confidence score and a human-review flag.

Standard library only. No third-party dependencies.

--------------------------------------------------------------------------
SCORING MODEL
--------------------------------------------------------------------------
The goal is precision over recall: a wrong contact is worse than a missing
one. A contact is only released (non-empty contact value) when confidence
reaches the threshold (70). Everything below is handed to a human with a
reason, never pre-filled.

We separate two questions deliberately:
  1. WHO is the person?  -> pick the best candidate by role rank.
  2. HOW SURE are we?    -> score corroboration across sources.

Confidence is additive, built from independent signals, then clamped to
[0, 100]:

  * BASE (+15): we have any usable, attributable contact value at all.

  * AGREEMENT (+40): two or more sources name the SAME person (after fuzzy
    matching nicknames/initials). This is the strongest signal because the
    sources are independent and corroborate each other.

  * ROLE (+5 .. +20): the chosen person's role rank. AP/accounts payable is
    the most valuable for collections; role-less is worth nothing here.

  * CONTACT MATCH (+10): the email/phone we emit is tied to the SAME person
    we identified (e.g. enrichment email local-part matches the name), not
    just a generic inbox.

  * ENRICHMENT NUDGE (-5 .. +10): enrichment.provider_confidence is a weak,
    self-reported signal. It can only NUDGE; it can never carry a contact on
    its own (BASE alone stays well below threshold).

  * CONFLICT CAP: if two sources give genuinely DIFFERENT people (names that
    do not fuzzy-match), confidence is CAPPED below threshold (max 60) and
    the row is flagged for review. Conflicting identity is disqualifying.

Provenance rule: every emitted value (name, role, contact, etc.) must trace
to at least one provider source_url. If it cannot be attributed, it is not
emitted.

Threshold = 70:
  * score >= 70  -> contact value released, needs_human_review = false.
  * score <  70  -> contact value blank, needs_human_review = true, with a
                    cannot_verify_reason explaining why.
  * missing company / no usable contact -> blank contact, review = true,
                    with a reason.
"""

import csv
import json
import os
import re
import sys

# --------------------------------------------------------------------------
# Configuration
# --------------------------------------------------------------------------

HERE = os.path.dirname(os.path.abspath(__file__))
COMPANIES_CSV = os.path.join(HERE, "challenge", "data", "companies.csv")
MOCKS_JSON = os.path.join(HERE, "challenge", "mocks", "enrichment_responses.json")
OUTPUT_CSV = os.path.join(HERE, "contacts_output.csv")

THRESHOLD = 70

# Role ranking: higher is more valuable for a collections contact.
# (pattern, rank, canonical label)
ROLE_RANKS = [
    (r"accounts?\s*payable|\bap\b|a/p", 6, "Accounts Payable"),
    (r"owner|president|founder|principal|proprietor", 5, "Owner/President"),
    (r"cfo|finance|controller|treasurer", 4, "Finance"),
    (r"office\s*manager|manager|mgr", 3, "Manager"),
    (r"registered\s*agent|agent", 2, "Registered Agent"),
]
ROLELESS_RANK = 0

# Confidence weights.
BASE_POINTS = 15
AGREEMENT_POINTS = 40
CONTACT_MATCH_POINTS = 10
CONFLICT_CAP = 60  # hard ceiling when sources name different people.

# Common nickname <-> formal name pairs (bidirectional, lowercased).
NICKNAMES = {
    "bob": "robert", "rob": "robert", "robbie": "robert",
    "bill": "william", "will": "william", "billy": "william",
    "jim": "james", "jimmy": "james",
    "mike": "michael", "mickey": "michael",
    "dave": "david",
    "joe": "joseph",
    "tom": "thomas", "tommy": "thomas",
    "dan": "daniel", "danny": "daniel",
    "tony": "anthony",
    "chris": "christopher",
    "rick": "richard", "rich": "richard", "dick": "richard",
    "steve": "steven", "stephen": "steven",
    "ed": "edward", "eddie": "edward",
    "ken": "kenneth",
    "tina": "christina",
    "sean": "shawn",
    "andy": "andrew",
    "matt": "matthew",
    "nick": "nicholas",
    "greg": "gregory",
    "jeff": "jeffrey",
    "ron": "ronald",
    "fred": "frederick",
}

# Titles/honorifics and parenthetical role notes to strip from names.
TITLE_RE = re.compile(
    r"\b(dr|mr|mrs|ms|miss|prof|md|dds|phd|esq|jr|sr|ii|iii)\.?\b",
    re.IGNORECASE,
)
PAREN_RE = re.compile(r"\(.*?\)")


# --------------------------------------------------------------------------
# Name normalization & fuzzy matching
# --------------------------------------------------------------------------

def normalize_name(raw):
    """Lowercase, strip titles/parentheticals/punctuation -> list of tokens."""
    if not raw:
        return []
    s = raw.lower()
    s = PAREN_RE.sub(" ", s)
    s = TITLE_RE.sub(" ", s)
    s = re.sub(r"[^a-z\s]", " ", s)  # drop punctuation, initials lose dots here
    tokens = [t for t in s.split() if t]
    return tokens


def canonical_token(tok):
    """Map a name token through the nickname table to a canonical form."""
    return NICKNAMES.get(tok, tok)


def same_person(name_a, name_b):
    """
    Loose identity match: True if two names plausibly refer to the same
    person. Handles nicknames (Bob/Robert), initials (Sean/S. Murphy),
    and titles (Dr. Emily Hart / Emily Hart).

    Strategy: the last name must match (canonicalized). The first token must
    match either fully (after nickname expansion) or by initial.
    """
    a = normalize_name(name_a)
    b = normalize_name(name_b)
    if not a or not b:
        return False

    a_last, b_last = canonical_token(a[-1]), canonical_token(b[-1])
    if a_last != b_last:
        return False

    # Single-token names that share the "last" name -> treat as match.
    if len(a) == 1 or len(b) == 1:
        return True

    a_first, b_first = a[0], b[0]
    ca, cb = canonical_token(a_first), canonical_token(b_first)
    if ca == cb:
        return True
    # Initial match: "s" vs "sean".
    if a_first[:1] == b_first[:1]:
        return True
    return False


# --------------------------------------------------------------------------
# Role ranking
# --------------------------------------------------------------------------

def rank_role(role):
    """Return (rank, canonical_label) for a role string."""
    if not role:
        return ROLELESS_RANK, ""
    low = role.lower()
    for pattern, rank, label in ROLE_RANKS:
        if re.search(pattern, low):
            return rank, label
    return ROLELESS_RANK, role.strip()


# --------------------------------------------------------------------------
# Candidate building
# --------------------------------------------------------------------------

def email_local_part(email):
    """Return the part before '@', lowercased, or '' ."""
    if not email or "@" not in email:
        return ""
    return email.split("@", 1)[0].lower()


def contact_matches_name(contact_value, name):
    """
    Heuristic: does an email/phone plausibly belong to the named person?
    Only emails can be tied to a name (via the local-part). Phones cannot,
    so we return False for them (no false credit).
    """
    if not contact_value or "@" not in contact_value:
        return False
    local = re.sub(r"[^a-z]", "", email_local_part(contact_value))
    if not local or not name:
        return False
    tokens = [t for t in normalize_name(name) if len(t) > 1]
    return any(tok in local for tok in tokens)


def build_named_candidates(providers):
    """
    Collect (name, role, role_rank, role_label, source_url, provider) for
    every provider that supplies a usable name. Used for identity resolution.
    """
    candidates = []
    for pname in ("registry", "listing", "enrichment"):
        p = providers.get(pname) or {}
        name = (p.get("name") or "").strip()
        if not name:
            continue
        rank, label = rank_role(p.get("role"))
        candidates.append({
            "name": name,
            "role": (p.get("role") or "").strip(),
            "role_rank": rank,
            "role_label": label,
            "source_url": p.get("source_url"),
            "provider": pname,
        })
    return candidates


def pick_best_contact_value(providers, chosen_name):
    """
    Choose an email or phone to emit, preferring a value attributable to the
    chosen person. Returns (value, provider, source_url) or (None, None, None).
    Email tied to the person is best; then any email; then any phone.
    Every returned value carries a source_url (provenance guaranteed).
    """
    enrichment = providers.get("enrichment") or {}
    listing = providers.get("listing") or {}

    # 1. Email that matches the chosen person.
    email = enrichment.get("email")
    if email and email_local_part(email):
        if contact_matches_name(email, chosen_name) and enrichment.get("source_url"):
            return email, "enrichment", enrichment["source_url"]

    # 2. Any attributable email.
    if email and enrichment.get("source_url"):
        return email, "enrichment", enrichment["source_url"]

    # 3. Phone from listing (preferred) then enrichment.
    if listing.get("phone") and listing.get("source_url"):
        return listing["phone"], "listing", listing["source_url"]
    if enrichment.get("phone") and enrichment.get("source_url"):
        return enrichment["phone"], "enrichment", enrichment["source_url"]
    return None, None, None


# --------------------------------------------------------------------------
# Core resolution per company
# --------------------------------------------------------------------------

def resolve_company(company_name, providers):
    """
    Return a result dict with all output columns for one company.
    """
    result = {
        "company_name": company_name,
        "contact_name": "",
        "contact_role": "",
        "contact_email_or_phone": "",
        "confidence_score": 0,
        "source": "",
        "needs_human_review": True,
        "cannot_verify_reason": "",
    }

    if not providers:
        result["cannot_verify_reason"] = "Company not found in provider data"
        return result

    named = build_named_candidates(providers)

    # Detect conflict: two named candidates that are genuinely different people.
    conflict = False
    for i in range(len(named)):
        for j in range(i + 1, len(named)):
            if not same_person(named[i]["name"], named[j]["name"]):
                conflict = True

    # Group candidates that refer to the same person; count agreement.
    chosen = None
    agreement_count = 1
    if named:
        # Highest role rank wins; ties broken by registry > listing > enrichment.
        provider_order = {"registry": 0, "listing": 1, "enrichment": 2}
        named.sort(key=lambda c: (-c["role_rank"], provider_order[c["provider"]]))
        chosen = named[0]
        agreement_count = sum(
            1 for c in named if same_person(c["name"], chosen["name"])
        )

    # Pick the contact value (email/phone) with provenance.
    chosen_name = chosen["name"] if chosen else ""
    contact_value, contact_provider, contact_url = pick_best_contact_value(
        providers, chosen_name
    )

    # Nothing usable to emit at all.
    if not chosen and not contact_value:
        result["cannot_verify_reason"] = "No usable contact in any provider"
        return result

    # ----------------------------------------------------------------------
    # Confidence scoring
    # ----------------------------------------------------------------------
    score = 0
    contributing = set()

    # BASE: any usable, attributable contact value.
    if contact_value:
        score += BASE_POINTS
        if contact_provider:
            contributing.add(contact_provider)

    # AGREEMENT: independent sources naming the same person.
    if agreement_count >= 2:
        score += AGREEMENT_POINTS

    # ROLE: value of the chosen person's role.
    if chosen:
        score += chosen["role_rank"] * 3 + 5 if chosen["role_rank"] else 0
        contributing.add(chosen["provider"])
        for c in named:
            if same_person(c["name"], chosen["name"]):
                contributing.add(c["provider"])

    # CONTACT MATCH: emitted email tied to the chosen person.
    if contact_value and contact_matches_name(contact_value, chosen_name):
        score += CONTACT_MATCH_POINTS

    # ENRICHMENT NUDGE: weak self-reported signal, bounded.
    enrichment = providers.get("enrichment") or {}
    pc = enrichment.get("provider_confidence")
    if isinstance(pc, (int, float)):
        nudge = round((pc - 50) / 10.0)        # -5 .. +5 range
        nudge = max(-5, min(10, nudge))
        score += nudge
        if enrichment.get("source_url"):
            contributing.add("enrichment")

    score = max(0, min(100, score))

    # CONFLICT CAP: different people across sources -> ceiling + force review.
    reasons = []
    if conflict:
        score = min(score, CONFLICT_CAP)
        reasons.append("Sources name different people")

    # ----------------------------------------------------------------------
    # Provenance check: only emit values we can attribute.
    # ----------------------------------------------------------------------
    if chosen and not chosen.get("source_url"):
        chosen = None  # cannot attribute the name -> drop it.
    if contact_value and not contact_url:
        contact_value = None  # cannot attribute the contact -> drop it.
        reasons.append("Contact value lacked a source URL")

    result["confidence_score"] = score
    result["source"] = "|".join(
        p for p in ("registry", "listing", "enrichment") if p in contributing
    )
    if chosen:
        result["contact_name"] = chosen["name"]
        result["contact_role"] = chosen["role_label"] or chosen["role"]

    # ----------------------------------------------------------------------
    # Threshold gate.
    # ----------------------------------------------------------------------
    if score >= THRESHOLD and contact_value:
        result["contact_email_or_phone"] = contact_value
        result["needs_human_review"] = False
        result["cannot_verify_reason"] = ""
    else:
        result["contact_email_or_phone"] = ""
        result["needs_human_review"] = True
        if not reasons:
            if not contact_value:
                reasons.append("No attributable contact value")
            else:
                reasons.append(
                    "Confidence %d below threshold %d" % (score, THRESHOLD)
                )
        result["cannot_verify_reason"] = "; ".join(reasons)

    return result


# --------------------------------------------------------------------------
# I/O & reporting
# --------------------------------------------------------------------------

def load_companies(path):
    with open(path, newline="", encoding="utf-8") as f:
        return [row["company_name"] for row in csv.DictReader(f)]


def load_mocks(path):
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def write_output(path, results):
    fields = [
        "company_name", "contact_name", "contact_role",
        "contact_email_or_phone", "confidence_score", "source",
        "needs_human_review", "cannot_verify_reason",
    ]
    with open(path, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        for r in results:
            row = dict(r)
            row["needs_human_review"] = "true" if r["needs_human_review"] else "false"
            writer.writerow(row)


def print_summary(results):
    name_w = max(len(r["company_name"]) for r in results)
    name_w = max(name_w, len("Company"))
    header = "%-*s  %10s  %s" % (name_w, "Company", "Confidence", "Flagged?")
    print(header)
    print("-" * len(header))
    for r in results:
        flagged = "REVIEW" if r["needs_human_review"] else "ok"
        print("%-*s  %10d  %s" % (
            name_w, r["company_name"], r["confidence_score"], flagged
        ))
    flagged_total = sum(1 for r in results if r["needs_human_review"])
    print("-" * len(header))
    print("%d companies, %d ready, %d flagged for review" % (
        len(results), len(results) - flagged_total, flagged_total
    ))


def main():
    companies = load_companies(COMPANIES_CSV)
    mocks = load_mocks(MOCKS_JSON)

    results = [resolve_company(c, mocks.get(c)) for c in companies]

    write_output(OUTPUT_CSV, results)
    print_summary(results)
    print("\nWrote %s" % OUTPUT_CSV)


if __name__ == "__main__":
    sys.exit(main())
