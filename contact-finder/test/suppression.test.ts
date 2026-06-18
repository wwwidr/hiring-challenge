import { describe, it, expect } from "vitest";
import { checkSuppression, loadSuppressionList } from "../src/lib/suppression.js";
import type { MergedCompany, SuppressionList } from "../src/types/index.js";

function company(name: string): MergedCompany {
  return {
    company_name: name,
    found: true,
    presentProviders: [],
    nameMatch: "none",
    nameUncomparable: true,
    nameEmailMatch: "none",
    phoneAgreement: "none",
    chosenName: "",
    chosenRole: "",
  };
}

const list: SuppressionList = {
  companies: ["Pioneer Landscaping Inc"],
  emails: ["jeff@lakesideglass.net"],
  phones: ["480.555.0133"],
};

describe("checkSuppression", () => {
  it("matches a company name case-insensitively", () => {
    expect(checkSuppression(company("pioneer landscaping inc"), "x@y.com", list).level).toBe("company");
  });

  it("company-wide suppression takes precedence over a contact match", () => {
    const res = checkSuppression(company("Pioneer Landscaping Inc"), "jeff@lakesideglass.net", list);
    expect(res.level).toBe("company");
  });

  it("matches a suppressed email", () => {
    expect(checkSuppression(company("Lakeside"), "jeff@lakesideglass.net", list).level).toBe("contact");
  });

  it("matches a suppressed phone across formats (normalized)", () => {
    expect(checkSuppression(company("Sunbelt"), "+1-480-555-0133", list).level).toBe("contact");
  });

  it("returns none when nothing matches or there is no contact", () => {
    expect(checkSuppression(company("Other Co"), "someone@else.com", list).level).toBe("none");
    expect(checkSuppression(company("Other Co"), "", list).level).toBe("none");
  });
});

describe("loadSuppressionList", () => {
  it("treats a missing file as no suppressions", () => {
    const empty = loadSuppressionList("/no/such/suppression.json");
    expect(empty).toEqual({ companies: [], emails: [], phones: [] });
  });
});
