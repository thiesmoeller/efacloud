import * as echarts from "echarts/core";
import { BarChart, HeatmapChart, LineChart, ScatterChart } from "echarts/charts";
import { GridComponent, LegendComponent, TooltipComponent, VisualMapComponent } from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";
import { useEffect, useRef, type ReactNode } from "react";
import type { EChartsOption } from "echarts";

echarts.use([
  BarChart,
  HeatmapChart,
  LineChart,
  ScatterChart,
  GridComponent,
  LegendComponent,
  TooltipComponent,
  VisualMapComponent,
  CanvasRenderer,
]);

type Props = {
  title: string;
  kicker?: string;
  summary: string;
  option: EChartsOption;
  children?: ReactNode;
  tall?: boolean;
};

export function Chart({ title, kicker, summary, option, children, tall = false }: Props) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!ref.current) return;
    const chart = echarts.init(ref.current, undefined, { renderer: "canvas" });
    chart.setOption(option);
    const observer = new ResizeObserver(() => chart.resize());
    observer.observe(ref.current);
    return () => {
      observer.disconnect();
      chart.dispose();
    };
  }, [option]);

  return (
    <article className="chart-card">
      <header className="chart-heading">
        <div>{kicker && <p className="eyebrow">{kicker}</p>}<h2>{title}</h2></div>
        <p>{summary}</p>
      </header>
      <div ref={ref} className={`chart ${tall ? "chart-tall" : ""}`} role="img" aria-label={`${title}. ${summary}`} />
      {children && <details className="data-table"><summary>Daten als Tabelle</summary>{children}</details>}
    </article>
  );
}
