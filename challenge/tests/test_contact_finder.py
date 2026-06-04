"""
tests/test_contact_finder.py

Unit tests for the Contact Finder scoring engine.

Covers:
  - Name fuzzy matching (exact, nickname, initial abbreviation, conflict)
  - company_slug normalisation
  - Email domain plausibility
  - Score engine against representative mock scenarios
  - Threshold boundary: contact cleared below 70
  - Provenance: source_url present when data exists, empty when it doesn't
  - Cannot-verify rows: score=0, all fields empty
"""

import os
import sys

import pytest

# Allow running from repo root: python -m pytest challenge/tests/
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from contact_finder import (
    CONFIDENCE_THRESHOLD,
    ProviderData,
    company_slug,
    email_domain_matches_slug,
    names_agree,
    score_company,
)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def registry(name, role, url="mock://registry/test"):
    return ProviderData(name=name, role=role, source_url=url)


def listing(name, phone, url="mock://listing/test"):
    return ProviderData(name=name, phone=phone, source_url=url)


def enrichment(email, phone, conf, url="mock://enrichment/test"):
    return ProviderData(email=email, phone=phone, provider_confidence=conf, source_url=url)


# ---------------------------------------------------------------------------
# names_agree
# ---------------------------------------------------------------------------

class TestNamesAgree:
    def test_exact_match(self):
        assert names_agree("Daniel Ortega", "Daniel Ortega") is True

    def test_case_insensitive(self):
        assert names_agree("MARIA GOMEZ", "maria gomez") is True

    def test_nickname_bob_robert(self):
        assert names_agree("Bob Kowalski", "Robert Kowalski") is True

    def test_nickname_dan_daniel(self):
        assert names_agree("Dan Ortega", "Daniel Ortega") is True

    def test_initial_abbreviation_first_name(self):
        assert names_agree("S. Murphy", "Sean Murphy") is True

    def test_initial_abbreviation_reversed(self):
        assert names_agree("Sean Murphy", "S. Murphy") is True

    def test_surname_only_match(self):
        assert names_agree("Karen Liu", "K. Liu") is True

    def test_clear_different_people(self):
        assert names_agree("Tina Alvarez", "Marcus Webb") is False

    def test_none_left(self):
        assert names_agree(None, "Sean Murphy") is False

    def test_none_right(self):
        assert names_agree("Sean Murphy", None) is False

    def test_both_none(self):
        assert names_agree(None, None) is False

    def test_empty_string(self):
        assert names_agree("", "Sean Murphy") is False


# ---------------------------------------------------------------------------
# company_slug
# ---------------------------------------------------------------------------

class TestCompanySlug:
    def test_strips_llc(self):
        assert "cedar-ridge-plumbing" in company_slug("Cedar Ridge Plumbing LLC")

    def test_strips_inc(self):
        slug = company_slug("Pioneer Landscaping Inc")
        assert "pioneer" in slug
        assert "inc" not in slug

    def test_lowercase(self):
        assert company_slug("Bayview Auto Repair") == company_slug("bayview auto repair")

    def test_strips_special_chars(self):
        # "Frontier Towing & Recovery" — ampersand becomes a separator
        slug = company_slug("Frontier Towing & Recovery")
        assert "&" not in slug


# ---------------------------------------------------------------------------
# email_domain_matches_slug
# ---------------------------------------------------------------------------

class TestEmailDomainMatchesSlug:
    def test_matching_domain(self):
        assert email_domain_matches_slug(
            "d.ortega@cedarridgeplumbing.com", "cedar-ridge-plumbing"
        ) is True

    def test_partial_word_match(self):
        assert email_domain_matches_slug(
            "karen@bayviewauto.com", "bayview-auto-repair"
        ) is True

    def test_generic_domain_no_match(self):
        assert email_domain_matches_slug(
            "info@gmail.com", "cedar-ridge-plumbing"
        ) is False

    def test_none_email(self):
        assert email_domain_matches_slug(None, "cedar-ridge-plumbing") is False

    def test_email_no_at_sign(self):
        assert email_domain_matches_slug("notanemail", "cedar-ridge") is False


# ---------------------------------------------------------------------------
# score_company — high-confidence cases (expect verified, score ≥ 70)
# ---------------------------------------------------------------------------

