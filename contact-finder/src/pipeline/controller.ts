/**
 * Orchestration: CSV -> per-company merge -> score -> suppression -> threshold -> validated row.
 * Pure assembly over the lib modules; the only side effects are the file reads in the
 * loaders it calls. Produces exactly one output row per input company (input/output parity).
 */

import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import type {
  CompanyInput,
  ContactRow,
  MergedCompany,
  ScoreBreakdown,
  SourceRef,
} from "../types/index.js";
import { scoringConfig, type ScoringConfig } from "../config/scoring.config.js";
import { loadCompaniesCsv, type ParseError } from "../lib/csv-parser.js";
import { loadFixture, lookupCompany } from "../lib/providers.js";
import { mergeCompany } from "../lib/merge.js";
import { scoreCompany } from "../lib/scorer.js";
import {
  checkSuppression,
  loadSuppressionList,
  type SuppressionResult,
} from "../lib/suppression.js";
import { validateRows, type ValidationFailure } from "../schemas/output.schema.js";

// Resolve the challenge fixtures relative to this file, so the slice runs from anywhere.
const HERE = dirname(fileURLToPath(import.meta.url));
const CHALLENGE = resolve(HERE, "../../../challenge");

export const DEFAULT_PATHS = {
  csvPath: resolve(CHALLENGE, "data/companies.csv"),
  fixturePath: resolve(CHALLENGE, "mocks/enrichment_responses.json"),
  suppressionPath: resolve(CHALLENGE, "mocks/suppression.json"),
};

export interface RunOptions {
  csvPath?: string;
  fixturePath?: string;
  suppressionPath?: string;
  config?: ScoringConfig;
  /** Include the explainable score_breakdown on each row (default true). */
  includeBreakdown?: boolean;
}

export interface RunSummary {
  total: number;
  emitted: number;
  needs_review: number;
  not_found: number;
  suppressed: number;
}

export interface RunResult {
  rows: ContactRow[];
  parseErrors: ParseError[];
  validationErrors: ValidationFailure[];
  summary: RunSummary;
}

function buildSourceRefs(merged: MergedCompany): SourceRef[] {
  const refs: SourceRef[] = [];
  if (merged.registry) refs.push({ provider: "registry", source_url: merged.registry.source_url });
  if (merged.listing) refs.push({ provider: "listing", source_url: merged.listing.source_url });
  if (merged.enrichment) refs.push({ provider: "enrichment", source_url: merged.enrichment.source_url });
  return refs;
}

/** The contact we WOULD emit, before suppression/threshold blanking. */
function selectContactValue(merged: MergedCompany): string {
  if (merged.phoneAgreement === "match") {
    return merged.listing?.phone ?? merged.enrichment?.phone ?? "";
  }
  if (merged.enrichment?.email) return merged.enrichment.email;
  if (merged.listing?.phone) return merged.listing.phone;
  if (merged.enrichment?.phone) return merged.enrichment.phone;
  return "";
}

export function buildRow(
  merged: MergedCompany,
  breakdown: ScoreBreakdown,
  suppression: SuppressionResult,
  candidate: string,
  cfg: ScoringConfig,
  includeBreakdown: boolean,
): ContactRow {
  const sources = buildSourceRefs(merged);

  let contact = "";
  let contactName = merged.chosenName;
  let contactRole = merged.chosenRole;
  let suppressed: ContactRow["suppressed"] = null;
  let needsReview = true;

  if (!merged.found) {
    contactName = "";
    contactRole = "";
  } else if (suppression.level === "company") {
    // Opt-out: surface the row but reveal nothing about the contact.
    suppressed = "company";
    contactName = "";
    contactRole = "";
  } else if (suppression.level === "contact") {
    // The specific channel opted out; keep the identity, blank the channel.
    suppressed = "contact";
  } else if (breakdown.total < cfg.threshold) {
    // Below threshold: keep what we found for the reviewer, but emit no contact.
  } else if (candidate !== "") {
    contact = candidate;
    needsReview = false;
  }
  // (If total >= threshold but no contact value exists, we fall through to review — invariant-safe.)

  const row: ContactRow = {
    company_name: merged.company_name,
    contact_name: contactName,
    contact_role: contactRole,
    contact_email_or_phone: contact,
    confidence_score: breakdown.total,
    source: sources,
    needs_human_review: needsReview,
    suppressed,
  };
  if (includeBreakdown) row.score_breakdown = breakdown;
  return row;
}

export function runContactFinder(opts: RunOptions = {}): RunResult {
  const cfg = opts.config ?? scoringConfig;
  const includeBreakdown = opts.includeBreakdown ?? true;
  const csvPath = opts.csvPath ?? DEFAULT_PATHS.csvPath;
  const fixturePath = opts.fixturePath ?? DEFAULT_PATHS.fixturePath;
  const suppressionPath = opts.suppressionPath ?? DEFAULT_PATHS.suppressionPath;

  const fixture = loadFixture(fixturePath);
  const suppressionList = loadSuppressionList(suppressionPath);
  const { rows: inputs, errors: parseErrors } = loadCompaniesCsv(csvPath);

  const rows: ContactRow[] = inputs.map((input: CompanyInput) => {
    const resp = lookupCompany(fixture, input.company_name);
    const merged = mergeCompany(input.company_name, resp, cfg);
    const breakdown = scoreCompany(merged, cfg);
    const candidate = selectContactValue(merged);
    const suppression: SuppressionResult = merged.found
      ? checkSuppression(merged, candidate, suppressionList)
      : { level: "none", matched: [] };
    return buildRow(merged, breakdown, suppression, candidate, cfg, includeBreakdown);
  });

  const { invalid } = validateRows(rows);

  const summary: RunSummary = {
    total: rows.length,
    emitted: rows.filter((r) => !r.needs_human_review).length,
    needs_review: rows.filter((r) => r.needs_human_review).length,
    not_found: rows.filter((r) => r.source.length === 0).length,
    suppressed: rows.filter((r) => r.suppressed !== null).length,
  };

  return { rows, parseErrors, validationErrors: invalid, summary };
}
