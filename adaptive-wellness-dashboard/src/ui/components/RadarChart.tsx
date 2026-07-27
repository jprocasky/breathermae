import { MetricModel } from "../../domain/types";

export function RadarChart({ metrics }: { metrics: MetricModel[] }) {
  const size = 300;
  const center = size / 2;
  const radius = 105;

  const point = (value: number, index: number) => {
    const angle = -Math.PI / 2 + index * (2 * Math.PI / metrics.length);
    const r = radius * value / 100;
    return [center + Math.cos(angle) * r, center + Math.sin(angle) * r];
  };

  const current = metrics.map((m, i) => point(m.currentScore, i).join(",")).join(" ");
  const baseline = metrics.map((m, i) => point(m.trend.baseline ?? 0, i).join(",")).join(" ");

  return (
    <svg className="radar" viewBox={`0 0 ${size} ${size}`} role="img" aria-label="Wellness balance wheel comparing baseline and current values">
      {[0.25, 0.5, 0.75, 1].map((factor) => {
        const ring = metrics.map((_, i) => point(factor * 100, i).join(",")).join(" ");
        return <polygon key={factor} points={ring} fill="none" className="radar-grid" />;
      })}
      {metrics.map((m, i) => {
        const [x, y] = point(100, i);
        return (
          <g key={m.definitionId}>
            <line x1={center} y1={center} x2={x} y2={y} className="radar-grid" />
            <text x={x} y={y} className="radar-label" textAnchor={x < center ? "end" : x > center ? "start" : "middle"}>{m.label.split(" ")[0]}</text>
          </g>
        );
      })}
      <polygon points={baseline} className="radar-baseline" />
      <polygon points={current} className="radar-current" />
    </svg>
  );
}
