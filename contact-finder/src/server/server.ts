/**
 * Thin HTTP wrapper. One endpoint, hardcoded to the challenge test CSV for now
 * (file upload is a deliberate later step). GET /contacts returns the run result as
 * JSON; add ?debug=1 to include the per-row score_breakdown.
 */

import { pathToFileURL } from "node:url";
import express, { type Express, type Request, type Response } from "express";
import { runContactFinder } from "../pipeline/controller.js";

export function createApp(): Express {
  const app = express();

  app.get("/health", (_req: Request, res: Response) => {
    res.json({ status: "ok" });
  });

  app.get("/contacts", (req: Request, res: Response) => {
    const includeBreakdown = req.query.debug === "1" || req.query.debug === "true";
    const result = runContactFinder({ includeBreakdown });
    res.json({
      summary: result.summary,
      rows: result.rows,
      parse_errors: result.parseErrors,
      validation_errors: result.validationErrors,
    });
  });

  return app;
}

export function startServer(port = Number(process.env.PORT) || 3000): void {
  const app = createApp();
  app.listen(port, () => {
    console.log(`Contact Finder listening on http://localhost:${port}`);
    console.log(`  GET /contacts        -> run the pipeline over the test CSV`);
    console.log(`  GET /contacts?debug=1 -> include score breakdowns`);
  });
}

// Boot only when run directly (npm run serve) — importing createApp for tests must not listen.
const invokedDirectly =
  process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href;
if (invokedDirectly) startServer();
