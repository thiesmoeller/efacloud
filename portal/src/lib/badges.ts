/** Human-readable badge labels for rigging / coxing / hull (German dockside). */

const RIGGING: Record<string, string> = {
  SCULL: "Skull",
  SWEEP: "Riemen",
};

const COXING: Record<string, string> = {
  COXED: "mit Stm.",
  COXLESS: "ohne Stm.",
};

const HULL: Record<string, string> = {
  RACING: "Rennboot",
  GIG: "Gig",
  WHERRY: "Wherry",
  TRIMMEDWHERRY: "Trimmed Wherry",
  YOL: "Yole",
  BARQUE: "Barkasse",
  MOTOR: "Motor",
  OTHER: "Sonstiges",
};

export function badgeLabel(
  kind: "rigging" | "coxing" | "hullType",
  raw: string
): string | null {
  if (!raw) return null;
  const key = raw.toUpperCase();
  if (kind === "rigging") return RIGGING[key] ?? raw;
  if (kind === "coxing") return COXING[key] ?? raw;
  return HULL[key] ?? raw;
}

export function isCoxed(typeCoxing: string): boolean {
  return typeCoxing.toUpperCase() === "COXED";
}

export function seatCountFromCategory(seatCategory: string): number {
  const n = parseInt(seatCategory, 10);
  return Number.isFinite(n) ? n : 1;
}
