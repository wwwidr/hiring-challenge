"""
Integration tests for the full pipeline (providers → scorer → output shape).

These tests exercise contact_finder.process_company() against real mock data
to verify the end-to-end output shape and business invariants.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent.parent))

import pytest
from contact_finder import process_company


class TestOutputShape:
    """Every row must have all required fields."""

    REQUIRED_FIELDS = {
        "company_name", "mailing_address", "contact_name",
        "contact_role", "contact_email_or_phone",
        "confidence_score", "source", "needs_human_review",
    }

    def _check(self, company: str, address: str):
        row = process_company(company, address)
        assert self.REQUIRED_FIELDS.issubset(row.keys()), \
            f"Missing fields in output for {company}"
        assert isinstance(row["confidence_score"], int)
        assert isinstance(row["needs_human_review"], bool)
        assert 0 <= row["confidence_score"] <= 100

    def test_high_confidence_company(self):
        self._check("Cedar Ridge Plumbing LLC", "4821 Maple Ave, Lincoln, NE 68504")

    def test_cannot_verify_company(self):
        # Not in mock data at all
        self._check("Redwood Cabinetry", "509 Timber Ct, Eugene, OR 97401")

    def test_conflict_company(self):
        self._check("Coastal Breeze Pool Service", "233 Seagrape Way, Sarasota, FL 34236")


class TestBusinessInvariants:
    """Core rules that must hold for every row."""

    def test_below_threshold_has_no_contact_method(self):
        # Summit Pest — weak enrichment, generic email
        row = process_company("Summit Pest Control", "6310 Highland Blvd, Reno, NV 89506")
        assert row["needs_human_review"] is True
        assert row["contact_email_or_phone"] == ""

    def test_cannot_verify_all_nulled(self):
        # No mock data for this company
        row = process_company("Liberty Sign & Awning", "1290 Freedom Blvd, Allen, TX 75002")
        assert row["needs_human_review"] is True
        assert row["contact_name"] == ""
        assert row["contact_email_or_phone"] == ""
        assert row["confidence_score"] == 0
        assert row["source"] == ""

    def test_verified_contact_has_source(self):
        # Verified rows must carry at least one source URL
        row = process_company("Pioneer Landscaping Inc", "940 Prairie View Dr, Boise, ID 83704")
        assert row["needs_human_review"] is False
        assert row["source"] != ""
        assert "mock://" in row["source"]

    def test_conflict_goes_to_review(self):
        row = process_company("Coastal Breeze Pool Service", "233 Seagrape Way, Sarasota, FL 34236")
        assert row["needs_human_review"] is True

    def test_registered_agent_goes_to_review(self):
        row = process_company("Northgate HVAC Services", "56 Industrial Pkwy, Akron, OH 44310")
        assert row["needs_human_review"] is True

    def test_triple_source_agreement_verified(self):
        row = process_company("Brookside Veterinary Clinic", "760 Willow Bend, Chattanooga, TN 37402")
        assert row["needs_human_review"] is False
        assert row["contact_name"] == "Dr. Emily Hart"
        assert row["confidence_score"] == 100

    def test_harbor_light_nickname_match_verified(self):
        # S. Murphy (listing) matches Sean Murphy (registry) → above threshold
        row = process_company("Harbor Light Electric", "22 Dockside Ave, New Bedford, MA 02740")
        assert row["needs_human_review"] is False
        assert row["confidence_score"] == 73

    def test_ironclad_bob_robert_nickname(self):
        # Bob (listing) = Robert (registry) via nickname resolution
        row = process_company("Ironclad Welding Shop", "1701 Foundry Rd, Pittsburgh, PA 15201")
        assert row["needs_human_review"] is False
        assert row["confidence_score"] == 100
