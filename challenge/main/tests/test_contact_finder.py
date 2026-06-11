import unittest

from pydantic import ValidationError

try:
    from challenge.main.contact_finder import pick_candidate
    from challenge.main.schema.models import OutputRow
except ModuleNotFoundError:
    from contact_finder import pick_candidate
    from schema.models import OutputRow


class ContactFinderTests(unittest.TestCase):
    def test_high_confidence_with_three_sources(self) -> None:
        candidate = pick_candidate(
            {
                "registry": {
                    "name": "Daniel Ortega",
                    "role": "Owner",
                    "source_url": "mock://registry/ne/cedar-ridge-plumbing",
                },
                "listing": {
                    "name": "Daniel Ortega",
                    "phone": "+1-402-555-0148",
                    "source_url": "mock://listing/cedar-ridge-plumbing",
                },
                "enrichment": {
                    "email": "d.ortega@cedarridgeplumbing.com",
                    "phone": None,
                    "provider_confidence": 84,
                    "source_url": "mock://enrichment/cedar-ridge-plumbing",
                },
            }
        )

        self.assertGreaterEqual(candidate.score, 70)
        self.assertFalse(candidate.needs_human_review)
        self.assertNotEqual(candidate.contact, "")

    def test_single_weak_enrichment_is_review(self) -> None:
        candidate = pick_candidate(
            {
                "enrichment": {
                    "email": "info@riversideprint.biz",
                    "phone": None,
                    "provider_confidence": 41,
                    "source_url": "mock://enrichment/riverside-print-sign",
                }
            }
        )

        self.assertLess(candidate.score, 70)
        self.assertTrue(candidate.needs_human_review)
        self.assertEqual(candidate.contact, "")

    def test_conflicting_names_are_penalized(self) -> None:
        candidate = pick_candidate(
            {
                "registry": {
                    "name": "Tina Alvarez",
                    "role": "Manager",
                    "source_url": "mock://registry/fl/coastal-breeze-pool",
                },
                "listing": {
                    "name": "Marcus Webb",
                    "phone": "+1-941-555-0146",
                    "source_url": "mock://listing/coastal-breeze-pool",
                },
            }
        )

        self.assertTrue(candidate.score <= 70)
        self.assertTrue(candidate.needs_human_review)

    def test_no_sources_results_in_review(self) -> None:
        candidate = pick_candidate({})
        self.assertEqual(candidate.score, 0)
        self.assertTrue(candidate.needs_human_review)
        self.assertEqual(candidate.contact, "")

    def test_schema_blocks_contact_below_threshold(self) -> None:
        with self.assertRaises(ValidationError):
            OutputRow.model_validate(
                {
                    "company_name": "Test Co",
                    "mailing_address": "123 Main",
                    "contact_name": "Person",
                    "contact_role": "owner",
                    "contact_email_or_phone": "person@testco.com",
                    "confidence_score": 50,
                    "source": "enrichment",
                    "needs_human_review": "true",
                }
            )


if __name__ == "__main__":
    unittest.main()
