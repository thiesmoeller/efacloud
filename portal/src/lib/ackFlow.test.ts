import { describe, expect, it } from "vitest";
import type { AckCheck } from "../api/types";
import { clearTokensForStale, nextAckDialog } from "./ackFlow";

const checks: AckCheck[] = [
  {
    kind: "status_notavailable",
    title: "Boot gesperrt",
    message: "Möchtest Du trotzdem das Boot benutzen?",
    snapshotHash: "h1",
  },
  {
    kind: "reservation",
    title: "Boot reserviert",
    message: "Möchtest Du trotzdem das Boot benutzen?",
    snapshotHash: "h2",
  },
  {
    kind: "damage",
    title: "Bootsschaden gemeldet",
    message: "Boot eingeschränkt benutzbar — Riss im Bug.",
    snapshotHash: "h3",
  },
];

describe("nextAckDialog", () => {
  it("returns null when nothing required", () => {
    expect(nextAckDialog(checks, [])).toBeNull();
  });

  it("walks EFA order for required kinds only", () => {
    const first = nextAckDialog(checks, ["reservation", "damage"]);
    expect(first?.check.kind).toBe("reservation");
    expect(first?.remaining.map((c) => c.kind)).toEqual(["damage"]);
  });

  it("includes stale kinds", () => {
    const first = nextAckDialog(checks, [], ["damage"]);
    expect(first?.check.kind).toBe("damage");
  });

  it("falls back to all checks when required list omitted", () => {
    const first = nextAckDialog(checks, undefined);
    expect(first?.check.kind).toBe("status_notavailable");
    expect(first?.remaining).toHaveLength(2);
  });
});

describe("clearTokensForStale", () => {
  it("clears all tokens so dialogs renew", () => {
    expect(clearTokensForStale(["a", "b"], ["damage"])).toEqual([]);
  });
});
