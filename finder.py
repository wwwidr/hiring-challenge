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