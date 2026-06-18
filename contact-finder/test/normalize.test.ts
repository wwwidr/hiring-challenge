import { describe, it, expect } from "vitest";
import {
  normalizeName,
  normalizePhone,
  phonesEqual,
  classifyNameMatch,
  classifyNameEmailMatch,
} from "../src/lib/normalize.js";

const TH = { fuzzyThreshold: 0.6, surnameThreshold: 0.8 };

describe("normalizeName", () => {
  it("strips titles, parentheticals, and punctuation", () => {
    expect(normalizeName("Dr. Emily Hart")).toBe("emily hart");
    expect(normalizeName("Jeff (manager)")).toBe("jeff");
    expect(normalizeName("S. Murphy")).toBe("s murphy");
  });

  it("returns empty string for null/empty", () => {
    expect(normalizeName(null)).toBe("");
    expect(normalizeName("")).toBe("");
  });
});

describe("normalizePhone", () => {
  it("reduces to digits and drops the US country code", () => {
    expect(normalizePhone("+1-402-555-0148")).toBe("4025550148");
    expect(normalizePhone("(402) 555.0148")).toBe("4025550148");
  });

  it("returns null when there are no digits", () => {
    expect(normalizePhone(null)).toBeNull();
    expect(normalizePhone("n/a")).toBeNull();
  });
});

describe("phonesEqual", () => {
  it("matches across formats, ignores nulls", () => {
    expect(phonesEqual("+1-480-555-0133", "480.555.0133")).toBe(true);
    expect(phonesEqual("+1-480-555-0133", "+1-480-555-0134")).toBe(false);
    expect(phonesEqual(null, "480.555.0133")).toBe(false);
  });
});

describe("classifyNameMatch", () => {
  it("detects exact matches (after normalization)", () => {
    expect(classifyNameMatch("Daniel Ortega", "Daniel Ortega", TH)).toBe("exact");
    expect(classifyNameMatch("Dr. Emily Hart", "Emily Hart", TH)).toBe("exact");
  });

  it("detects initials and nicknames as fuzzy", () => {
    expect(classifyNameMatch("Sean Murphy", "S. Murphy", TH)).toBe("fuzzy");
    expect(classifyNameMatch("Robert Kowalski", "Bob Kowalski", TH)).toBe("fuzzy");
  });

  it("treats conflicting names as none", () => {
    expect(classifyNameMatch("Tina Alvarez", "Marcus Webb", TH)).toBe("none");
  });

  it("does not match on a shared first name when surnames differ", () => {
    expect(classifyNameMatch("John Smith", "John Jones", TH)).toBe("none");
  });

  it("returns none when either side is empty (uncomparable)", () => {
    expect(classifyNameMatch("Daniel Ortega", null, TH)).toBe("none");
    expect(classifyNameMatch(null, "Daniel Ortega", TH)).toBe("none");
  });
});

describe("classifyNameEmailMatch", () => {
  it("is strong when the last name appears in the local-part", () => {
    expect(classifyNameEmailMatch("George Whitfield", "g.whitfield@x.com")).toBe("strong");
    expect(classifyNameEmailMatch("Angela Brooks", "a.brooks@x.com")).toBe("strong");
  });

  it("is weak when only the first name appears", () => {
    expect(classifyNameEmailMatch("Karen Liu", "karen@x.com")).toBe("weak");
  });

  it("caps a single-token name at weak (can't confirm a surname)", () => {
    expect(classifyNameEmailMatch("Jeff", "jeff@x.com")).toBe("weak");
  });

  it("is none for a generic email that omits the name", () => {
    expect(classifyNameEmailMatch("Jane Doe", "info@acme.com")).toBe("none");
  });

  it("is none without an email or name", () => {
    expect(classifyNameEmailMatch("Jane Doe", null)).toBe("none");
    expect(classifyNameEmailMatch(null, "info@acme.com")).toBe("none");
  });
});
