"""
Mock provider adapter.

Loads enrichment_responses.json and exposes one method per provider.
Each method returns a typed dict or None if the provider has no data
for that company (intentional "not found" — treat as absence, not error).
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Optional, TypedDict

_MOCK_PATH = Path(__file__).parent.parent / "mocks" / "enrichment_responses.json"


class RegistryResult(TypedDict):
    name: Optional[str]
    role: Optional[str]
    source_url: str


class ListingResult(TypedDict):
    name: Optional[str]
    phone: Optional[str]
    source_url: str


class EnrichmentResult(TypedDict):
    email: Optional[str]
    phone: Optional[str]
    provider_confidence: int
    source_url: str


class ProviderResponse(TypedDict):
    registry: Optional[RegistryResult]
    listing: Optional[ListingResult]
    enrichment: Optional[EnrichmentResult]


def _load() -> dict:
    with _MOCK_PATH.open() as f:
        return json.load(f)


_DATA: dict = _load()


def query_all(company_name: str) -> ProviderResponse:
    """Return all three providers' responses for a company, or None per provider if absent."""
    record = _DATA.get(company_name, {})
    return ProviderResponse(
        registry=record.get("registry"),
        listing=record.get("listing"),
        enrichment=record.get("enrichment"),
    )
