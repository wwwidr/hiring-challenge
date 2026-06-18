/**
 * The mock data source. In production this would be three independent provider
 * clients; for the slice they are one canned fixture keyed by exact company_name.
 * A missing key is a genuine "not found" — the most common real-world outcome.
 */

import { readFileSync } from "node:fs";
import type { FixtureMap, ProviderResponse } from "../types/index.js";

export function loadFixture(absPath: string): FixtureMap {
  const text = readFileSync(absPath, "utf8");
  return JSON.parse(text) as FixtureMap;
}

/** Returns null when the company has no entry in any source. */
export function lookupCompany(fixture: FixtureMap, companyName: string): ProviderResponse | null {
  return fixture[companyName] ?? null;
}
