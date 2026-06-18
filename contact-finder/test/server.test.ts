import { describe, it, expect } from "vitest";
import request from "supertest";
import { createApp } from "../src/server/server.js";

const app = createApp();

describe("HTTP endpoints", () => {
  it("GET /contacts returns the full run result", async () => {
    const res = await request(app).get("/contacts");
    expect(res.status).toBe(200);
    expect(res.body.summary.total).toBe(30);
    expect(res.body.rows).toHaveLength(30);
    expect(res.body.validation_errors).toHaveLength(0);
  });

  it("omits score_breakdown by default and includes it with ?debug=1", async () => {
    const plain = await request(app).get("/contacts");
    expect(plain.body.rows[0]).not.toHaveProperty("score_breakdown");

    const debug = await request(app).get("/contacts?debug=1");
    expect(debug.body.rows[0]).toHaveProperty("score_breakdown");
  });
});