class TestHighConfidenceCases:
    def test_all_three_sources_agree(self):
        """Cedar Ridge Plumbing pattern: all 3 sources, names agree, conf=84."""
        result = score_company(
            registry   = registry("Daniel Ortega", "Owner",
                                   "mock://registry/ne/cedar-ridge-plumbing"),
            listing    = listing("Daniel Ortega", "+1-402-555-0148",
                                  "mock://listing/cedar-ridge-plumbing"),
            enrichment = enrichment("d.ortega@cedarridgeplumbing.com", None, 84,
                                     "mock://enrichment/cedar-ridge-plumbing"),
            slug       = "cedar-ridge-plumbing",
        )
        assert result.confidence_score >= CONFIDENCE_THRESHOLD
        assert result.needs_human_review is False
        assert result.contact_email_or_phone != ""
        assert len(result.sources) == 3

    def test_registry_plus_enrichment(self):
        """Bayview Auto Repair: registry + enrichment email+phone, conf=78."""
        result = score_company(
            registry   = registry("Karen Liu", "Owner",
                                   "mock://registry/wa/bayview-auto-repair"),
            listing    = None,
            enrichment = enrichment("karen@bayviewauto.com", "+1-253-555-0192", 78,
                                     "mock://enrichment/bayview-auto-repair"),
            slug       = "bayview-auto-repair",
        )
        assert result.confidence_score >= CONFIDENCE_THRESHOLD
        assert result.needs_human_review is False
        assert result.contact_name == "Karen Liu"

    def test_all_three_high_conf(self):
        """Pioneer Landscaping: all 3 agree, conf=88 → near max score."""
        result = score_company(
            registry   = registry("Maria Gomez", "President",
                                   "mock://registry/id/pioneer-landscaping"),
            listing    = listing("Maria Gomez", "+1-208-555-0175",
                                  "mock://listing/pioneer-landscaping"),
            enrichment = enrichment("maria@pioneerlandscaping.com", "+1-208-555-0175", 88,
                                     "mock://enrichment/pioneer-landscaping"),
            slug       = "pioneer-landscaping",
        )
        assert result.confidence_score >= 90
        assert result.needs_human_review is False

    def test_nickname_match_counts_as_agreement(self):
        """Ironclad Welding: Bob (listing) ≈ Robert (registry) → names agree."""
        result = score_company(
            registry   = registry("Robert Kowalski", "Owner",
                                   "mock://registry/pa/ironclad-welding"),
            listing    = listing("Bob Kowalski", "+1-412-555-0184",
                                  "mock://listing/ironclad-welding"),
            enrichment = enrichment("bob@ironcladweld.com", "+1-412-555-0184", 81,
                                     "mock://enrichment/ironclad-welding"),
            slug       = "ironclad-welding",
        )
        assert result.confidence_score >= CONFIDENCE_THRESHOLD
        assert result.needs_human_review is False

    def test_initial_abbreviation_counts_as_agreement(self):
        """Harbor Light Electric: S. Murphy (listing) ≈ Sean Murphy (registry)."""
        result = score_company(
            registry   = registry("Sean Murphy", "Owner",
                                   "mock://registry/ma/harbor-light-electric"),
            listing    = listing("S. Murphy", "+1-508-555-0160",
                                  "mock://listing/harbor-light-electric"),
            enrichment = None,
            slug       = "harbor-light-electric",
        )
        assert result.confidence_score >= CONFIDENCE_THRESHOLD
        assert result.needs_human_review is False


# ---------------------------------------------------------------------------
# score_company — needs-review cases (expect score < 70)
# ---------------------------------------------------------------------------

