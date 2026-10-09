export const integer = new Intl.NumberFormat("de-DE", { maximumFractionDigits: 0 });
export const decimal = new Intl.NumberFormat("de-DE", { maximumFractionDigits: 1 });

export function dateLabel(value: string): string {
  return new Intl.DateTimeFormat("de-DE", { day: "2-digit", month: "long", year: "numeric" }).format(new Date(`${value}T12:00:00`));
}

export function yearRange(years: number[], preset: "all" | "five" | "current"): { from: string; to: string } {
  const latest = Math.max(...years);
  const earliest = Math.min(...years);
  const fromYear = preset === "all" ? earliest : preset === "five" ? Math.max(earliest, latest - 4) : latest;
  return { from: `${fromYear}-01-01`, to: `${latest}-12-31` };
}
