import csv
import json
import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional

CONFIDENCE_THRESHOLD = 70 

PERSONA_PRIORITY = {
    "ap manager": 0,
    "accounts payable": 0,
    "owner": 1,
    "founder": 1,
    "cfo": 2,
    "finance lead": 2,
    "president": 2,   
    "manager": 3,
    "office manager": 3,
    "registered agent": 4,
}

@dataclass
class Candidate:
    name: Optional[str]
    role: Optional[str]
    email: Optional[str]
    phone: Optional[str]
    source_urls: list[str] = field(default_factory=list)
    provider_confidence: Optional[int] = None  # enrichment provider's self-reported score


@dataclass
class OutputRow:
    company_name: str
    contact_name: str
    contact_role: str
    contact_email_or_phone: str
    confidence_score: int
    source: str           # pipe-separated source_urls
    needs_human_review: bool
    cannot_verify_reason: str = ""


def normalize_name(name: Optional[str]) -> str:
    if not name:
        return ""
    name = name.lower()
    name = re.sub(r"[^\w\s]", "", name)
    return re.sub(r"\s+", " ", name).strip()


def names_match(a: Optional[str], b: Optional[str]) -> bool:
    na, nb = normalize_name(a), normalize_name(b)
    if not na or not nb:
        return False
    if na == nb:
        return True
    parts_a, parts_b = na.split(), nb.split()
    if len(parts_a) >= 2 and len(parts_b) >= 2:
        if parts_a[-1] == parts_b[-1]:
            if parts_a[0][0] == parts_b[0][0]: 
                return True
    return False


def persona_rank(role: Optional[str]) -> int:
    """Lower = higher priority decision-maker. Unknown roles get lowest priority."""
    if not role:
        return 99
    role_lower = role.lower()
    for keyword, rank in PERSONA_PRIORITY.items():
        if keyword in role_lower:
            return rank
    return 99


def best_contact(a: Optional[str], b: Optional[str]) -> Optional[str]:
    return a or b or None


def compute_confidence(candidate: Candidate, source_count: int, name_agrees: bool) -> int:
    score = 0

    has_name = bool(candidate.name)
    has_email = bool(candidate.email)
    has_phone = bool(candidate.phone)
    source_list = " ".join(candidate.source_urls)
    has_registry = "mock://registry" in source_list
    has_enrichment_only = (
        not has_registry
        and "mock://listing" not in source_list
        and "mock://enrichment" in source_list
    )
    if name_agrees and source_count >= 2:
        score += 50
    elif has_registry and has_name:
        score += 40
    elif has_name and source_count == 1:
        score += 25
    elif has_enrichment_only and not has_name:
        score -= 15  

    if has_email and has_phone:
        score += 15
    elif has_email or has_phone:
        score += 10
    else:
        score -= 5

    rank = persona_rank(candidate.role)
    if rank <= 2:       # AP manager / owner / founder / CFO / president
        score += 15
    elif rank == 3:     # manager / office manager
        score += 5
    elif rank == 4:     # registered agent
        score -= 5
    else:               # unknown role
        score -= 10

    if candidate.provider_confidence is not None:
        if candidate.provider_confidence >= 80:
            score += 10
        elif candidate.provider_confidence >= 60:
            score += 5
        elif candidate.provider_confidence < 50:
            score -= 5

    return max(0, min(100, score))


def load_mocks(path: Path) -> dict:
    with open(path) as f:
        return json.load(f)


def enrich(company_name: str, mocks: dict) -> Optional[OutputRow]:
    providers = mocks.get(company_name, {})

    registry   = providers.get("registry")
    listing    = providers.get("listing")
    enrichment = providers.get("enrichment")
    name = None
    role = None
    email = None
    phone = None
    source_urls = []
    provider_confidence = None

    if registry:
        name = registry.get("name")
        role = registry.get("role")
        source_urls.append(registry["source_url"])

    if listing:
        listing_name = listing.get("name")
        listing_phone = listing.get("phone")
        if not name:
            name = listing_name
        source_urls.append(listing["source_url"])
        if listing_phone:
            phone = listing_phone

    if enrichment:
        email = enrichment.get("email")
        enrichment_phone = enrichment.get("phone")
        if not phone:
            phone = enrichment_phone
        provider_confidence = enrichment.get("provider_confidence")
        source_urls.append(enrichment["source_url"])
    if not source_urls:
        return OutputRow(
            company_name=company_name,
            contact_name="",
            contact_role="",
            contact_email_or_phone="",
            confidence_score=0,
            source="",
            needs_human_review=True,
            cannot_verify_reason="no results from any provider",
        )
    source_names = [
        s.get("name") for s in [registry, listing]
        if s and s.get("name")
    ]
    name_agrees = len(source_names) >= 2 and names_match(source_names[0], source_names[1])

    candidate = Candidate(
        name=name,
        role=role,
        email=email,
        phone=phone,
        source_urls=source_urls,
        provider_confidence=provider_confidence,
    )

    score = compute_confidence(candidate, len(source_urls), name_agrees)
    if len(source_names) >= 2 and not name_agrees:
        score = min(score, 40)

    contact_value = email or phone or ""
    below_threshold = score < CONFIDENCE_THRESHOLD

    return OutputRow(
        company_name=company_name,
        contact_name=candidate.name or "",
        contact_role=candidate.role or "",
        contact_email_or_phone="" if below_threshold else contact_value,
        confidence_score=score,
        source=" | ".join(source_urls),
        needs_human_review=below_threshold,
        cannot_verify_reason="confidence below threshold" if below_threshold and source_urls else "",
    )

def run(csv_path: Path, mocks_path: Path, output_path: Path):
    mocks = load_mocks(mocks_path)

    rows = []
    with open(csv_path, newline="") as f:
        for row in csv.DictReader(f):
            result = enrich(row["company_name"], mocks)
            rows.append(result)

    fieldnames = [
        "company_name", "contact_name", "contact_role",
        "contact_email_or_phone", "confidence_score",
        "source", "needs_human_review", "cannot_verify_reason",
    ]
    with open(output_path, "w", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        for r in rows:
            writer.writerow(vars(r))

    total = len(rows)
    verified = sum(1 for r in rows if not r.needs_human_review)
    review = sum(1 for r in rows if r.needs_human_review)
    not_found = sum(1 for r in rows if not r.source)
    print(f"Processed {total} companies → {verified} verified, {review} need review ({not_found} not found)")
    print(f"Output written to {output_path}")

base = Path(__file__).parent / "challenge"
csv_path   = base / "data" / "companies.csv"
mocks_path = base / "mocks" / "enrichment_responses.json"
output_path = Path(__file__).parent / "contacts_output.csv"

if not csv_path.exists():
    print(f"CSV not found at {csv_path}. Pass paths as args: contact_finder.py <csv> <mocks> <output>")
else:

    run(csv_path, mocks_path, output_path)
