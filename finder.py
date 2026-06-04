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