import { useMemo, useState } from "react";
import type { EChartsOption } from "echarts";
import type { Fleet, FleetBoat } from "../api/types";
import { Chart } from "../components/Chart";
import { DataTable } from "../components/DataTable";
import { decimal, integer } from "../lib/format";

type Metric = "trips" | "kilometers" | "hours";

function classMedian(boats: FleetBoat[], boat: FleetBoat): number {
  const values = boats.filter((candidate) => candidate.class === boat.class).map((candidate) => candidate.trips).sort((a, b) => a - b);
  if (!values.length) return 0;
  return values[Math.floor(values.length / 2)];
}

export function FleetScreen({ data }: { data: Fleet }) {
  const [search, setSearch] = useState("");
  const [boatClass, setBoatClass] = useState("all");
  const [metric, setMetric] = useState<Metric>("trips");
  const [underused, setUnderused] = useState(false);
  const [activeOnly, setActiveOnly] = useState(false);
  const classes = useMemo(() => [...new Set(data.boats.map((boat) => boat.class))].sort(), [data]);
  const boats = useMemo(() => data.boats
    .filter((boat) => !search || boat.name.toLocaleLowerCase("de").includes(search.toLocaleLowerCase("de")))
    .filter((boat) => boatClass === "all" || boat.class === boatClass)
    .filter((boat) => !activeOnly || boat.currentlyActive)
    .filter((boat) => !underused || boat.trips < classMedian(data.boats, boat))
    .sort((a, b) => b[metric] - a[metric]), [data, search, boatClass, metric, underused, activeOnly]);

  const scatterOption = useMemo<EChartsOption>(() => ({
    color: ["#0b5964"],
    tooltip: { renderMode: "richText", formatter: (value: unknown) => {
      const point = value as { data: [number, number, number, string] };
      return `<b>${point.data[3]}</b><br>${point.data[0]} Fahrten<br>${point.data[1]} km Ø<br>${point.data[2]} h`;
    } },
    grid: { left: 55, right: 25, top: 22, bottom: 48 },
    xAxis: { type: "value", name: "Fahrten", nameLocation: "middle", nameGap: 30 },
    yAxis: { type: "value", name: "Ø Kilometer" },
    series: [{ type: "scatter", symbolSize: (value: unknown) => 10 + Math.sqrt((value as number[])[2] || 0) * 2.2, data: boats.map((boat) => [boat.trips, boat.averageDistance || 0, boat.hours, boat.name]), emphasis: { focus: "self" } }],
  }), [boats]);

  const top = boats.slice(0, 18);
  const barOption = useMemo<EChartsOption>(() => ({
    color: ["#db6b45"], tooltip: { trigger: "axis", axisPointer: { type: "shadow" } },
    grid: { left: 120, right: 28, top: 10, bottom: 30 },
    xAxis: { type: "value" }, yAxis: { type: "category", inverse: true, data: top.map((boat) => boat.name) },
    series: [{ type: "bar", data: top.map((boat) => boat[metric]), barMaxWidth: 18, itemStyle: { borderRadius: [0, 5, 5, 0] } }],
  }), [top, metric]);

  return <>
    <section className="page-intro"><p className="eyebrow">Unsere Flotte</p><h1>Boote brauchen Wasser.</h1><p>Welche Boote tragen den Vereinsalltag — und welche könnten häufiger ihre Spur ziehen?</p></section>
    <section className="filter-panel" aria-label="Flotte filtern">
      <label>Boot suchen<input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name …" /></label>
      <label>Klasse<select value={boatClass} onChange={(event) => setBoatClass(event.target.value)}><option value="all">Alle Klassen</option>{classes.map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>Kennzahl<select value={metric} onChange={(event) => setMetric(event.target.value as Metric)}><option value="trips">Fahrten</option><option value="kilometers">Kilometer</option><option value="hours">Stunden</option></select></label>
      <div className="check-stack"><label className="check"><input type="checkbox" checked={activeOnly} onChange={(event) => setActiveOnly(event.target.checked)} /> Nur aktuell aktive</label><label className="check"><input type="checkbox" checked={underused} onChange={(event) => setUnderused(event.target.checked)} /> Unter Klassendurchschnitt</label></div>
    </section>
    <p className="result-count">{integer.format(boats.length)} Boote im Vergleich</p>
    <div className="chart-grid">
      <Chart kicker="Nutzungsprofil" title="Arbeitstiere und Spezialisten" summary="Häufigkeit, typische Distanz und gesamte Zeit auf dem Wasser." option={scatterOption} tall>
        <DataTable headers={["Boot", "Klasse", "Fahrten", "Ø km", "Stunden"]} rows={boats.map((boat) => [boat.name, boat.class, boat.trips, boat.averageDistance === null ? "–" : decimal.format(boat.averageDistance), decimal.format(boat.hours)])} />
      </Chart>
      <Chart kicker="Rangfolge" title={`Nach ${metric === "trips" ? "Fahrten" : metric === "kilometers" ? "Kilometern" : "Stunden"}`} summary="Die ersten 18 Boote im aktuellen Filter." option={barOption} tall>
        <DataTable headers={["Boot", "Fahrten", "Kilometer", "Veränderung"]} rows={top.map((boat) => [boat.name, boat.trips, decimal.format(boat.kilometers), boat.changePercent === null ? "neu" : `${decimal.format(boat.changePercent)} %`])} />
      </Chart>
    </div>
    <article className="fleet-table-card"><div className="chart-heading"><div><p className="eyebrow">Detail</p><h2>Flotte im Überblick</h2></div><p>„Unter Durchschnitt“ vergleicht nur Boote derselben Klasse.</p></div>
      <DataTable headers={["Boot", "Klasse", "Fahrten", "km", "h", "Aktive Tage", "vs. vorher"]} rows={boats.map((boat) => [<strong>{boat.name}</strong>, boat.class, integer.format(boat.trips), decimal.format(boat.kilometers), decimal.format(boat.hours), boat.activeDays, boat.changePercent === null ? "–" : <span className={boat.changePercent >= 0 ? "up" : "down"}>{boat.changePercent >= 0 ? "+" : ""}{decimal.format(boat.changePercent)} %</span>])} />
    </article>
  </>;
}
