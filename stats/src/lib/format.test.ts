import { describe, expect, it } from "vitest";
import { yearRange } from "./format";

describe("yearRange", () => {
  it("builds complete and five-year ranges", () => {
    expect(yearRange([2006, 2026], "all")).toEqual({ from: "2006-01-01", to: "2026-12-31" });
    expect(yearRange([2006, 2026], "five")).toEqual({ from: "2022-01-01", to: "2026-12-31" });
  });
});
