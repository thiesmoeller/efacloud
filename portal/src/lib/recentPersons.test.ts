import { describe, expect, it } from "vitest";
import type { Person } from "../api/types";
import { rankPersonsByRecent } from "./recentPersons";

const person = (id: string): Person => ({ id, firstName: id, lastName: "", displayName: id });

describe("recent person ranking", () => {
  it("puts matching people in most-recently-used order and preserves server order for the rest", () => {
    expect(rankPersonsByRecent([person("a"), person("b"), person("c"), person("d")], ["c", "a"]).map(p => p.id))
      .toEqual(["c", "a", "b", "d"]);
  });
});
