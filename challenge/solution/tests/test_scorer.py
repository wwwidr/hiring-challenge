"""
Unit tests for scorer.py — covering each scoring rule in isolation
and the most important edge cases visible in the mock data.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent.parent))

import pytest
from scorer import (
    CONFIDENCE_THRESHOLD,
    _email_matches_name,
    _is_generic_email,
    _names_match,
    score,
)
from providers import ProviderResponse


# ── Name matching ────────────────────────────────────────────────────────────

class TestNamesMatch:
    def test_exact_match(self):
        assert _names_match("Daniel Ortega", "Daniel Ortega")

    def test_case_insensitive(self):
        assert _names_match("daniel ortega", "DANIEL ORTEGA")

    def test_initial_match(self):
        # "S. Murphy" should match "Sean Murphy"
        assert _names_match("S. Murphy", "Sean Murphy")

    def test_nickname_match(self):
        # "Bob Kowalski" (listing) should match "Robert Kowalski" (registry)
        assert _names_match("Bob Kowalski", "Robert Kowalski")

    def test_honorific_stripped(self):
        # "Dr. Emily Hart" should match "Emily Hart"
        assert _names_match("Dr. Emily Hart", "Emily Hart")

    def test_different_last_names_no_match(self):
        # Coastal Breeze conflict case
        assert not _names_match("Tina Alvarez", "Marcus Webb")

    def test_same_first_different_last_no_match(self):
        assert not _names_match("Karen Smith", "Karen Liu")


# ── Email matching ───────────────────────────────────────────────────────────

class TestEmailMatchesName:
    def test_initial_dot_last(self):
        # d.ortega@ → Daniel Ortega
        assert _email_matches_name("d.ortega@cedarridgeplumbing.com", "Daniel Ortega")

    def test_first_only(self):
        # karen@ → Karen Liu
        assert _email_matches_name("karen@bayviewauto.com", "Karen Liu")

    def test_first_dot_last(self):
        # emily.hart@ → Emily Hart
        assert _email_matches_name("emily.hart@brooksidevet.com", "Dr. Emily Hart")

    def test_nickname_in_email(self):
        # bob@ → Robert Kowalski (bob is nickname for Robert)
        assert _email_matches_name("bob@ironcladweld.com", "Robert Kowalski")

    def test_initial_dot_last_with_first_initial(self):
        # a.brooks@ → Angela Brooks
        assert _email_matches_name("a.brooks@greenfieldcater.com", "Angela Brooks")

    def test_generic_email_never_matches(self):
        assert not _email_matches_name("info@example.com", "Angela Brooks")
        assert not _email_matches_name("office@example.com", "Daniel Ortega")

    def test_mismatched_last_name(self):
        assert not _email_matches_name("d.smith@example.com", "Daniel Ortega")


# ── Generic email detection ──────────────────────────────────────────────────

class TestIsGenericEmail:
    def test_info(self):
        assert _is_generic_email("info@riversideprint.biz")

    def test_contact(self):
        assert _is_generic_email("contact@summitpest.io")

    def test_sales(self):
        assert _is_generic_email("sales@anchormarine.co")

    def test_office(self):
        assert _is_generic_email("office@sunbeltroofingaz.com")

    def test_personal_not_generic(self):
        assert not _is_generic_email("karen@bayviewauto.com")
        assert not _is_generic_email("d.ortega@cedarridgeplumbing.com")


# ── Confidence score integration ─────────────────────────────────────────────

def _make_response(registry=None, listing=None, enrichment=None) -> ProviderResponse:
    return ProviderResponse(registry=registry, listing=listing, enrichment=enrichment)


class TestScoreHighConfidence:
    """Triple-source agreement → should score well above threshold."""

    def test_cedar_ridge(self):
        resp = _make_response(
            registry={"name": "Daniel Ortega", "role": "Owner",
                       "source_url": "mock://registry/ne/cedar-ridge-plumbing"},
            listing={"name": "Daniel Ortega", "phone": "+1-402-555-0148",
                     "source_url": "mock://listing/cedar-ridge-plumbing"},
            enrichment={"email": "d.ortega@cedarridgeplumbing.com", "phone": None,
                        "provider_confidence": 84,
                        "source_url": "mock://enrichment/cedar-ridge-plumbing"},
        )
        result = score(resp)
        assert result.confidence_score == 100  # capped
        assert not result.needs_human_review
        assert result.contact_name == "Daniel Ortega"
        assert result.contact_email_or_phone == "d.ortega@cedarridgeplumbing.com"
        assert len(result.sources) == 3

    def test_pioneer_landscaping(self):
        resp = _make_response(
            registry={"name": "Maria Gomez", "role": "President",
                       "source_url": "mock://registry/id/pioneer-landscaping"},
            listing={"name": "Maria Gomez", "phone": "+1-208-555-0175",
                     "source_url": "mock://listing/pioneer-landscaping"},
            enrichment={"email": "maria@pioneerlandscaping.com", "phone": "+1-208-555-0175",
                        "provider_confidence": 88,
                        "source_url": "mock://enrichment/pioneer-landscaping"},
        )
        result = score(resp)
        assert result.confidence_score == 100
        assert not result.needs_human_review


class TestScoreConflict:
    """Conflicting names across sources → must flag for human review."""

    def test_coastal_breeze_conflict(self):
        resp = _make_response(
            registry={"name": "Tina Alvarez", "role": "Manager",
                       "source_url": "mock://registry/fl/coastal-breeze-pool"},
            listing={"name": "Marcus Webb", "phone": "+1-941-555-0146",
                     "source_url": "mock://listing/coastal-breeze-pool"},
        )
        result = score(resp)
        assert result.needs_human_review
        assert result.notes is not None
        assert "conflict" in result.notes.lower()
        # Contact fields nulled out on review rows
        assert result.contact_name is None
        assert result.contact_email_or_phone is None


class TestScoreCannotVerify:
    """No providers → score = 0, needs_human_review = True."""

    def test_no_sources(self):
        resp = _make_response()
        result = score(resp)
        assert result.confidence_score == 0
        assert result.needs_human_review
        assert result.contact_name is None
        assert result.sources == []


class TestScoreLowConfidence:
    """Weak enrichment-only hits → below threshold."""

    def test_summit_pest_generic_email(self):
        resp = _make_response(
            enrichment={"email": "contact@summitpest.io", "phone": None,
                        "provider_confidence": 38,
                        "source_url": "mock://enrichment/summit-pest-control"},
        )
        result = score(resp)
        assert result.confidence_score < CONFIDENCE_THRESHOLD
        assert result.needs_human_review

    def test_riverside_generic_info_email(self):
        resp = _make_response(
            enrichment={"email": "info@riversideprint.biz", "phone": None,
                        "provider_confidence": 41,
                        "source_url": "mock://enrichment/riverside-print-sign"},
        )
        result = score(resp)
        assert result.confidence_score < CONFIDENCE_THRESHOLD
        assert result.needs_human_review


class TestScoreBorderline:
    """Harbor Light: registry + listing agree on name, no enrichment → just above threshold."""

    def test_harbor_light(self):
        resp = _make_response(
            registry={"name": "Sean Murphy", "role": "Owner",
                       "source_url": "mock://registry/ma/harbor-light-electric"},
            listing={"name": "S. Murphy", "phone": "+1-508-555-0160",
                     "source_url": "mock://listing/harbor-light-electric"},
        )
        result = score(resp)
        # base=50 + name_agree=15 + owner_role=8 = 73
        assert result.confidence_score == 73
        assert not result.needs_human_review
        # No enrichment email → fall back to listing phone
        assert result.contact_email_or_phone == "+1-508-555-0160"


class TestScoreRegisteredAgent:
    """Registered Agent role should be penalized — lower-value decision-maker."""

    def test_northgate_registered_agent(self):
        resp = _make_response(
            registry={"name": "Thomas Reed", "role": "Registered Agent",
                       "source_url": "mock://registry/oh/northgate-hvac"},
        )
        result = score(resp)
        # base=50, role=-5 → 45 < 70
        assert result.confidence_score == 45
        assert result.needs_human_review


class TestProvenanceNeverEmpty:
    """Every non-zero result must carry at least one source URL."""

    def test_single_enrichment_source_tracked(self):
        resp = _make_response(
            enrichment={"email": "karen@bayviewauto.com", "phone": None,
                        "provider_confidence": 78,
                        "source_url": "mock://enrichment/bayview-auto-repair"},
        )
        result = score(resp)
        assert len(result.sources) >= 1
        assert all(s.startswith("mock://") for s in result.sources)

    def test_no_sources_means_empty_list(self):
        resp = _make_response()
        result = score(resp)
        assert result.sources == []
