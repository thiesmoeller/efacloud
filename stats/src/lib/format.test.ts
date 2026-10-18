import { describe, expect, it } from "vitest";
import { escapeHtml, yearRange } from "./format";

describe("escapeHtml", () => {
  it("neutralises markup in names shown in HTML tooltips", () => {
    expect(escapeHtml(`<img src=x onerror="a">& 'B'`)).toBe("&lt;img src=x onerror=&quot;a&quot;&gt;&amp; &#39;B&#39;");
  });
});

describe("yearRange", () => {
  it("builds complete and five-year ranges", () => {
    expect(yearRange([2006, 2026], "all")).toEqual({ from: "2006-01-01", to: "2026-12-31" });
    expect(yearRange([2006, 2026], "five")).toEqual({ from: "2022-01-01", to: "2026-12-31" });
  });
});
