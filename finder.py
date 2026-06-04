import csv
import json
import logging

logging.basicConfig(level=logging.INFO, format="%(asctime)s - %(message)s")
logger = logging.getLogger(__name__)

CONFIDENCE_THRESHOLD = 70

def read_csv(filepath):
    rows = []
    with open(filepath) as file:
        reader = csv.DictReader(file)
        for row in reader:
            rows.append(row)
    return rows

def read_json(filepath):
    with open(filepath) as file:
        return json.load(file)

companies = read_csv("challenge/data/companies.csv")
mock_data = read_json("challenge/mocks/enrichment_responses.json")
logger.info(f"Loaded {len(companies)} companies")

# Priority order taken directly from clarifications doc
# AP manager is highest because we are chasing payment, not general outreach
ROLE_PRIORITY = {
    "ap manager": 5,
    "accounts payable": 5,
    "owner": 4,
    "founder": 4,
    "president": 3,
    "cfo": 3,
    "finance": 3,
    "manager": 2,
    "registered agent": 1,
    "office manager": 1,
}

def get_role_score(role):
    if role is None:
        return 0
    role_lowered = role.lower()
    for key in ROLE_PRIORITY:
        if key in role_lowered:
            return ROLE_PRIORITY[key]
    return 0

def names_are_same_person(name_one, name_two):
    # Handles cases like "S. Murphy" vs "Sean Murphy"
    # If last names match and first initials match, treat as same person
    if name_one is None or name_two is None:
        return False

    cleaned_one = name_one.lower().replace(".", "").strip()
    cleaned_two = name_two.lower().replace(".", "").strip()

    if cleaned_one == cleaned_two:
        return True

    parts_one = cleaned_one.split()
    parts_two = cleaned_two.split()

    if len(parts_one) < 2 or len(parts_two) < 2:
        return False

    last_names_match = parts_one[-1] == parts_two[-1]
    first_initials_match = parts_one[0][0] == parts_two[0][0]

    if last_names_match and first_initials_match:
        return True

    return False


def build_contact_row(company_name, mock_data):
    registry = mock_data.get("registry", None)
    listing = mock_data.get("listing", None)
    enrichment = mock_data.get("enrichment", None)

    confidence_score = 0
    source_urls = []
    contact_name = None
    contact_role = None
    contact_email = None
    contact_phone = None

    # Registry is the most trustworthy source -- state-filed data
    if registry is not None:
        contact_name = registry.get("name")
        contact_role = registry.get("role")
        source_urls.append(registry.get("source_url"))
        confidence_score = confidence_score + 25
        logger.info(f"{company_name} -- registry hit: {contact_name}, {contact_role}")

    if listing is not None:
        listing_name = listing.get("name")

        if listing_name is not None and names_are_same_person(contact_name, listing_name):
            # Two independent sources agree on the same person -- strong signal
            confidence_score = confidence_score + 15
            source_urls.append(listing.get("source_url"))
            logger.info(f"{company_name} -- listing confirms registry name")

        elif listing_name is not None and contact_name is None:
            # Listing is the only name source, less reliable
            contact_name = listing_name
            source_urls.append(listing.get("source_url"))
            confidence_score = confidence_score + 10
            logger.info(f"{company_name} -- listing only hit: {contact_name}")

    if enrichment is not None:
        enrichment_email = enrichment.get("email")
        enrichment_phone = enrichment.get("phone")
        provider_confidence = enrichment.get("provider_confidence", 0)

        if enrichment_email is not None:
            contact_email = enrichment_email
            source_urls.append(enrichment.get("source_url"))
            # Not taking provider confidence at face value
            # Weighting it at 40% because it is self-reported
            confidence_score = confidence_score + int(provider_confidence * 0.4)
            logger.info(f"{company_name} -- enrichment email: {contact_email}, provider confidence: {provider_confidence}")

        if enrichment_phone is not None:
            contact_phone = enrichment_phone
            confidence_score = confidence_score + 5

    # Grab phone from listing if enrichment did not give us one
    if contact_phone is None and listing is not None:
        listing_phone = listing.get("phone")
        if listing_phone is not None:
            contact_phone = listing_phone
            confidence_score = confidence_score + 5
            if listing.get("source_url") not in source_urls:
                source_urls.append(listing.get("source_url"))

    role_points = get_role_score(contact_role) * 3
    confidence_score = confidence_score + role_points

    active_source_count = 0
    if registry is not None:
        active_source_count = active_source_count + 1
    if listing is not None:
        active_source_count = active_source_count + 1
    if enrichment is not None:
        active_source_count = active_source_count + 1

    if active_source_count >= 2:
        confidence_score = confidence_score + 10
    if active_source_count == 3:
        confidence_score = confidence_score + 10

    if confidence_score > 100:
        confidence_score = 100

    contact_email_or_phone = ""
    if contact_email is not None:
        contact_email_or_phone = contact_email
    elif contact_phone is not None:
        contact_email_or_phone = contact_phone

    needs_human_review = confidence_score < CONFIDENCE_THRESHOLD or contact_email_or_phone == ""

    if needs_human_review:
        logger.warning(f"{company_name} -- flagged for human review, score: {confidence_score}")

    return {
        "contact_name": contact_name if contact_name is not None else "",
        "contact_role": contact_role if contact_role is not None else "",
        "contact_email_or_phone": contact_email_or_phone,
        "confidence_score": confidence_score,
        "source": " | ".join(source_urls),
        "needs_human_review": needs_human_review,
    }