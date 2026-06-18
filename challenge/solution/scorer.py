"""
Confidence scorer and name resolver.

Implements the formula from PLAN.md, updated for the CLARIFICATIONS threshold (70).

Formula:
  score = base + agreement_bonus + role_bonus - conflict_penalty - generic_penalty
  clamped to [0, 100].

Threshold: score < 70  →  needs_human_review = True, contact fields = null.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Optional

from providers import EnrichmentResult, ListingResult, ProviderResponse, RegistryResult

CONFIDENCE_THRESHOLD = 70

# Common nickname → canonical first name (lowercase)
_NICKNAMES: dict[str, str] = {
    "bob": "robert",
    "bobby": "robert",
    "rob": "robert",
    "bill": "william",
    "billy": "william",
    "will": "william",
    "jim": "james",
    "jimmy": "james",
    "mike": "michael",
    "mick": "michael",
    "dick": "richard",
    "rick": "richard",
    "rich": "richard",
    "tom": "thomas",
    "tommy": "thomas",
    "dave": "david",
    "dan": "daniel",
    "danny": "daniel",
    "chris": "christopher",
    "kate": "katherine",
    "kathy": "katherine",
    "liz": "elizabeth",
    "beth": "elizabeth",
    "sue": "susan",
    "suzy": "susan",
    "jen": "jennifer",
    "jeff": "jeffrey",
    "al": "albert",
    "andy": "andrew",
    "drew": "andrew",
    "tony": "anthony",
    "joe": "joseph",
    "joey": "joseph",
    "ed": "edward",
    "ted": "edward",
    "sam": "samuel",
    "pat": "patricia",
    "trish": "patricia",
    "peg": "margaret",
    "maggie": "margaret",
}

# Generic email local-parts that do not identify a specific person
_GENERIC_LOCALS = frozenset(
    {"info", "office", "sales", "contact", "admin", "support", "hello",
     "inquiries", "help", "team", "billing", "accounts", "ap", "general"}
)

# Role strings → priority tier (lower = higher priority per CLARIFICATIONS)
_ROLE_PRIORITY: dict[str, int] = {
    "ap manager": 1,
    "accounts payable": 1,
    "accounts payable manager": 1,
    "owner": 2,
    "founder": 2,
    "co-founder": 2,
    "president": 2,
    "cfo": 2,
    "chief financial officer": 2,
    "finance lead": 2,
    "office manager": 3,
    "manager": 3,
    "registered agent": 4,
}


def _normalize(name: str) -> str:
    """Lowercase, strip honorifics and punctuation, collapse whitespace."""
    name = name.lower()
    # Strip common honorifics
    name = re.sub(r"\b(dr|mr|mrs|ms|prof|rev)\.?\s*", "", name)
    # Remove trailing role hints like "(manager)"
    name = re.sub(r"\(.*?\)", "", name)
    name = re.sub(r"[^\w\s]", "", name)
    return " ".join(name.split())


def _first_last(normalized: str) -> tuple[str, str]:
    """Split a normalized name into (first, last). Best-effort."""
    parts = normalized.split()
    if len(parts) == 0:
        return "", ""
    if len(parts) == 1:
        return parts[0], ""
    return parts[0], parts[-1]


def _canonical_first(first: str) -> str:
    """Resolve nickname to canonical form, e.g. 'bob' → 'robert'."""
    return _NICKNAMES.get(first, first)


def _names_match(a: str, b: str) -> bool:
    """
    Return True if two raw name strings refer to the same person.
    Handles: exact match, initial match (S. Murphy ~ Sean Murphy),
    nickname pairs (Bob ~ Robert), and honorific stripping.
    """
    na, nb = _normalize(a), _normalize(b)
    if na == nb:
        return True

    fa, la = _first_last(na)
    fb, lb = _first_last(nb)

    # Last names must match (after normalization)
    if la != lb or not la:
        return False

    # Canonical first name comparison (handles nicknames)
    if _canonical_first(fa) == _canonical_first(fb):
        return True

    # Initial match: one side is a single letter
    if (len(fa) == 1 and fb.startswith(fa)) or (len(fb) == 1 and fa.startswith(fb)):
        return True

    return False


def _email_local(email: str) -> str:
    """Extract local-part of an email address, lowercased."""
    return email.split("@")[0].lower()


def _is_generic_email(email: str) -> bool:
    local = _email_local(email)
    # Check whole local part
    if local in _GENERIC_LOCALS:
        return True
    # Check first segment before a dot (e.g. "info.team@")
    first_seg = local.split(".")[0]
    return first_seg in _GENERIC_LOCALS


def _email_matches_name(email: str, name: str) -> bool:
    """
    Return True if the email local-part is plausibly derived from this name.
    Handles: first.last, first, f.last, first_last patterns.
    """
    local = _email_local(email)
    if _is_generic_email(email):
        return False

    norm = _normalize(name)
    first, last = _first_last(norm)
    canon_first = _canonical_first(first)

    # Segments from local part (split on dot, underscore, hyphen)
    segments = re.split(r"[._\-]", local)

    # Direct first-name match (e.g. "karen@")
    if len(segments) == 1:
        local_canon = _canonical_first(local)
        if local_canon == canon_first:
            return True
        # Initial-only match isn't strong enough on its own without last name
        return False

    # Two-segment patterns
    if len(segments) >= 2:
        seg0, seg1 = segments[0], segments[1]

        # first.last pattern
        if _canonical_first(seg0) == canon_first and seg1 == last:
            return True
        # initial.last pattern (e.g. d.ortega → Daniel Ortega)
        if len(seg0) == 1 and seg0 == first[0:1] and seg1 == last:
            return True
        # last.first pattern (less common but exists)
        if seg0 == last and _canonical_first(seg1) == canon_first:
            return True

    return False


def _role_bonus(role: Optional[str]) -> int:
    if not role:
        return 0
    tier = _ROLE_PRIORITY.get(role.lower(), 3)
    if tier == 1:
        return 10   # AP Manager — top priority per clarifications
    if tier == 2:
        return 8    # Owner / President / CFO
    if tier == 3:
        return 0    # Manager / Office Manager
    if tier == 4:
        return -5   # Registered Agent
    return 0


@dataclass
class ScoredContact:
    contact_name: Optional[str]
    contact_role: Optional[str]
    contact_email_or_phone: Optional[str]
    confidence_score: int
    sources: list[str] = field(default_factory=list)
    needs_human_review: bool = False
    notes: Optional[str] = None  # surfaced to reviewer on conflict rows


def score(response: ProviderResponse) -> ScoredContact:
    """
    Aggregate provider signals, compute confidence, resolve best contact.
    """
    reg: Optional[RegistryResult] = response["registry"]
    lst: Optional[ListingResult] = response["listing"]
    enr: Optional[EnrichmentResult] = response["enrichment"]

    sources: list[str] = []
    if reg:
        sources.append(reg["source_url"])
    if lst:
        sources.append(lst["source_url"])
    if enr:
        sources.append(enr["source_url"])

    # ── Base score ───────────────────────────────────────────────────────────
    if enr:
        base = enr["provider_confidence"]
    elif reg:
        base = 50
    elif lst and lst.get("name"):
        base = 35
    elif lst:
        base = 20   # phone-only listing, no identified person
    else:
        base = 0    # no data at all

    bonuses = 0
    penalties = 0
    conflict = False
    notes: list[str] = []

    # ── Agreement bonuses ────────────────────────────────────────────────────
    reg_name = reg["name"] if reg else None
    lst_name = lst["name"] if lst else None
    enr_email = enr["email"] if enr else None

    if reg_name and lst_name:
        if _names_match(reg_name, lst_name):
            bonuses += 15  # independent sources agree on same person
        else:
            conflict = True
            penalties += 25
            notes.append(
                f"Name conflict: registry='{reg_name}' vs listing='{lst_name}'"
            )

    if reg_name and enr_email and _email_matches_name(enr_email, reg_name):
        bonuses += 12

    if lst_name and enr_email and _email_matches_name(enr_email, lst_name):
        bonuses += 10

    # ── Role bonus (applied to best available name's role) ───────────────────
    role: Optional[str] = reg["role"] if reg else None
    bonuses += _role_bonus(role)

    # ── Generic-contact penalty ──────────────────────────────────────────────
    # Apply when contact method is a generic email AND no personal name found
    best_name = reg_name or lst_name
    if enr_email and _is_generic_email(enr_email) and not best_name:
        penalties += 12

    # ── Final score ──────────────────────────────────────────────────────────
    raw = base + bonuses - penalties
    score_val = max(0, min(100, raw))

    needs_review = score_val < CONFIDENCE_THRESHOLD or conflict

    # ── Resolve best contact fields ──────────────────────────────────────────
    # Priority for name: registry > listing > None
    contact_name = reg_name or lst_name

    # Priority for role: registry only
    contact_role = role

    # Priority for contact method: personal email > personal phone > generic email
    contact_method: Optional[str] = None
    if not needs_review:
        if enr_email and not _is_generic_email(enr_email):
            contact_method = enr_email
        elif enr and enr.get("phone"):
            contact_method = enr["phone"]
        elif lst and lst.get("phone"):
            contact_method = lst["phone"]
        elif enr_email:  # generic email, still above threshold
            contact_method = enr_email

    return ScoredContact(
        contact_name=contact_name if not needs_review else None,
        contact_role=contact_role if not needs_review else None,
        contact_email_or_phone=contact_method,
        confidence_score=score_val,
        sources=sources,
        needs_human_review=needs_review,
        notes="; ".join(notes) if notes else None,
    )
