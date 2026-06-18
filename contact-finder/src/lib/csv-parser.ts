/**
 * CSV ingestion for the input company list. Tolerant by design: a malformed row
 * is recorded as an error rather than throwing, so one bad line never sinks the run.
 */

import { readFileSync } from "node:fs";
import { parse } from "csv-parse/sync";
import type { CompanyInput } from "../types/index.js";

export interface ParseOptions {
  /** Hard cap on accepted rows (defends against accidental huge uploads). */
  maxRows?: number;
}

export interface ParseError {
  line: number;
  reason: string;
}

export interface ParseResult {
  rows: CompanyInput[];
  errors: ParseError[];
}

const DEFAULT_MAX_ROWS = 1000;
const REQUIRED_COLUMNS = ["company_name", "mailing_address"] as const;

export function parseCompaniesCsv(csvText: string, opts: ParseOptions = {}): ParseResult {
  const maxRows = opts.maxRows ?? DEFAULT_MAX_ROWS;
  const errors: ParseError[] = [];
  const rows: CompanyInput[] = [];
  const seen = new Set<string>();

  let records: Record<string, string>[];
  try {
    records = parse(csvText, {
      columns: true,
      skip_empty_lines: true,
      trim: true,
      bom: true,
      relax_column_count: true,
    }) as Record<string, string>[];
  } catch (err) {
    return {
      rows: [],
      errors: [{ line: 0, reason: `CSV could not be parsed: ${(err as Error).message}` }],
    };
  }

  if (records.length > 0) {
    const header = Object.keys(records[0]!);
    const missing = REQUIRED_COLUMNS.filter((c) => !header.includes(c));
    if (missing.length > 0) {
      return {
        rows: [],
        errors: [{ line: 1, reason: `Missing required column(s): ${missing.join(", ")}` }],
      };
    }
  }

  let capReported = false;
  records.forEach((record, i) => {
    const line = i + 2; // +1 for header, +1 for 1-based line numbers
    if (rows.length >= maxRows) {
      if (!capReported) {
        errors.push({ line, reason: `Row limit (${maxRows}) reached; remaining rows skipped` });
        capReported = true;
      }
      return;
    }

    const company_name = (record.company_name ?? "").trim();
    const mailing_address = (record.mailing_address ?? "").trim();

    if (!company_name) {
      errors.push({ line, reason: "Empty company_name" });
      return;
    }

    const key = company_name.toLowerCase();
    if (seen.has(key)) {
      errors.push({ line, reason: `Duplicate company_name "${company_name}" — skipped` });
      return;
    }
    seen.add(key);

    rows.push({ company_name, mailing_address });
  });

  return { rows, errors };
}

export function loadCompaniesCsv(absPath: string, opts: ParseOptions = {}): ParseResult {
  const text = readFileSync(absPath, "utf8");
  return parseCompaniesCsv(text, opts);
}
