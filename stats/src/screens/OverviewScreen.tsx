import { useMemo } from "react";
import type { EChartsOption } from "echarts";
import { Chart } from "../components/Chart";
import { DataTable } from "../components/DataTable";
import { MetricCards, metricItems } from "../components/MetricCards";
import { dateLabel, decimal, integer } from "../lib/format";
import type { Overview } from "../api/types";

const colors = ["#0b5964", "#db6b45", "#d2a840", "#5e8275", "#9b5b68", "#6885a6"];

export function OverviewScreen({ data }: { data: Overview }) {
  const annualOption = useMemo<EChartsOption>(() => ({
    color: colors,
    tooltip: { trigger: "axis" },
    legend: { top: 0, textStyle: { color: "#52616a" } },
    grid: { left: 52, right: 18, top: 52, bottom: 38 },
    xAxis: { type: "category", data: data.annual.map((row) => row.year), axisLine: { lineStyle: { color: "#aab8b6" } } },
    yAxis: [{ type: "value", name: "Fahrten" }, { type: "value", name: "km" }],
    series: [
      { name: "Fahrten", type: "line", smooth: true, symbolSize: 7, data: data.annual.map((row) => row.trips), lineStyle: { width: 3 }, areaStyle: { opacity: .08 } },
      { name: "Kilometer", type: "line", smooth: true, yAxisIndex: 1, symbol: "none", data: data.annual.map((row) => row.kilometers), lineStyle: { width: 2 } },
    ],
  }), [data]);

  const monthOption = useMemo<EChartsOption>(() => {
    const years = [...new Set(data.months.map((row) => row.year))];
    return {
      tooltip: { formatter: (value: unknown) => {
        const point = value as { value: [number, number, number] };
        return `${years[point.value[1]]} · ${["Jan","Feb","Mär","Apr","Mai","Jun","Jul","Aug","Sep","Okt","Nov","Dez"][point.value[0]]}<br><b>${point.value[2]} Fahrten</b>`;
      } },
      grid: { left: 58, right: 22, top: 16, bottom: 54 },
      xAxis: { type: "category", data: ["Jan","Feb","Mär","Apr","Mai","Jun","Jul","Aug","Sep","Okt","Nov","Dez"], splitArea: { show: true } },
      yAxis: { type: "category", data: years, splitArea: { show: true } },
      visualMap: { show: false, min: 0, max: Math.max(1, ...data.months.map((row) => row.trips)), inRange: { color: ["#edf1eb", "#8db3ac", "#0b5964"] } },
      series: [{ type: "heatmap", data: data.months.map((row) => [row.month - 1, years.indexOf(row.year), row.trips]), emphasis: { itemStyle: { shadowBlur: 8 } } }],
    };
  }, [data]);

  const hourOption = useMemo<EChartsOption>(() => ({
    tooltip: { position: "top" },
    grid: { left: 58, right: 20, top: 14, bottom: 54 },
    xAxis: { type: "category", data: Array.from({ length: 24 }, (_, hour) => `${hour}`), name: "Uhr" },
    yAxis: { type: "category", data: ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"] },
    visualMap: { min: 0, max: Math.max(1, ...data.weekdayHours.map((row) => row.trips)), orient: "horizontal", left: "center", bottom: 0, inRange: { color: ["#f5efe4", "#e4ad67", "#a3463e"] } },
    series: [{ type: "heatmap", data: data.weekdayHours.map((row) => [row.hour, row.weekday, row.trips]) }],
  }), [data]);

  const classesOption = useMemo<EChartsOption>(() => ({
    color: [colors[1]], tooltip: { trigger: "axis", axisPointer: { type: "shadow" } },
    grid: { left: 118, right: 22, top: 10, bottom: 30 },
    xAxis: { type: "value" }, yAxis: { type: "category", inverse: true, data: data.boatClasses.map((row) => row.label) },
    series: [{ type: "bar", data: data.boatClasses.map((row) => row.trips), barMaxWidth: 22, itemStyle: { borderRadius: [0, 5, 5, 0] } }],
  }), [data]);

  const paceOption = useMemo<EChartsOption>(() => ({
    color: [colors[1], colors[0], colors[3]],
    tooltip: { trigger: "axis" },
    legend: { top: 0 },
    grid: { left: 58, right: 22, top: 52, bottom: 36 },
    xAxis: { type: "category", data: data.seasonPace.points.map((point) => point.day), axisLabel: { interval: (_index: number, value: string) => value.endsWith("-01"), formatter: (value: string) => value.slice(0, 2) } },
    yAxis: { type: "value", name: "km" },
    series: [
      { name: `${data.seasonPace.currentYear}`, type: "line", showSymbol: false, data: data.seasonPace.points.map((point) => point.current), lineStyle: { width: 4 } },
      { name: `${data.seasonPace.currentYear - 1}`, type: "line", showSymbol: false, data: data.seasonPace.points.map((point) => point.previous), lineStyle: { width: 2 } },
      { name: "5-Jahres-Median", type: "line", showSymbol: false, data: data.seasonPace.points.map((point) => point.median), lineStyle: { type: "dashed", width: 2 } },
    ],
  }), [data]);

  const busy = data.facts.busiestDay;
  return <>
    <section className="hero">
      <div><p className="eyebrow">Unsere Zeit auf dem Wasser</p><h1>Das sind wir.</h1>
        <p className="lede">Jede Ausfahrt ist ein kleiner Eintrag. Zusammen erzählen sie, wie unser Verein lebt.</p></div>
      <div className="hero-stamp"><span>{data.annual.length}</span>Jahre im Blick</div>
    </section>
    <MetricCards items={[...metricItems(data.headline), { label: "Aktive Boote", value: integer.format(data.headline.activeBoats) }, { label: "Beteiligte", value: integer.format(data.headline.participants) }]} />
    <section className="fact-strip" aria-label="Besondere Zahlen">
      <div><span>Lebhaftester Tag</span><strong>{busy ? dateLabel(busy.date) : "–"}</strong><small>{busy ? `${busy.trips} Fahrten` : "Keine Daten"}</small></div>
      <div><span>Typische Strecke</span><strong>{data.facts.medianDistance === null ? "–" : `${decimal.format(data.facts.medianDistance)} km`}</strong><small>Median aller Fahrten mit Distanz</small></div>
      <div><span>Längster Eintrag</span><strong>{data.facts.longestDistance === null ? "–" : `${decimal.format(data.facts.longestDistance)} km`}</strong><small>laut Fahrtenbuch</small></div>
    </section>
    <Chart kicker="Langzeit" title="Die große Kielwelle" summary="Fahrten und Kilometer pro Jahr machen Wachstum, Pausen und neue Rhythmen sichtbar." option={annualOption}>
      <DataTable headers={["Jahr", "Fahrten", "Kilometer", "Stunden"]} rows={data.annual.map((row) => [row.year, integer.format(row.trips), decimal.format(row.kilometers), decimal.format(row.hours)])} />
    </Chart>
    <Chart kicker="Aktuelle Saison" title="Wie wir dieses Jahr liegen" summary="Kumulierte Kilometer bis zum gleichen Kalendertag — gegen Vorjahr und den Median der fünf vorherigen Jahre." option={paceOption}>
      <DataTable headers={["Tag", `${data.seasonPace.currentYear}`, `${data.seasonPace.currentYear - 1}`, "5-Jahres-Median"]} rows={data.seasonPace.points.filter((_, index) => index % 14 === 0 || index === data.seasonPace.points.length - 1).map((point) => [point.day, decimal.format(point.current), decimal.format(point.previous), decimal.format(point.median)])} />
    </Chart>
    <div className="chart-grid">
      <Chart kicker="Saison" title="Zwanzig Sommer und Winter" summary="Jede Zelle zeigt die Anzahl der Fahrten in einem Monat." option={monthOption} tall>
        <DataTable headers={["Jahr", "Monat", "Fahrten"]} rows={data.months.filter((row) => row.trips > 0).map((row) => [row.year, row.month, row.trips])} />
      </Chart>
      <Chart kicker="Tagesrhythmus" title="Wann wir rudern" summary="Startzeiten nach Wochentag und Stunde, über den gewählten Zeitraum." option={hourOption} tall>
        <DataTable headers={["Wochentag", "Stunde", "Fahrten"]} rows={data.weekdayHours.filter((row) => row.trips > 0).map((row) => [["Mo","Di","Mi","Do","Fr","Sa","So"][row.weekday], `${row.hour}:00`, row.trips])} />
      </Chart>
    </div>
    <div className="chart-grid">
      <Chart kicker="Bootsgefühl" title="Womit wir unterwegs sind" summary="Fahrten nach Boots- und Riggerklasse." option={classesOption}>
        <DataTable headers={["Klasse", "Fahrten", "km"]} rows={data.boatClasses.map((row) => [row.label, row.trips, decimal.format(row.kilometers)])} />
      </Chart>
      <article className="chart-card destinations"><div className="chart-heading"><div><p className="eyebrow">Reviere</p><h2>Wohin es uns zieht</h2></div><p>Die häufigsten Ziele im gewählten Zeitraum.</p></div>
        <ol>{data.destinations.map((row, index) => <li key={row.label}><span>{String(index + 1).padStart(2, "0")}</span><div><strong>{row.label}</strong><small>{integer.format(row.trips)} Fahrten · {decimal.format(row.kilometers)} km</small></div></li>)}</ol>
      </article>
    </div>
    <p className="coverage">Datengrundlage: Distanz bei {decimal.format(data.coverage.distancePercent)} % und Fahrtdauer bei {decimal.format(data.coverage.durationPercent)} % der berücksichtigten Fahrten verfügbar.</p>
  </>;
}
