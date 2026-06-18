/**
 * Zod validation for output rows. This is the last gate before a contact leaves the
 * system, and it encodes the two invariants the challenge cares about:
 *   (a) an emitted contact MUST carry provenance (never an unattributed value);
 *   (b) an empty contact and `needs_human_review` are two sides of the same coin —
 *       not-found, suppressed, and below-threshold all converge on (contact="" , review=true).
 */

import { z } from "zod";
import type { ContactRow } from "../types/index.js";

// A contact is either empty, a valid email, or a phone-shaped string.
const phoneSchema = z.string().regex(/^\+?[0-9().\-\s]{7,20}$/, "not a phone-shaped string");
const contactSchema = z.union([z.literal(""), z.string().email(), phoneSchema]);

const sourceRefSchema = z.object({
  provider: z.enum(["registry", "listing", "enrichment"]),
  source_url: z.string().min(1),
});

const scoreBreakdownSchema = z.object({
  role: z.number(),
  phone: z.number(),
  email: z.number(),
  relationshipBonus: z.number(),
  total: z.number(),
  roleTier: z.string().nullable(),
  notes: z.array(z.string()),
});

export const contactRowSchema = z
  .object({
    company_name: z.string().min(1),
    contact_name: z.string(),
    contact_role: z.string(),
    contact_email_or_phone: contactSchema,
    confidence_score: z.number().int().min(0).max(100),
    source: z.array(sourceRefSchema),
    needs_human_review: z.boolean(),
    suppressed: z.union([z.literal("company"), z.literal("contact"), z.null()]),
    score_breakdown: scoreBreakdownSchema.optional(),
  })
  .superRefine((row, ctx) => {
    const hasContact = row.contact_email_or_phone !== "";
    if (hasContact && row.source.length === 0) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ["source"],
        message: "Emitted contact must have at least one source (provenance).",
      });
    }
    if (hasContact && row.needs_human_review) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ["needs_human_review"],
        message: "A row with an emitted contact must not be flagged for review.",
      });
    }
    if (!hasContact && !row.needs_human_review) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ["needs_human_review"],
        message: "A row with no contact must be flagged for human review.",
      });
    }
  });

export type ValidationFailure = { row: unknown; errors: z.ZodIssue[] };

export function validateRow(
  row: unknown,
): { ok: true; row: ContactRow } | { ok: false; errors: z.ZodIssue[] } {
  const result = contactRowSchema.safeParse(row);
  if (result.success) return { ok: true, row: result.data as ContactRow };
  return { ok: false, errors: result.error.issues };
}

export function validateRows(rows: unknown[]): {
  valid: ContactRow[];
  invalid: ValidationFailure[];
} {
  const valid: ContactRow[] = [];
  const invalid: ValidationFailure[] = [];
  for (const row of rows) {
    const res = validateRow(row);
    if (res.ok) valid.push(res.row);
    else invalid.push({ row, errors: res.errors });
  }
  return { valid, invalid };
}
