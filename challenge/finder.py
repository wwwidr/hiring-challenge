import csv
import json
import re
from pathlib import Path

BASE_DIR = Path(__file__).parent
DATA_PATH = BASE_DIR / "data" / "companies.csv"
MOCK_PATH = BASE_DIR / "mocks" / "enrichment_responses.json"
OUTPUT_DIR = BASE_DIR / "output"
OUTPUT_PATH = OUTPUT_DIR / "contacts.csv"

# Common nickname -> canonical first name (all lowercase)
NICKNAMES = {
    "bob": "robert", "rob": "robert", "bobby": "robert",
    "bill": "william", "will": "william", "billy": "william",
    "jim": "james", "jimmy": "james",
    "joe": "joseph", "joey": "joseph",
    "mike": "michael", "mick": "michael",
    "tom": "thomas", "tommy": "thomas",
    "ted": "edward", "ed": "edward",
    "dick": "richard", "rick": "richard",
    "kate": "katherine", "kathy": "katherine", "katie": "katherine",
    "sue": "susan", "suzy": "susan",
    "liz": "elizabeth", "beth": "elizabeth", "lisa": "elizabeth",
    "meg": "margaret", "maggie": "margaret", "peggy": "margaret",
    "dan": "daniel", "danny": "daniel",
    "dave": "david", "davy": "david",
    "chris": "christopher",
    "tony": "anthony",
    "nick": "nicholas",
    "matt": "matthew",
    "andy": "andrew",
    "alex": "alexander",
}

TITLE_PREFIXES = {"dr", "mr", "mrs", "ms", "prof", "rev"}


def strip_title(name: str) -> str:
    if not name:
        return name
    parts = name.strip().split()
    if parts and parts[0].rstrip(".").lower() in TITLE_PREFIXES:
        return " ".join(parts[1:])
    return name


def parse_name(name: str):
    """Return (first, last) both lowercased, or (None, None) on failure."""
    if not name:
        return None, None
    name = strip_title(name)
    name = re.sub(r"\(.*?\)", "", name).strip()
    parts = name.split()
    if not parts:
        return None, None
    if len(parts) == 1:
        return parts[0].lower().rstrip("."), None
    first = parts[0].lower().rstrip(".")
    last = parts[-1].lower().rstrip(".")
    return first, last


def names_match(name_a: str, name_b: str) -> bool:
    """
    True when two names plausibly refer to the same person.
    Rules: same last name AND (first names canonically equal, or one is
    just the initial of the other).
    Handles: "Bob" / "Robert", "S. Murphy" / "Sean Murphy".
    """
    fa, la = parse_name(name_a)
    fb, lb = parse_name(name_b)
    if not la or not lb:
        return False
    if la != lb:
        return False
    ca = NICKNAMES.get(fa, fa)
    cb = NICKNAMES.get(fb, fb)
    # Either is an initial: match if it equals the first letter of the other
    if len(fa) == 1:
        return bool(cb) and fa == cb[0]
    if len(fb) == 1:
        return bool(ca) and fb == ca[0]
    return ca == cb


def role_priority(role: str) -> int:
    """Lower = more preferred. 99 = unknown/unset."""
    if not role:
        return 99
    r = role.lower()
    if any(k in r for k in ("ap manager", "accounts payable")):
        return 1
    if any(k in r for k in ("owner", "founder", "president")):
        return 2
    if any(k in r for k in ("cfo", "chief financial", "finance")):
        return 3
    if "office manager" in r or r == "manager":
        return 4
    if "registered agent" in r:
        return 5
    return 99


def role_score_delta(role: str) -> int:
    if not role:
        return 0
    r = role.lower()
    if any(k in r for k in ("ap manager", "accounts payable")):
        return 15
    if any(k in r for k in ("owner", "founder", "president")):
        return 10
    if "registered agent" in r:
        return -10
    return 0


def compute_score(registry, listing, enrichment) -> int:
    """Return confidence score 0-100 per the spec."""
    score = 0

    reg_name = registry.get("name") if registry else None
    reg_role = registry.get("role") if registry else None
    lst_name_raw = listing.get("name") if listing else None
    lst_phone = listing.get("phone") if listing else None
    enr_email = enrichment.get("email") if enrichment else None
    enr_phone = enrichment.get("phone") if enrichment else None
    enr_conf = enrichment.get("provider_confidence") if enrichment else None

    lst_name = re.sub(r"\(.*?\)", "", lst_name_raw).strip() if lst_name_raw else None

    # Registry hit with a name
    if reg_name:
        score += 35

    # Listing independently confirms the same person
    if reg_name and lst_name and names_match(reg_name, lst_name):
        score += 20

    # Enrichment provides an email or phone
    if enr_email or enr_phone:
        score += 20

    # Role bonus / penalty (from registry)
    score += role_score_delta(reg_role)

    # Only one source contributed at all
    sources_present = sum([registry is not None, listing is not None, enrichment is not None])
    if sources_present == 1:
        score -= 15

    # Enrichment provider's own confidence is high
    if enr_conf is not None and enr_conf > 75:
        score += 10

    # No contact method found anywhere
    has_contact = bool(enr_email or enr_phone or lst_phone)
    if not has_contact:
        score -= 20

    return max(0, min(100, score))


