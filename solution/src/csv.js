/**
 * csv.js
 *
 * Lightweight CSV parser for companies.csv (no external dependencies).
 * Handles quoted fields with commas inside (e.g. mailing addresses).
 */

import { readFileSync } from "node:fs";

/**
 * Parse a CSV file into an array of objects keyed by header row.
 * @param {string} filePath – absolute path to the CSV
 * @returns {object[]}
 */
export function parseCSV(filePath) {
  const raw = readFileSync(filePath, "utf-8").trim();
  const lines = raw.split("\n");
  const headers = splitCSVLine(lines[0]);

  return lines.slice(1).filter(Boolean).map((line) => {
    const values = splitCSVLine(line);
    const row = {};
    headers.forEach((h, i) => {
      row[h.trim()] = (values[i] || "").trim();
    });
    return row;
  });
}

/**
 * Split a single CSV line respecting quoted fields.
 */
function splitCSVLine(line) {
  const result = [];
  let current = "";
  let inQuotes = false;

  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (ch === '"') {
      inQuotes = !inQuotes;
    } else if (ch === "," && !inQuotes) {
      result.push(current);
      current = "";
    } else {
      current += ch;
    }
  }
  result.push(current);
  return result;
}
