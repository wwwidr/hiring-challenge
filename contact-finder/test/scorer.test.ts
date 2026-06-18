import { describe, it, expect } from "vitest";
import {
  scoreRole,
  scorePhone,
  scoreEmail,
  scoreRelationshipBonus,
  scoreCompany,
} from "../src/lib/scorer.js";
import { scoringConfig as cfg } from "../src/config/scoring.config.js";
import type { MergedCompany, EnrichmentRecord } from "../src/types/index.js";

function makeMerged(overrides: Partial<MergedCompany> = {}): MergedCompany {
  return {
    company_name: "Test Co",
    found: true,
    presentProviders: [],
    nameMatch: "none",
    nameUncomparable: true,
    nameEmailMatch: "none",
    phoneAgreement: "none",
    chosenName: "",
    chosenRole: "",
    ...overrides,
  };
}

const enrichmentWithEmail: EnrichmentRecord = {
  email: "x@y.com",
  phone: null,
  provider_confidence: 80,
  source_url: "mock://e",
};

describe("scoreRole", () => {
  it("maps each persona to its tier, in priority order", () => {
    expect(scoreRole(makeMerged({ chosenRole: "Accounts Payable Manager" }), cfg).tier).toBe("accounts_payable");
    expect(scoreRole(makeMerged({ chosenRole: "Owner" }), cfg).tier).toBe("owner_founder");
    expect(scoreRole(makeMerged({ chosenRole: "President" }), cfg).tier).toBe("owner_founder");
    expect(scoreRole(makeMerged({ chosenRole: "CFO" }), cfg).tier).toBe("cfo_finance");
    expect(scoreRole(makeMerged({ chosenRole: "Office Manager" }), cfg).tier).toBe("office_manager");
  });

  it("scores non-decision-maker roles as zero", () => {
    expect(scoreRole(makeMerged({ chosenRole: "Registered Agent" }), cfg).points).toBe(cfg.roleNoMatchPoints);
    // A bare "Manager" is too generic and must not match the office-manager tier.
    expect(scoreRole(makeMerged({ chosenRole: "Manager" }), cfg).tier).toBeNull();
    expect(scoreRole(makeMerged({ chosenRole: "" }), cfg).points).toBe(0);
  });
});

describe("scorePhone", () => {
  it("gives full credit when phones match and the name is aligned", () => {
    expect(scorePhone(makeMerged({ phoneAgreement: "match", nameMatch: "exact", nameUncomparable: false }), cfg)).toBe(cfg.phone.match);
  });

  it("gives partial credit for a matching phone with no name to attribute it to", () => {
    expect(scorePhone(makeMerged({ phoneAgreement: "match", chosenName: "Jane Doe" }), cfg)).toBe(cfg.phone.partial);
  });

  it("blocks phone credit entirely when names conflict", () => {
    expect(scorePhone(makeMerged({ phoneAgreement: "match", nameMatch: "none", nameUncomparable: false }), cfg)).toBe(cfg.phone.none);
  });

  it("blocks phone credit for a fully anonymous record", () => {
    // EDGE CASE: corroborated-anonymous phone (e.g. Sunbelt) — no name anywhere.
    expect(scorePhone(makeMerged({ phoneAgreement: "match", chosenName: "" }), cfg)).toBe(cfg.phone.none);
  });

  it("gives no credit when phones conflict", () => {
    // EDGE CASE: listing.phone !== enrichment.phone — untested by the provided fixture.
    expect(scorePhone(makeMerged({ phoneAgreement: "conflict", chosenName: "Jane Doe" }), cfg)).toBe(cfg.phone.none);
  });

  it("gives partial credit when exactly one source has a phone", () => {
    expect(scorePhone(makeMerged({ phoneAgreement: "one", chosenName: "Jane Doe" }), cfg)).toBe(cfg.phone.partial);
  });

  it("blocks even a single-source phone when the two names conflict", () => {
    expect(scorePhone(makeMerged({ phoneAgreement: "one", nameMatch: "none", nameUncomparable: false }), cfg)).toBe(cfg.phone.none);
  });
});

describe("scoreEmail", () => {
  it("corroborates an email when a phone matches across sources", () => {
    const m = makeMerged({ phoneAgreement: "match", enrichment: enrichmentWithEmail });
    expect(scoreEmail(m, cfg)).toBe(cfg.email.corroborated);
  });

  it("counts an uncorroborated email lower", () => {
    const m = makeMerged({ phoneAgreement: "one", enrichment: enrichmentWithEmail });
    expect(scoreEmail(m, cfg)).toBe(cfg.email.uncorroborated);
  });

  it("scores zero with no email", () => {
    expect(scoreEmail(makeMerged(), cfg)).toBe(cfg.email.none);
  });
});

describe("scoreRelationshipBonus", () => {
  it("rewards two-source name agreement above the email fallback", () => {
    expect(scoreRelationshipBonus(makeMerged({ nameMatch: "exact" }), cfg).points).toBe(cfg.relationshipBonus.nameExact);
    expect(scoreRelationshipBonus(makeMerged({ nameMatch: "fuzzy" }), cfg).points).toBe(cfg.relationshipBonus.nameFuzzy);
  });

  it("falls back to the name<->email link when there is no name to compare", () => {
    expect(scoreRelationshipBonus(makeMerged({ nameEmailMatch: "strong" }), cfg).points).toBe(cfg.relationshipBonus.emailStrong);
    expect(scoreRelationshipBonus(makeMerged({ nameEmailMatch: "weak" }), cfg).points).toBe(cfg.relationshipBonus.emailWeak);
  });

  it("gives no relationship credit when nothing links contact to identity", () => {
    expect(scoreRelationshipBonus(makeMerged(), cfg).points).toBe(cfg.relationshipBonus.none);
  });
});

describe("scoreCompany", () => {
  it("zeroes a not-found company", () => {
    const b = scoreCompany(makeMerged({ found: false }), cfg);
    expect(b.total).toBe(0);
    expect(b.role).toBe(0);
  });

  it("EDGE CASE: an AP-manager full match clears the threshold and exercises the score cap", () => {
    const m = makeMerged({
      chosenRole: "Accounts Payable Manager",
      chosenName: "Pat Lee",
      nameMatch: "exact",
      nameUncomparable: false,
      phoneAgreement: "match",
      enrichment: { ...enrichmentWithEmail, phone: "+1-555-555-5555" },
    });
    const b = scoreCompany(m, cfg);
    expect(b.roleTier).toBe("accounts_payable");
    expect(b.total).toBe(cfg.maxScore); // raw 103 -> capped at 99
    expect(b.notes.some((n) => /capped/i.test(n))).toBe(true);
  });

  it("EDGE CASE: an owner with a generic email earns no relationship bonus and stays under threshold", () => {
    const m = makeMerged({
      chosenRole: "Owner",
      chosenName: "Jane Doe",
      nameEmailMatch: "none", // email is e.g. info@acme.com — name absent
      phoneAgreement: "one",
      enrichment: { email: "info@acme.com", phone: "+1-555-555-5555", provider_confidence: 50, source_url: "mock://e" },
    });
    const b = scoreCompany(m, cfg);
    expect(b.relationshipBonus).toBe(cfg.relationshipBonus.none);
    expect(b.total).toBeLessThan(cfg.threshold);
  });

  it("EDGE CASE: a phone conflict drops phone credit", () => {
    const m = makeMerged({
      chosenRole: "Owner",
      chosenName: "Jane Doe",
      phoneAgreement: "conflict",
      enrichment: enrichmentWithEmail,
    });
    expect(scoreCompany(m, cfg).phone).toBe(cfg.phone.none);
  });
});
