/**
 * providers.js
 *
 * Loads the canned mock-provider data from enrichment_responses.json
 * and exposes a per-company lookup that returns { registry, listing, enrichment }
 * (any key may be missing – that is a "not found" from that source).
 */

import { readFileSync } from "node:fs";
import { resolve, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));

const MOCK_PATH = resolve(
  __dirname,
  "../../challenge/mocks/enrichment_responses.json"
);

let _cache = null;

/** Load once, cache in memory */
function loadMocks() {
  if (!_cache) {
    _cache = JSON.parse(readFileSync(MOCK_PATH, "utf-8"));
  }
  return _cache;
}

/**
 * Query all three mock providers for a given company.
 *
 * @param {string} companyName – exact key from companies.csv
 * @returns {{ registry?: object, listing?: object, enrichment?: object }}
 *          Missing keys mean the provider returned nothing.
 */
export function queryProviders(companyName) {
  const mocks = loadMocks();
  return mocks[companyName] ?? {};
}