class TestNeedsReviewCases:
    def test_name_conflict_triggers_review(self):
        """Coastal Breeze Pool: registry=Tina Alvarez, listing=Marcus Webb → conflict."""
        result = score_company(
            registry   = registry("Tina Alvarez", "Manager",
                                   "mock://registry/fl/coastal-breeze-pool"),
            listing    = listing("Marcus Webb", "+1-941-555-0146",
                                  "mock://listing/coastal-breeze-pool"),
            enrichment = None,
            slug       = "coastal-breeze-pool",
        )
        assert result.needs_human_review is True
        assert result.contact_email_or_phone == ""

    def test_registered_agent_only_scores_low(self):
        """Northgate HVAC: registry only with Registered Agent role."""
        result = score_company(
            registry   = registry("Thomas Reed", "Registered Agent",
                                   "mock://registry/oh/northgate-hvac"),
            listing    = None,
            enrichment = None,
            slug       = "northgate-hvac",
        )
        assert result.needs_human_review is True

    def test_sole_enrichment_low_confidence(self):
        """Riverside Print & Sign: enrichment only, conf=41 → cannot verify."""
        result = score_company(
            registry   = None,
            listing    = None,
            enrichment = enrichment("info@riversideprint.biz", None, 41,
                                     "mock://enrichment/riverside-print-sign"),
            slug       = "riverside-print-sign",
        )
        assert result.needs_human_review is True
        assert result.contact_email_or_phone == ""

    def test_listing_only_no_name(self):
        """Maple Leaf Bakery: listing has phone but no name → unverifiable."""
        result = score_company(
            registry   = None,
            listing    = listing(None, "+1-802-555-0121",
                                  "mock://listing/maple-leaf-bakery"),
            enrichment = None,
            slug       = "maple-leaf-bakery",
        )
        assert result.needs_human_review is True
        assert result.contact_name == ""


# ---------------------------------------------------------------------------
# score_company — cannot-verify cases (score = 0, all fields empty)
# ---------------------------------------------------------------------------

class TestCannotVerifyCases:
    def test_no_sources_at_all(self):
        """11 companies with no mock data → score=0, everything empty."""
        result = score_company(None, None, None, slug="crescent-moon-cafe")
        assert result.confidence_score == 0
        assert result.needs_human_review is True
        assert result.contact_name == ""
        assert result.contact_role == ""
        assert result.contact_email_or_phone == ""
        assert result.sources == []

    def test_very_low_enrichment_confidence(self):
        """Summit Pest Control: conf=38, enrichment only."""
        result = score_company(
            registry   = None,
            listing    = None,
            enrichment = enrichment("contact@summitpest.io", None, 38,
                                     "mock://enrichment/summit-pest-control"),
            slug       = "summit-pest-control",
        )
        assert result.confidence_score == 0
        assert result.needs_human_review is True


# ---------------------------------------------------------------------------
# Threshold boundary & provenance invariants
# ---------------------------------------------------------------------------

class TestInvariants:
    def test_contact_cleared_below_threshold(self):
        """Below threshold: contact_email_or_phone must be empty — no fabrication."""
        result = score_company(
            registry   = None,
            listing    = None,
            enrichment = enrichment("guess@example.com", None, 35,
                                     "mock://enrichment/example"),
            slug       = "example-company",
        )
        assert result.needs_human_review is True
        assert result.contact_email_or_phone == ""

    def test_raw_channel_preserved_for_human_reviewers(self):
        """Even when flagged, the raw channel is kept for the human queue."""
        result = score_company(
            registry   = None,
            listing    = None,
            enrichment = enrichment("guess@example.com", None, 35,
                                     "mock://enrichment/example"),
            slug       = "example-company",
        )
        assert result.raw_contact_channel == "guess@example.com"

    def test_sources_populated_when_data_present(self):
        """Every result with data must carry at least one source_url."""
        result = score_company(
            registry   = registry("Karen Liu", "Owner",
                                   "mock://registry/wa/bayview"),
            listing    = None,
            enrichment = None,
            slug       = "bayview-auto-repair",
        )
        assert "mock://registry/wa/bayview" in result.sources

    def test_no_fabricated_source_urls(self):
        """Cannot-verify rows must not fabricate source URLs."""
        result = score_company(None, None, None, slug="ghost-company")
        assert result.sources == []

    def test_score_clamped_to_100(self):
        """Score never exceeds 100 even with all signals firing."""
        result = score_company(
            registry   = registry("Maria Gomez", "President",
                                   "mock://registry/id/pioneer"),
            listing    = listing("Maria Gomez", "+1-208-555-0175",
                                  "mock://listing/pioneer"),
            enrichment = enrichment("maria@pioneerlandscaping.com", "+1-208-555-0175", 95,
                                     "mock://enrichment/pioneer"),
            slug       = "pioneer-landscaping",
        )
        assert result.confidence_score <= 100

    def test_score_clamped_to_zero(self):
        """Score never goes negative."""
        result = score_company(None, None, None, slug="nothing")
        assert result.confidence_score >= 0
