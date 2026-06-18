"""
Contact Finder — Stage B entry point.

Usage:
    python contact_finder.py

Reads  : ../data/companies.csv
Mocks  : ../mocks/enrichment_responses.json  (via providers.py)
Outputs: output.csv  (same directory as this script)
         output.json (machine-readable, includes notes field for reviewers)
"""

from __future__ import annotations

import csv
import json
import sys
from pathlib import Path

from providers import query_all
from scorer import score, ScoredContact

_CSV_INPUT = Path(__file__).parent.parent / "data" / "companies.csv"
_OUT_CSV = Path(__file__).parent / "output.csv"
_OUT_JSON = Path(__file__).parent / "output.json"

CSV_FIELDNAMES = [
    "company_name",
    "mailing_address",
    "contact_name",
    "contact_role",
    "contact_email_or_phone",
    "confidence_score",
    "source",
    "needs_human_review",
]


def process_company(company_name: str, mailing_address: str) -> dict:
    response = query_all(company_name)
    result: ScoredContact = score(response)

    return {
        "company_name": company_name,
        "mailing_address": mailing_address,
        "contact_name": result.contact_name or "",
        "contact_role": result.contact_role or "",
        "contact_email_or_phone": result.contact_email_or_phone or "",
        "confidence_score": result.confidence_score,
        "source": ", ".join(result.sources) if result.sources else "",
        "needs_human_review": result.needs_human_review,
        # Extra field in JSON only — helps human reviewers
        "_notes": result.notes or "",
    }


def main() -> None:
    rows: list[dict] = []

    with _CSV_INPUT.open(newline="") as f:
        reader = csv.DictReader(f)
        for record in reader:
            row = process_company(
                company_name=record["company_name"],
                mailing_address=record["mailing_address"],
            )
            rows.append(row)

    # ── CSV output (spec-compliant fields only) ───────────────────────────────
    with _OUT_CSV.open("w", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=CSV_FIELDNAMES, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)

    # ── JSON output (includes _notes for human reviewers) ────────────────────
    with _OUT_JSON.open("w") as f:
        json.dump(rows, f, indent=2)

    # ── Summary to stdout ─────────────────────────────────────────────────────
    total = len(rows)
    verified = sum(1 for r in rows if not r["needs_human_review"])
    review = total - verified

    print(f"Processed {total} companies")
    print(f"  Verified contacts : {verified}")
    print(f"  Needs human review: {review}")
    print(f"Output written to   : {_OUT_CSV}")
    print(f"                      {_OUT_JSON}")

    if "--verbose" in sys.argv:
        print()
        for r in rows:
            flag = "[REVIEW]" if r["needs_human_review"] else "[OK]    "
            print(
                f"{flag} {r['company_name']:<35} "
                f"score={r['confidence_score']:>3}  "
                f"contact={r['contact_email_or_phone'] or '—'}"
            )


if __name__ == "__main__":
    main()