def pick_contact(registry, listing, enrichment):
    """
    Choose the best named contact by role priority, corroborate across
    sources, then attach the best available contact method.

    Returns (contact_name, contact_role, contact_email_or_phone, source_pipe).
    """
    reg_name = registry.get("name") if registry else None
    reg_role = registry.get("role") if registry else None
    lst_name_raw = listing.get("name") if listing else None
    lst_phone = listing.get("phone") if listing else None
    enr_email = enrichment.get("email") if enrichment else None
    enr_phone = enrichment.get("phone") if enrichment else None

    lst_name = re.sub(r"\(.*?\)", "", lst_name_raw).strip() if lst_name_raw else None
    lst_role_match = re.search(r"\(([^)]+)\)", lst_name_raw) if lst_name_raw else None
    lst_role = lst_role_match.group(1) if lst_role_match else None

    # Build candidate list: (name, role, priority, source_url)
    candidates = []
    if reg_name:
        candidates.append((reg_name, reg_role, role_priority(reg_role), registry.get("source_url")))
    if lst_name:
        candidates.append((lst_name, lst_role, role_priority(lst_role), listing.get("source_url")))

    candidates.sort(key=lambda c: c[2])

    chosen_name = ""
    chosen_role = ""
    source_urls = []

    if candidates:
        chosen_name, chosen_role, _, primary_url = candidates[0]
        chosen_role = chosen_role or ""
        if primary_url:
            source_urls.append(primary_url)
        # If another source names the same person, count it as corroboration
        for name, _, _, url in candidates[1:]:
            if url and names_match(chosen_name, name) and url not in source_urls:
                source_urls.append(url)

    # Contact method: enrichment email > enrichment phone > listing phone
    contact = ""
    if enr_email:
        contact = enr_email
        url = enrichment.get("source_url") if enrichment else None
        if url and url not in source_urls:
            source_urls.append(url)
    elif enr_phone:
        contact = enr_phone
        url = enrichment.get("source_url") if enrichment else None
        if url and url not in source_urls:
            source_urls.append(url)
    elif lst_phone:
        contact = lst_phone
        url = listing.get("source_url") if listing else None
        if url and url not in source_urls:
            source_urls.append(url)

    return chosen_name, chosen_role, contact, "|".join(source_urls)


def process_row(company_name: str, mailing_address: str, mock_data: dict) -> dict:
    if company_name not in mock_data:
        return {
            "company_name": company_name,
            "mailing_address": mailing_address,
            "contact_name": "",
            "contact_role": "",
            "contact_email_or_phone": "",
            "confidence_score": 0,
            "source": "",
            "needs_human_review": True,
        }

    data = mock_data[company_name]
    registry = data.get("registry")
    listing = data.get("listing")
    enrichment = data.get("enrichment")

    score = compute_score(registry, listing, enrichment)
    contact_name, contact_role, contact_method, source = pick_contact(registry, listing, enrichment)

    needs_review = score < 70
    if needs_review:
        contact_method = ""

    return {
        "company_name": company_name,
        "mailing_address": mailing_address,
        "contact_name": contact_name,
        "contact_role": contact_role,
        "contact_email_or_phone": contact_method,
        "confidence_score": score,
        "source": source,
        "needs_human_review": needs_review,
    }


def main():
    with open(DATA_PATH, newline="", encoding="utf-8") as f:
        companies = list(csv.DictReader(f))

    with open(MOCK_PATH, encoding="utf-8") as f:
        mock_data = json.load(f)

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    fieldnames = [
        "company_name", "mailing_address", "contact_name", "contact_role",
        "contact_email_or_phone", "confidence_score", "source", "needs_human_review",
    ]

    results = []
    for row in companies:
        result = process_row(row["company_name"], row["mailing_address"], mock_data)
        results.append(result)
        flag = "REVIEW" if result["needs_human_review"] else "OK    "
        print(f"[{flag}]  score={result['confidence_score']:3d}  {result['company_name']}")

    with open(OUTPUT_PATH, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(results)

    total = len(results)
    reviewed = sum(1 for r in results if r["needs_human_review"])
    resolved = total - reviewed
    print(f"\n{total} rows  |  {resolved} resolved  |  {reviewed} flagged for review")
    print(f"Output: {OUTPUT_PATH}")


if __name__ == "__main__":
    main()
