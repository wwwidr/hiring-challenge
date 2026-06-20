from __future__ import annotations

from typing import Dict, Literal, Optional

from pydantic import BaseModel, ConfigDict, Field, model_validator


class RegistryRecord(BaseModel):
    model_config = ConfigDict(extra="ignore")

    name: Optional[str] = None
    role: Optional[str] = None
    source_url: Optional[str] = None


class ListingRecord(BaseModel):
    model_config = ConfigDict(extra="ignore")

    name: Optional[str] = None
    phone: Optional[str] = None
    source_url: Optional[str] = None


class EnrichmentRecord(BaseModel):
    model_config = ConfigDict(extra="ignore")

    email: Optional[str] = None
    phone: Optional[str] = None
    provider_confidence: Optional[int] = Field(default=None, ge=0, le=100)
    source_url: Optional[str] = None


class CompanyProviders(BaseModel):
    model_config = ConfigDict(extra="ignore")

    registry: Optional[RegistryRecord] = None
    listing: Optional[ListingRecord] = None
    enrichment: Optional[EnrichmentRecord] = None


class InputCompanyRow(BaseModel):
    model_config = ConfigDict(extra="ignore")

    company_name: str = Field(min_length=1)
    mailing_address: str = Field(min_length=1)


class OutputRow(BaseModel):
    model_config = ConfigDict(extra="ignore")

    company_name: str
    mailing_address: str
    contact_name: str
    contact_role: str
    contact_email_or_phone: str
    confidence_score: int = Field(ge=0, le=100)
    source: str
    needs_human_review: str

    @model_validator(mode="after")
    def validate_threshold_rule(self) -> "OutputRow":
        if self.confidence_score < 70 and self.contact_email_or_phone:
            raise ValueError("contact_email_or_phone must be empty when confidence_score < 70")
        if self.needs_human_review not in {"true", "false"}:
            raise ValueError("needs_human_review must be 'true' or 'false'")
        return self


class ProvenanceEntry(BaseModel):
    model_config = ConfigDict(extra="ignore")

    score_breakdown: Dict[str, int]
    source_urls: Dict[str, str]
    selected_contact: str
    selected_role: Optional[Literal["ap_manager", "owner", "cfo", "office_manager", "other"]] = None
