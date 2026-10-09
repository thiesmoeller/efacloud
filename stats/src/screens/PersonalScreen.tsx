import { useMemo } from "react";
import type { EChartsOption } from "echarts";
import type { Personal } from "../api/types";
import { Chart } from "../components/Chart";
import { DataTable } from "../components/DataTable";
import { MetricCards, metricItems } from "../components/MetricCards";
import { decimal } from "../lib/format";

export function PersonalScreen({ data, firstName }: { data: Personal; firstName: string }) {
  if (!data.available || !data.headline) return <section className="empty-state"><p className="eyebrow">Meine Statistik</p><h1>Noch keine persönliche Spur.</h1><p>{data.reason || "Dein efaCloud-Konto ist noch nicht mit einem Fahrtenbuch-Eintrag verknüpft."}</p></section>;
  const annual = data.annual || [];
  const boats = data.boats || [];
  const annualOption = useMemo<EChartsOption>(() => ({
    color: ["#0b5964", "#db6b45"], tooltip: { trigger: "axis" }, legend: { top: 0 },
    grid: { left: 52, right: 22, top: 50, bottom: 35 }, xAxis: { type: "category", data: annual.map((row) => row.year) },
    yAxis: [{ type: "value", name: "Fahrten" }, { type: "value", name: "km" }],
    series: [{ name: "Fahrten", type: "bar", data: annual.map((row) => row.trips), itemStyle: { borderRadius: [5, 5, 0, 0] } }, { name: "Kilometer", type: "line", smooth: true, yAxisIndex: 1, data: annual.map((row) => row.kilometers), lineStyle: { width: 3 } }],
  }), [annual]);
  const boatsOption = useMemo<EChartsOption>(() => ({
    color: ["#d2a840"], grid: { left: 110, right: 22, top: 10, bottom: 30 },
    xAxis: { type: "value" }, yAxis: { type: "category", inverse: true, data: boats.map((row) => row.label) },
    series: [{ type: "bar", data: boats.map((row) => row.trips), barMaxWidth: 22, itemStyle: { borderRadius: [0, 5, 5, 0] } }],
  }), [boats]);
  return <>
    <section className="page-intro personal-intro"><p className="eyebrow">Meine Statistik</p><h1>{firstName ? `${firstName}s Kielwasser.` : "Mein Kielwasser."}</h1><p>Nur du siehst diese Zahlen. Jede Fahrt wird vollständig gutgeschrieben, wenn du als Crew oder Steuerperson eingetragen bist.</p></section>
    <MetricCards items={[...metricItems(data.headline), { label: "Mitgerudert", value: data.headline.rowedTrips }, { label: "Gesteuert", value: data.headline.coxTrips }]} />
    <div className="chart-grid">
      <Chart kicker="Meine Jahre" title="Was sich angesammelt hat" summary="Persönliche Fahrten und Kilometer im Zeitverlauf." option={annualOption}>
        <DataTable headers={["Jahr", "Fahrten", "Kilometer", "Stunden"]} rows={annual.map((row) => [row.year, row.trips, decimal.format(row.kilometers), decimal.format(row.hours)])} />
      </Chart>
      <Chart kicker="Lieblingsboote" title="Darauf war ich unterwegs" summary="Boote mit den meisten persönlichen Fahrten." option={boatsOption}>
        <DataTable headers={["Boot", "Fahrten", "Kilometer"]} rows={boats.map((row) => [row.label, row.trips, decimal.format(row.kilometers)])} />
      </Chart>
    </div>
    <article className="chart-card destinations"><div className="chart-heading"><div><p className="eyebrow">Meine Ziele</p><h2>Oft angesteuert</h2></div></div><ol>{(data.destinations || []).map((row, index) => <li key={row.label}><span>{String(index + 1).padStart(2, "0")}</span><div><strong>{row.label}</strong><small>{row.trips} Fahrten · {decimal.format(row.kilometers)} km</small></div></li>)}</ol></article>
    {data.coverage && <p className="coverage">Persönliche Distanzdaten sind für {decimal.format(data.coverage.distancePercent)} % der Fahrten verfügbar.</p>}
  </>;
}
