import { describe, it, expect, beforeAll } from "vitest";
import { runContactFinder, type RunResult } from "../src/pipeline/controller.js";
import type { ContactRow } from "../src/types/index.js";

describe("runContactFinder (integration over the provided fixtures)", () => {
  let result: RunResult;
  const byName = (name: string): ContactRow =>
    result.rows.find((r) => r.company_name === name)!;

  beforeAll(() => {
    result = runContactFinder();
  });

  it("emits exactly one row per input company (parity)", () => {
    expect(result.summary.total).toBe(30);
    expect(result.rows).toHaveLength(30);
  });

  it("every emitted row passes schema validation", () => {
    expect(result.validationErrors).toHaveLength(0);
  });

  it("counts the 12 genuinely-not-found rows", () => {
    expect(result.summary.not_found).toBe(12);
    const notFound = byName("Redwood Cabinetry");
    expect(notFound.source).toHaveLength(0);
    expect(notFound.contact_email_or_phone).toBe("");
    expect(notFound.needs_human_review).toBe(true);
  });

  it("surfaces a company-wide suppression without dropping the row", () => {
    const pioneer = byName("Pioneer Landscaping Inc");
    expect(pioneer.suppressed).toBe("company");
    expect(pioneer.contact_email_or_phone).toBe("");
    expect(pioneer.contact_name).toBe("");
    expect(pioneer.needs_human_review).toBe(true);
  });

  it("blanks a contact-level suppressed email but keeps provenance", () => {
    const lakeside = byName("Lakeside Auto Glass");
    expect(lakeside.suppressed).toBe("contact");
    expect(lakeside.contact_email_or_phone).toBe("");
    expect(lakeside.source.length).toBeGreaterThan(0);
  });

  it("suppresses by phone regardless of format", () => {
    expect(byName("Sunbelt Roofing Co").suppressed).toBe("contact");
  });

  it("flags a non-decision-maker role for review", () => {
    const northgate = byName("Northgate HVAC Services"); // "Registered Agent"
    expect(northgate.needs_human_review).toBe(true);
    expect(northgate.contact_email_or_phone).toBe("");
  });

  it("emits a fully-corroborated contact with provenance", () => {
    const ironclad = byName("Ironclad Welding Shop");
    expect(ironclad.needs_human_review).toBe(false);
    expect(ironclad.contact_email_or_phone).not.toBe("");
    expect(ironclad.source).toHaveLength(3);
  });

  it("passes the no-listing owners whose name appears in the email", () => {
    expect(byName("Greenfield Catering Group").needs_human_review).toBe(false);
    expect(byName("Tidewater Plumbing & Heating").needs_human_review).toBe(false);
  });

  it("keeps a first-name-only email link in review", () => {
    // Bayview: "Karen Liu" vs karen@... — weak link, must not clear the bar.
    expect(byName("Bayview Auto Repair").needs_human_review).toBe(true);
  });

  it("produces the expected confident / review split for the current tuning", () => {
    expect(result.summary.suppressed).toBe(3);
    expect(result.summary.emitted).toBe(5);
    expect(result.summary.needs_review).toBe(25);
  });
});
