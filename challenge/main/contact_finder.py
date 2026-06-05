from __future__ import annotations

import argparse
import csv
import json
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Dict, List, Optional, Tuple

try:
    from challenge.main.schema import CompanyProviders, InputCompanyRow, OutputRow, ProvenanceEntry
except ModuleNotFoundError:
    from schema import CompanyProviders, InputCompanyRow, OutputRow, ProvenanceEntry

ROLE_BUCKETS = {
    "ap_manager": ["accounts payable", "ap manager", "a/p", "payables"],
    "owner": ["owner", "founder", "president", "principal"],
    "cfo": ["cfo", "finance lead", "finance director", "vp finance"],
    "office_manager": ["office manager", "manager"],
}

GENERIC_EMAIL_LOCALS = {"info", "contact", "sales", "office", "admin"}


@dataclass
class Candidate:
    name: Optional[str]
    role_bucket: Optional[str]
    contact: str
    score: int
    needs_human_review: bool
    source: str
    score_breakdown: Dict[str, int]


def normalize_text(value: Optional[str]) -> str:
    if not value:
        return ""
    return re.sub(r"\s+", " ", value.strip().lower())


def split_email_local(email: str) -> str:
    if "@" not in email:
        return ""
    return email.split("@", 1)[0].strip().lower()


def is_similar_name(a: Optional[str], b: Optional[str]) -> bool:
    na = normalize_text(a)
    nb = normalize_text(b)
    if not na or not nb:
        return False
    return na == nb or na.replace(".", "") == nb.replace(".", "")


def bucket_role(role: Optional[str], name: Optional[str]) -> Optional[str]:
    haystack = f"{normalize_text(role)} {normalize_text(name)}"
    if not haystack.strip():
        return None

    for bucket, terms in ROLE_BUCKETS.items():
        if any(term in haystack for term in terms):
            return bucket

    return "other"


def role_points(bucket: Optional[str], role: Optional[str]) -> int:
    if bucket == "ap_manager":
        return 25
    if bucket == "owner":
        return 22
    if bucket == "cfo":
        return 20
    if bucket == "office_manager":
        return 14
    if normalize_text(role) == "registered agent":
        return 8
    if bucket == "other":
        return 10
    return 0


def contact_points(contact: Optional[str], enrichment_conf: Optional[int], listing_phone_match: bool) -> int:
    if not contact:
        return 0

    if "@" in contact:
        return min(25, 10 + int((enrichment_conf or 0) * 0.15))

    if listing_phone_match:
        return 20

    if (enrichment_conf or 0) > 0:
        return min(20, 8 + int((enrichment_conf or 0) * 0.12))

    return 12


def choose_candidate_identity(providers: CompanyProviders) -> Tuple[Optional[str], Optional[str], Optional[str]]:
    reg_name = providers.registry.name if providers.registry else None
    reg_role = providers.registry.role if providers.registry else None
    list_name = providers.listing.name if providers.listing else None

    reg_bucket = bucket_role(reg_role, reg_name)
    list_bucket = bucket_role(None, list_name)

    priority = {
        "ap_manager": 4,
        "owner": 3,
        "cfo": 2,
        "office_manager": 1,
        "other": 0,
        None: -1,
    }
    if priority.get(reg_bucket, -1) >= priority.get(list_bucket, -1):
        return reg_name, reg_role, reg_bucket
    return list_name, None, list_bucket


def choose_contact(providers: CompanyProviders) -> Optional[str]:
    if providers.enrichment and providers.enrichment.email:
        return providers.enrichment.email
    if providers.enrichment and providers.enrichment.phone:
        return providers.enrichment.phone
    if providers.listing and providers.listing.phone:
        return providers.listing.phone
    return None


def compute_score(providers: CompanyProviders, role_bucket: Optional[str], selected_role: Optional[str], contact: Optional[str]) -> Tuple[int, Dict[str, int]]:
    has_registry = providers.registry is not None
    has_listing = providers.listing is not None
    has_enrichment = providers.enrichment is not None

    identity = min(30, (20 if has_registry else 0) + (10 if has_listing else 0) + (5 if has_enrichment else 0))
    role = role_points(role_bucket, selected_role)

    enrichment_conf = providers.enrichment.provider_confidence if providers.enrichment else None
    listing_phone = providers.listing.phone if providers.listing else None
    enrich_phone = providers.enrichment.phone if providers.enrichment else None
    listing_phone_match = bool(listing_phone and enrich_phone and listing_phone == enrich_phone)

    contact_score = contact_points(contact, enrichment_conf, listing_phone_match)

    provider_count = sum([has_registry, has_listing, has_enrichment])
    corroboration = 12 if provider_count >= 3 else 8 if provider_count == 2 else 0

    reg_name = providers.registry.name if providers.registry else None
    list_name = providers.listing.name if providers.listing else None
    if is_similar_name(reg_name, list_name):
        corroboration += 5
    if listing_phone_match:
        corroboration += 5
    corroboration = min(corroboration, 20)

    penalties = 0
    if provider_count == 1:
        penalties += 15
    if has_enrichment and not has_registry and not has_listing and (enrichment_conf or 0) < 60:
        penalties += 10
    if normalize_text(selected_role) == "registered agent":
        penalties += 12
    if reg_name and list_name and not is_similar_name(reg_name, list_name):
        penalties += 8
    if contact and "@" in contact and split_email_local(contact) in GENERIC_EMAIL_LOCALS:
        penalties += 6
    if not role_bucket:
        penalties += 5

    score = max(0, min(100, identity + role + contact_score + corroboration - penalties))
    return score, {
        "identity": identity,
        "role": role,
        "contact": contact_score,
        "corroboration": corroboration,
        "penalties": penalties,
    }


