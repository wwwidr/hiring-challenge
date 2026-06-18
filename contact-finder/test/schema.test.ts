import { describe, it, expect } from "vitest";
import { validateRow } from "../src/schemas/output.schema.js";
import type { ContactRow } from "../src/types/index.js";

function baseRow(overrides: Partial<ContactRow> = {}): ContactRow {
  return {
    company_name: "Acme",
    contact_name: "Jane Doe",
    contact_role: "Owner",
    contact_email_or_phone: "jane@acme.com",
    confidence_score: 80,
    source: [{ provider: "registry", source_url: "mock://r" }],
    needs_human_review: false,
    suppressed: null,
    ...overrides,
  };
}

describe("contactRowSchema invariants", () => {
  it("accepts a valid emitted row (email)", () => {
    expect(validateRow(baseRow()).ok).toBe(true);
  });

  it("accepts a valid emitted row (phone)", () => {
    expect(validateRow(baseRow({ contact_email_or_phone: "+1-402-555-0148" })).ok).toBe(true);
  });

  it("accepts a not-found row (empty contact, review, no source)", () => {
    const row = baseRow({
      contact_name: "",
      contact_role: "",
      contact_email_or_phone: "",
      confidence_score: 0,
      source: [],
      needs_human_review: true,
    });
    expect(validateRow(row).ok).toBe(true);
  });

  it("rejects an emitted contact with no provenance", () => {
    expect(validateRow(baseRow({ source: [] })).ok).toBe(false);
  });

  it("rejects an emitted contact flagged for review", () => {
    expect(validateRow(baseRow({ needs_human_review: true })).ok).toBe(false);
  });

  it("rejects an empty contact that is NOT flagged for review", () => {
    expect(validateRow(baseRow({ contact_email_or_phone: "", needs_human_review: false })).ok).toBe(false);
  });

  it("rejects a malformed contact value", () => {
    expect(validateRow(baseRow({ contact_email_or_phone: "not-an-email" })).ok).toBe(false);
  });

  it("rejects an out-of-range confidence score", () => {
    expect(validateRow(baseRow({ confidence_score: 150 })).ok).toBe(false);
  });
});
