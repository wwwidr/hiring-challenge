import { describe, it, expect } from "vitest";
import { mergeCompany } from "../src/lib/merge.js";
import { scoringConfig as cfg } from "../src/config/scoring.config.js";
import type { ProviderResponse } from "../src/types/index.js";

describe("mergeCompany", () => {
  it("flags a missing company as not found", () => {
    const m = mergeCompany("Ghost Co", null, cfg);
    expect(m.found).toBe(false);
    expect(m.presentProviders).toEqual([]);
  });

  it("records which providers are present, in order", () => {
    const resp: ProviderResponse = {
      registry: { name: "A B", role: "Owner", source_url: "mock://r" },
      enrichment: { email: "a@b.com", phone: null, provider_confidence: 50, source_url: "mock://e" },
    };
    const m = mergeCompany("Co", resp, cfg);
    expect(m.found).toBe(true);
    expect(m.presentProviders).toEqual(["registry", "enrichment"]);
  });

  it("classifies an exact registry/listing name match", () => {
    const resp: ProviderResponse = {
      registry: { name: "Daniel Ortega", role: "Owner", source_url: "mock://r" },
      listing: { name: "Daniel Ortega", phone: "+1-402-555-0148", source_url: "mock://l" },
    };
    const m = mergeCompany("Co", resp, cfg);
    expect(m.nameMatch).toBe("exact");
    expect(m.nameUncomparable).toBe(false);
  });

  it("marks names uncomparable when there is no listing name", () => {
    const resp: ProviderResponse = {
      registry: { name: "Karen Liu", role: "Owner", source_url: "mock://r" },
      enrichment: { email: "karen@x.com", phone: "+1-253-555-0192", provider_confidence: 78, source_url: "mock://e" },
    };
    const m = mergeCompany("Co", resp, cfg);
    expect(m.nameMatch).toBe("none");
    expect(m.nameUncomparable).toBe(true);
  });

  it("detects a phone conflict between listing and enrichment", () => {
    const resp: ProviderResponse = {
      listing: { name: null, phone: "+1-111-111-1111", source_url: "mock://l" },
      enrichment: { email: null, phone: "+1-222-222-2222", provider_confidence: 50, source_url: "mock://e" },
    };
    expect(mergeCompany("Co", resp, cfg).phoneAgreement).toBe("conflict");
  });

  it("detects matching phones across sources", () => {
    const resp: ProviderResponse = {
      listing: { name: null, phone: "+1-480-555-0133", source_url: "mock://l" },
      enrichment: { email: "o@x.com", phone: "+1-480-555-0133", provider_confidence: 66, source_url: "mock://e" },
    };
    expect(mergeCompany("Co", resp, cfg).phoneAgreement).toBe("match");
  });

  it("wires the name<->email fallback link", () => {
    const resp: ProviderResponse = {
      registry: { name: "George Whitfield", role: "Owner", source_url: "mock://r" },
      enrichment: { email: "g.whitfield@x.com", phone: null, provider_confidence: 76, source_url: "mock://e" },
    };
    expect(mergeCompany("Co", resp, cfg).nameEmailMatch).toBe("strong");
  });
});