def pick_candidate(company_data: Dict[str, object]) -> Candidate:
    providers = CompanyProviders.model_validate(company_data)
    name, role, role_bucket = choose_candidate_identity(providers)
    contact = choose_contact(providers)
    score, breakdown = compute_score(providers, role_bucket, role, contact)

    providers_present: List[str] = []
    if providers.registry:
        providers_present.append("registry")
    if providers.listing:
        providers_present.append("listing")
    if providers.enrichment:
        providers_present.append("enrichment")

    needs_human_review = score < 70
    final_contact = "" if needs_human_review else (contact or "")

    if not name and providers.enrichment and providers.enrichment.email:
        name = "Unknown"
    if not role_bucket and name:
        role_bucket = "other"

    return Candidate(
        name=name,
        role_bucket=role_bucket,
        contact=final_contact,
        score=score,
        needs_human_review=needs_human_review,
        source="|".join(providers_present),
        score_breakdown=breakdown,
    )


def build_rows(input_csv: Path, responses_json: Path) -> Tuple[List[OutputRow], Dict[str, ProvenanceEntry]]:
    with responses_json.open("r", encoding="utf-8") as f:
        responses = json.load(f)

    rows: List[OutputRow] = []
    provenance: Dict[str, ProvenanceEntry] = {}

    with input_csv.open("r", encoding="utf-8", newline="") as f:
        reader = csv.DictReader(f)
        for raw_row in reader:
            input_row = InputCompanyRow.model_validate(raw_row)
            company_data = responses.get(input_row.company_name, {})
            providers = CompanyProviders.model_validate(company_data)
            candidate = pick_candidate(company_data)

            output_row = OutputRow.model_validate(
                {
                    "company_name": input_row.company_name,
                    "mailing_address": input_row.mailing_address,
                    "contact_name": candidate.name or "",
                    "contact_role": candidate.role_bucket or "",
                    "contact_email_or_phone": candidate.contact,
                    "confidence_score": candidate.score,
                    "source": candidate.source,
                    "needs_human_review": str(candidate.needs_human_review).lower(),
                }
            )
            rows.append(output_row)

            source_urls: Dict[str, str] = {}
            if providers.registry and providers.registry.source_url:
                source_urls["registry"] = providers.registry.source_url
            if providers.listing and providers.listing.source_url:
                source_urls["listing"] = providers.listing.source_url
            if providers.enrichment and providers.enrichment.source_url:
                source_urls["enrichment"] = providers.enrichment.source_url

            provenance[input_row.company_name] = ProvenanceEntry.model_validate(
                {
                    "score_breakdown": candidate.score_breakdown,
                    "source_urls": source_urls,
                    "selected_contact": candidate.contact,
                    "selected_role": candidate.role_bucket,
                }
            )

    return rows, provenance


def write_outputs(rows: List[OutputRow], provenance: Dict[str, ProvenanceEntry], output_csv: Path, provenance_json: Path) -> None:
    output_csv.parent.mkdir(parents=True, exist_ok=True)
    provenance_json.parent.mkdir(parents=True, exist_ok=True)

    fieldnames = [
        "company_name",
        "mailing_address",
        "contact_name",
        "contact_role",
        "contact_email_or_phone",
        "confidence_score",
        "source",
        "needs_human_review",
    ]
    with output_csv.open("w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows([row.model_dump() for row in rows])

    with provenance_json.open("w", encoding="utf-8") as f:
        json.dump({k: v.model_dump() for k, v in provenance.items()}, f, indent=2)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Stage B contact finder using mock providers.")
    parser.add_argument("--input", default="challenge/data/companies.csv", help="Input CSV path")
    parser.add_argument("--mocks", default="challenge/mocks/enrichment_responses.json", help="Mock provider JSON path")
    parser.add_argument("--output", default="challenge/output/contact_results.csv", help="Output CSV path")
    parser.add_argument(
        "--provenance-output",
        default="challenge/output/contact_provenance.json",
        help="Provenance JSON path",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    rows, provenance = build_rows(Path(args.input), Path(args.mocks))
    write_outputs(rows, provenance, Path(args.output), Path(args.provenance_output))


if __name__ == "__main__":
    main()
