/**
 * CLI entry: runs the pipeline against the challenge fixtures and prints a readable
 * table + summary to stdout.
 */

import { runContactFinder } from "./pipeline/controller.js";
import type { ContactRow } from "./types/index.js";

function truncate(value: string, width: number): string {
  return value.length <= width ? value.padEnd(width) : value.slice(0, width - 1) + "…";
}

function reviewLabel(row: ContactRow): string {
  if (row.suppressed === "company") return "SUPPRESSED(co)";
  if (row.suppressed === "contact") return "SUPPRESSED(ct)";
  if (row.source.length === 0) return "NOT FOUND";
  return row.needs_human_review ? "review" : "OK";
}

function printTable(rows: ContactRow[]): void {
  const cols: [string, number][] = [
    ["Company", 30],
    ["Role", 16],
    ["Contact", 32],
    ["Score", 5],
    ["Status", 14],
  ];
  const header = cols.map(([name, w]) => truncate(name, w)).join("  ");
  console.log(header);
  console.log("-".repeat(header.length));
  for (const row of rows) {
    const line = [
      truncate(row.company_name, 30),
      truncate(row.contact_role || "—", 16),
      truncate(row.contact_email_or_phone || "—", 32),
      String(row.confidence_score).padStart(5),
      truncate(reviewLabel(row), 14),
    ].join("  ");
    console.log(line);
  }
}

function main(): void {
  const result = runContactFinder();

  printTable(result.rows);

  const s = result.summary;
  console.log("\nSummary");
  console.log(
    `  ${s.total} companies — ${s.emitted} confident, ${s.needs_review} need review ` +
      `(${s.not_found} not found, ${s.suppressed} suppressed)`,
  );

  if (result.parseErrors.length > 0) {
    console.log(`\nCSV notes (${result.parseErrors.length}):`);
    for (const e of result.parseErrors) console.log(`  line ${e.line}: ${e.reason}`);
  }

  if (result.validationErrors.length > 0) {
    console.error(`\n⚠ ${result.validationErrors.length} row(s) FAILED schema validation:`);
    for (const v of result.validationErrors) {
      console.error(`  ${JSON.stringify(v.row)} -> ${v.errors.map((i) => i.message).join("; ")}`);
    }
    process.exitCode = 1;
  } else {
    console.log("\n✓ all rows passed schema validation");
  }
}

main();
