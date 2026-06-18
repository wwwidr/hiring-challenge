/**
 * Suppression / opt-out enforcement. This is a COMPLIANCE gate, not a UI convenience:
 * a suppressed contact must never be emitted, regardless of how confident we are.
 * It runs before the threshold check in the controller.
 *
 *   - company-wide match  -> surface the row, blank the contact (audit trail; not dropped)
 *   - contact (email/phone) match -> blank just that contact value
 *
 * Matching is normalization-insensitive: emails compared case-folded, phones by digits.
 */

import { existsSync, readFileSync } from "node:fs";
import type { MergedCompany, SuppressionList } from "../types/index.js";
import { normalizePhone } from "./normalize.js";

export type SuppressionLevelResult = "company" | "contact" | "none";

export interface SuppressionResult {
  level: SuppressionLevelResult;
  /** The list entries that matched, for logging/audit. */
  matched: string[];
}

/** Lenient loader: a missing file means "no suppressions", never an error. */
export function loadSuppressionList(absPath: string): SuppressionList {
  if (!existsSync(absPath)) return { companies: [], emails: [], phones: [] };
  const raw = JSON.parse(readFileSync(absPath, "utf8")) as Partial<SuppressionList>;
  return {
    companies: raw.companies ?? [],
    emails: raw.emails ?? [],
    phones: raw.phones ?? [],
  };
}

/**
 * @param contactValue the contact we WOULD emit for this company (email or phone), or "".
 */
export function checkSuppression(
  merged: MergedCompany,
  contactValue: string,
  list: SuppressionList,
): SuppressionResult {
  const companyKey = merged.company_name.trim().toLowerCase();
  const companyHit = list.companies.find((c) => c.trim().toLowerCase() === companyKey);
  if (companyHit) return { level: "company", matched: [companyHit] };

  const matched: string[] = [];
  const value = contactValue.trim();
  if (value) {
    const emailKey = value.toLowerCase();
    const emailHit = list.emails.find((e) => e.trim().toLowerCase() === emailKey);
    if (emailHit) matched.push(emailHit);

    const phoneKey = normalizePhone(value);
    if (phoneKey) {
      const phoneHit = list.phones.find((p) => normalizePhone(p) === phoneKey);
      if (phoneHit) matched.push(phoneHit);
    }
  }

  return matched.length > 0 ? { level: "contact", matched } : { level: "none", matched: [] };
}
