import { describe, it, expect } from "vitest";
import { parseCompaniesCsv } from "../src/lib/csv-parser.js";

const HEADER = "company_name,mailing_address";

describe("parseCompaniesCsv", () => {
  it("parses rows and preserves quoted commas in the address", () => {
    const csv = `${HEADER}\nCedar Ridge Plumbing LLC,"4821 Maple Ave, Lincoln, NE 68504"`;
    const { rows, errors } = parseCompaniesCsv(csv);
    expect(errors).toHaveLength(0);
    expect(rows).toEqual([
      { company_name: "Cedar Ridge Plumbing LLC", mailing_address: "4821 Maple Ave, Lincoln, NE 68504" },
    ]);
  });

  it("dedupes by company_name case-insensitively", () => {
    const csv = `${HEADER}\nAcme,1 A St\nACME,2 B St`;
    const { rows, errors } = parseCompaniesCsv(csv);
    expect(rows).toHaveLength(1);
    expect(errors.some((e) => /duplicate/i.test(e.reason))).toBe(true);
  });

  it("records an error for an empty company_name and skips it", () => {
    const csv = `${HEADER}\n,123 Nowhere St\nReal Co,5 Main St`;
    const { rows, errors } = parseCompaniesCsv(csv);
    expect(rows).toHaveLength(1);
    expect(rows[0]!.company_name).toBe("Real Co");
    expect(errors.some((e) => /empty company_name/i.test(e.reason))).toBe(true);
  });

  it("fails clearly when a required column is missing", () => {
    const csv = `name,address\nAcme,1 A St`;
    const { rows, errors } = parseCompaniesCsv(csv);
    expect(rows).toHaveLength(0);
    expect(errors[0]!.reason).toMatch(/missing required column/i);
  });

  it("enforces the row cap and reports it once", () => {
    const csv = `${HEADER}\nA,x\nB,y\nC,z`;
    const { rows, errors } = parseCompaniesCsv(csv, { maxRows: 2 });
    expect(rows).toHaveLength(2);
    expect(errors.filter((e) => /row limit/i.test(e.reason))).toHaveLength(1);
  });

  it("tolerates a UTF-8 BOM on the header", () => {
    const csv = `﻿${HEADER}\nAcme,1 A St`;
    const { rows, errors } = parseCompaniesCsv(csv);
    expect(errors).toHaveLength(0);
    expect(rows[0]!.company_name).toBe("Acme");
  });
});
