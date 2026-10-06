import type { SeatCategory } from "../api/types";

/** Always-first chip; API only returns categories present in the fleet. */
export const SEAT_FILTER_ALL = { code: "ALL", label: "Alle" } as const;

/**
 * Build seat filter chips: Alle + categories from API (already fleet-present).
 * Preserves API order (server sorts naturally by seat code).
 */
export function buildSeatFilterChips(seatCategories: SeatCategory[]): Array<{
  code: string;
  label: string;
}> {
  return [SEAT_FILTER_ALL, ...seatCategories];
}

export function isAllSeatFilter(code: string | undefined | null): boolean {
  return !code || code === "ALL";
}
