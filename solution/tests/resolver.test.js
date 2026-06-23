/**
 * resolver.test.js — Unit tests for the contact resolver
 *
 * Uses Node.js built-in test runner (node --test).
 */

import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  resolveContact,
  normalizeName,
  namesMatch,
  rolePriority,
  CONFIDENCE_THRESHOLD,
} from "../src/resolver.js";

// ── Helper tests ────────────────────────────────────────────────────────

describe("normalizeName", () => {
  it("lowercases and trims", () => {
    assert.equal(normalizeName("  Daniel Ortega "), "daniel ortega");
  });

  it("strips title prefixes", () => {
    assert.equal(normalizeName("Dr. Emily Hart"), "emily hart");
    assert.equal(normalizeName("Mr. John Smith"), "john smith");
  });

  it("strips parentheticals", () => {
    assert.equal(normalizeName("Jeff (manager)"), "jeff");
  });

  it("returns null for null/empty input", () => {
    assert.equal(normalizeName(null), null);
    assert.equal(normalizeName(""), null);
  });
});

describe("namesMatch", () => {
  it("matches exact names", () => {
    assert.ok(namesMatch("Daniel Ortega", "Daniel Ortega"));
  });

  it("matches initials", () => {
    assert.ok(namesMatch("S. Murphy", "Sean Murphy"));
  });

  it("matches nicknames", () => {
    assert.ok(namesMatch("Bob Kowalski", "Robert Kowalski"));
  });

  it("rejects different people", () => {
    assert.ok(!namesMatch("Tina Alvarez", "Marcus Webb"));
  });

  it("handles null gracefully", () => {
    assert.ok(!namesMatch(null, "Daniel Ortega"));
    assert.ok(!namesMatch("Daniel Ortega", null));
  });
});

describe("rolePriority", () => {
  it("Owner ranks higher (lower number) than Manager", () => {
    assert.ok(rolePriority("Owner") < rolePriority("Manager"));
  });

  it("Registered Agent is lowest known role", () => {
    assert.ok(rolePriority("Registered Agent") > rolePriority("Owner"));
  });

  it("unknown roles get Infinity", () => {
    assert.equal(rolePriority("Janitor"), Infinity);
  });
});

// ── Resolver tests ──────────────────────────────────────────────────────

describe("resolveContact", () => {
  it("high-confidence: 3 sources agreeing on same person", () => {
    const result = resolveContact("Cedar Ridge Plumbing LLC", {
      registry: {
        name: "Daniel Ortega",
        role: "Owner",
        source_url: "mock://registry/ne/cedar-ridge-plumbing",
      },
      listing: {
        name: "Daniel Ortega",
        phone: "+1-402-555-0148",
        source_url: "mock://listing/cedar-ridge-plumbing",
      },
      enrichment: {
        email: "d.ortega@cedarridgeplumbing.com",
        phone: null,
        provider_confidence: 84,
        source_url: "mock://enrichment/cedar-ridge-plumbing",
      },
    });

    assert.equal(result.contact_name, "Daniel Ortega");
    assert.equal(result.contact_role, "Owner");
    assert.equal(
      result.contact_email_or_phone,
      "d.ortega@cedarridgeplumbing.com"
    );
    assert.ok(result.confidence_score >= 90);
    assert.equal(result.needs_human_review, false);
    assert.ok(result.source.includes("registry"));
    assert.ok(result.source.includes("listing"));
    assert.ok(result.source.includes("enrichment"));
  });

  it("cannot-verify: no providers have data", () => {
    const result = resolveContact("Redwood Cabinetry", {});

    assert.equal(result.contact_name, "");
    assert.equal(result.contact_email_or_phone, "");
    assert.equal(result.confidence_score, 0);
    assert.equal(result.needs_human_review, true);
  });

  it("low-confidence enrichment-only with weak provider_confidence", () => {
    const result = resolveContact("Summit Pest Control", {
      enrichment: {
        email: "contact@summitpest.io",
        phone: null,
        provider_confidence: 38,
        source_url: "mock://enrichment/summit-pest-control",
      },
    });

    assert.ok(result.confidence_score < CONFIDENCE_THRESHOLD);
    assert.equal(result.needs_human_review, true);
    assert.equal(result.contact_email_or_phone, "");
  });

  it("conflicting names across sources → flagged for review (ambiguous)", () => {
    const result = resolveContact("Coastal Breeze Pool Service", {
      registry: {
        name: "Tina Alvarez",
        role: "Manager",
        source_url: "mock://registry/fl/coastal-breeze-pool",
      },
      listing: {
        name: "Marcus Webb",
        phone: "+1-941-555-0146",
        source_url: "mock://listing/coastal-breeze-pool",
      },
    });

    // Two sources name different people → genuinely ambiguous → review
    assert.equal(result.needs_human_review, true);
    assert.ok(result.confidence_score < CONFIDENCE_THRESHOLD);
    // Provenance is still tracked even on review rows
    assert.ok(result.provenance.length === 2);
  });

  it("nickname match boosts confidence: Bob ↔ Robert", () => {
    const result = resolveContact("Ironclad Welding Shop", {
      registry: {
        name: "Robert Kowalski",
        role: "Owner",
        source_url: "mock://registry/pa/ironclad-welding",
      },
      listing: {
        name: "Bob Kowalski",
        phone: "+1-412-555-0184",
        source_url: "mock://listing/ironclad-welding",
      },
      enrichment: {
        email: "bob@ironcladweld.com",
        phone: "+1-412-555-0184",
        provider_confidence: 81,
        source_url: "mock://enrichment/ironclad-welding",
      },
    });

    assert.ok(result.confidence_score >= 90);
    assert.equal(result.needs_human_review, false);
  });

  it("single Registered Agent with no other sources → low confidence", () => {
    const result = resolveContact("Northgate HVAC Services", {
      registry: {
        name: "Thomas Reed",
        role: "Registered Agent",
        source_url: "mock://registry/oh/northgate-hvac",
      },
    });

    // Registered Agent alone is a weak signal
    assert.ok(result.confidence_score < CONFIDENCE_THRESHOLD);
    assert.equal(result.needs_human_review, true);
  });

  it("listing with phone only (no name) → below threshold", () => {
    const result = resolveContact("Maple Leaf Bakery", {
      listing: {
        name: null,
        phone: "+1-802-555-0121",
        source_url: "mock://listing/maple-leaf-bakery",
      },
    });

    assert.ok(result.confidence_score < CONFIDENCE_THRESHOLD);
    assert.equal(result.needs_human_review, true);
  });

  it("every result has provenance array", () => {
    const result = resolveContact("Bayview Auto Repair", {
      registry: {
        name: "Karen Liu",
        role: "Owner",
        source_url: "mock://registry/wa/bayview-auto-repair",
      },
      enrichment: {
        email: "karen@bayviewauto.com",
        phone: "+1-253-555-0192",
        provider_confidence: 78,
        source_url: "mock://enrichment/bayview-auto-repair",
      },
    });

    assert.ok(Array.isArray(result.provenance));
    assert.ok(result.provenance.length > 0);
    for (const p of result.provenance) {
      assert.ok(p.source_url.startsWith("mock://"));
    }
  });
});
