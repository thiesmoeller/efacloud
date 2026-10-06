import { describe, expect, it } from "vitest";
import { buildSeatFilterChips, isAllSeatFilter, SEAT_FILTER_ALL } from "./seatFilters";

describe("buildSeatFilterChips", () => {
  it("prepends Alle and preserves API order", () => {
    const chips = buildSeatFilterChips([
      { code: "1", label: "Einer" },
      { code: "2", label: "Zweier" },
      { code: "4", label: "Vierer" },
    ]);
    expect(chips[0]).toEqual(SEAT_FILTER_ALL);
    expect(chips.map((c) => c.code)).toEqual(["ALL", "1", "2", "4"]);
  });

  it("works with empty fleet categories", () => {
    expect(buildSeatFilterChips([])).toEqual([SEAT_FILTER_ALL]);
  });
});

describe("isAllSeatFilter", () => {
  it("treats empty and ALL as alle", () => {
    expect(isAllSeatFilter("ALL")).toBe(true);
    expect(isAllSeatFilter("")).toBe(true);
    expect(isAllSeatFilter(null)).toBe(true);
    expect(isAllSeatFilter("1")).toBe(false);
  });
});
