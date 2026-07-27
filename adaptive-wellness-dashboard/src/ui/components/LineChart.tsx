import { MetricModel } from "../../domain/types";
import { PILLARS } from "../../config/wellnessConfig";

export function LineChart({ metrics, compact = false }: { metrics: MetricModel[]; compact?: boolean }) {
  const width = 900;
  const height = compact ? 220 : 420;
  const pad = { left: 54, right: 24, top: 24, bottom: 42 };
  const count = Math.max(...metrics.map((m) => m.historical.length));
  const x = (i: number) => pad.left + (i / Math.max(1, count - 1)) * (width - pad.left - pad.right);
  const y = (score: number) => height - pad.bottom - (score / 100) * (height - pad.top - pad.bottom);

  return (
    <svg className="line-chart" viewBox={`0 0 ${width} ${height}`} role="img" aria-label="Historical wellness trend chart">
      {[0, 20, 40, 60, 80, 100].map((tick) => (
        <g key={tick}>
          <line x1={pad.left} x2={width - pad.right} y1={y(tick)} y2={y(tick)} className="grid-line" />
          <text x={pad.left - 10} y={y(tick) + 4} textAnchor="end" className="axis-text">{tick}</text>
        </g>
      ))}
      {metrics.map((metric, metricIndex) => {
        const def = PILLARS.find((p) => p.id === metric.definitionId);
        const color = def?.color ?? "#ffffff";
        const points = metric.historical.map((p, i) => `${x(i)},${y(p.score)}`).join(" ");
        return (
          <g key={metric.definitionId}>
            <polyline points={points} fill="none" stroke={color} strokeWidth={metricIndex === 0 && metrics.length === 1 ? 5 : 3} />
            {metric.historical.map((p, i) => <circle key={p.assessmentId} cx={x(i)} cy={y(p.score)} r="4" fill={color} />)}
          </g>
        );
      })}
      {(metrics[0]?.historical ?? []).map((p, i) => (
        <text key={p.assessmentId} x={x(i)} y={height - 14} textAnchor="middle" className="axis-text">{p.quarterLabel}</text>
      ))}
    </svg>
  );
}
