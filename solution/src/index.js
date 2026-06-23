/**
 * index.js — Contact Finder entry point
 *
 * Pipeline:
 *   1. Parse companies.csv
 *   2. For each company, query mock providers
 *   3. Resolve best contact using cross-reference logic
 *   4. Output results as JSON + summary table to stdout
 *   5. Write results to output/results.json
 *
 * Adaptations from CLARIFICATIONS.md:
 *   - Confidence threshold: 70
 *   - Role priority: AP > Owner > CFO > Office Manager
 *   - Precision over recall: "cannot verify" is encouraged
 *   - US B2B only, business contacts only
 *   - Provenance tracked for every value
 */

import { resolve, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { writeFileSync, mkdirSync } from "node:fs";
import { parseCSV } from "./csv.js";
import { queryProviders } from "./providers.js";
import { resolveContact, CONFIDENCE_THRESHOLD } from "./resolver.js";

const __dirname = dirname(fileURLToPath(import.meta.url));

// ── Paths ──────────────────────────────────────────────────────────────
const CSV_PATH = resolve(__dirname, "../../challenge/data/companies.csv");
const OUTPUT_DIR = resolve(__dirname, "../output");
const OUTPUT_PATH = resolve(OUTPUT_DIR, "results.json");

// ── Main ───────────────────────────────────────────────────────────────
function main() {
  console.log("═══════════════════════════════════════════════════════════");
  console.log("  Contact Finder — Stage B Slice (mocked providers)");
  console.log("═══════════════════════════════════════════════════════════\n");

  // 1. Parse CSV
  const companies = parseCSV(CSV_PATH);
  console.log(`Loaded ${companies.length} companies from CSV.\n`);

  // 2+3. Query providers → resolve contacts
  const results = companies.map((row) => {
    const providers = queryProviders(row.company_name);
    const resolved = resolveContact(row.company_name, providers);
    return {
      ...resolved,
      mailing_address: row.mailing_address,
      // Strip provenance detail from the flat output (kept in full JSON)
      provenance: undefined,
    };
  });

  // Keep full provenance in a separate detailed output
  const detailedResults = companies.map((row) => {
    const providers = queryProviders(row.company_name);
    return resolveContact(row.company_name, providers);
  });

  // 4. Print summary table
  console.log("─── Results ───────────────────────────────────────────────\n");

  const found = results.filter((r) => !r.needs_human_review);
  const review = results.filter((r) => r.needs_human_review);

  console.log(
    `Found: ${found.length} | Needs review: ${review.length} | Total: ${results.length}\n`
  );

  // Print each result
  for (const r of results) {
    const status = r.needs_human_review ? "🔍 REVIEW" : "✅ FOUND ";
    console.log(`${status}  ${r.company_name}`);
    if (!r.needs_human_review) {
      console.log(`         Name:  ${r.contact_name || "(none)"}`);
      console.log(`         Role:  ${r.contact_role || "(none)"}`);
      console.log(`         Contact: ${r.contact_email_or_phone}`);
      console.log(`         Score:   ${r.confidence_score}/100`);
      console.log(`         Source:  ${r.source}`);
    } else {
      console.log(
        `         Score:   ${r.confidence_score}/100 (below threshold ${CONFIDENCE_THRESHOLD})`
      );
      if (r.source !== "none") {
        console.log(`         Partial data from: ${r.source}`);
      } else {
        console.log("         No data found from any provider.");
      }
    }
    console.log();
  }

  // 5. Write full JSON output
  mkdirSync(OUTPUT_DIR, { recursive: true });
  writeFileSync(OUTPUT_PATH, JSON.stringify(detailedResults, null, 2));
  console.log(`\nFull results written to: output/results.json`);

  // Summary stats
  console.log("\n─── Confidence Distribution ────────────────────────────────");
  const buckets = { "90-100": 0, "70-89": 0, "50-69": 0, "0-49": 0 };
  for (const r of results) {
    const s = r.confidence_score;
    if (s >= 90) buckets["90-100"]++;
    else if (s >= 70) buckets["70-89"]++;
    else if (s >= 50) buckets["50-69"]++;
    else buckets["0-49"]++;
  }
  for (const [range, count] of Object.entries(buckets)) {
    const bar = "█".repeat(count);
    console.log(`  ${range}: ${bar} (${count})`);
  }
  console.log();
}

main();
