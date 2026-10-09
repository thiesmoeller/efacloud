import { decimal, integer } from "../lib/format";

type Item = { label: string; value: number | string; unit?: string; note?: string };

export function MetricCards({ items }: { items: Item[] }) {
  return <div className="metric-grid">{items.map((item) => (
    <article className="metric" key={item.label}>
      <p>{item.label}</p>
      <strong>{typeof item.value === "number" ? decimal.format(item.value) : item.value}<span>{item.unit}</span></strong>
      {item.note && <small>{item.note}</small>}
    </article>
  ))}</div>;
}

export function metricItems(value: { trips: number; kilometers: number; hours: number }) {
  return [
    { label: "Fahrten", value: integer.format(value.trips) },
    { label: "Kilometer", value: decimal.format(value.kilometers), unit: " km" },
    { label: "Auf dem Wasser", value: decimal.format(value.hours), unit: " h" },
  ];
}
