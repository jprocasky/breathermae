import { useMemo, useState } from "react";
import { PILLARS } from "../config/wellnessConfig";
import { sampleUser } from "../data/sampleUser";
import { buildDashboardModel } from "../engine/modelBuilder";
import { LineChart } from "./components/LineChart";
import { RadarChart } from "./components/RadarChart";
import { Sparkline } from "./components/Sparkline";

export function App() {
  const model = useMemo(() => buildDashboardModel(sampleUser), []);
  const [selectedPillarId, setSelectedPillarId] = useState(model.pillars[0].definitionId);
  const selectedPillar = model.pillars.find((p) => p.definitionId === selectedPillarId)!;
  const subcategories = model.subcategoriesByPillar[selectedPillarId] ?? [];

  return (
    <main className="dashboard-shell">
      <header className="topbar">
        <div>
          <h1>8-PILLARS OF WELLNESS EVOLUTION™</h1>
          <p>Quarterly Wellness Intelligence • Personalized Adaptive Computational Model</p>
        </div>
        <div className="date-chip">Mar 2026 → Mar 2027</div>
      </header>

      <section className="dashboard-grid">
        <aside className="left-column">
          <section className="panel overall-card">
            <h2>Overall Wellness Score</h2>
            <div className="score-row">
              <span className="hero-score">{Math.round(model.overall.currentScore)}</span>
              <div>
                <strong className="positive">↑ {Math.round(model.overall.trend.baselineChange)}</strong>
                <small>Since baseline</small>
              </div>
            </div>
            <MetricRow label="Awareness" value={78} delta={8} />
            <MetricRow label="Behavior Change" value={74} delta={11} />
            <MetricRow label="Consistency Index" value={Math.round(model.overall.trend.consistency)} suffix="%" />
            <MetricRow label="Growth Rate" value={Number(model.overall.trend.slopePerQuarter.toFixed(1))} />

            <h3>Wellness Trajectory</h3>
            <div className="trajectory">
              {["Baseline","Awareness","Behavior","Integration","Alignment","Sustained"].map((x, i) => (
                <div key={x} className={`trajectory-step ${i <= 4 ? "active" : ""}`}>
                  <span />
                  <small>{x}</small>
                </div>
              ))}
            </div>

            <div className="momentum-box">
              <span className="momentum-icon">↗</span>
              <div>
                <small>Wellness Momentum</small>
                <strong>{model.overall.visualization.statusLabel}</strong>
                <p>Your wellness is changing at {Math.abs(model.overall.trend.slopePerQuarter).toFixed(1)} points per quarter.</p>
              </div>
            </div>
          </section>

          <section className="panel">
            <h2>Wellness Balance Wheel</h2>
            <RadarChart metrics={model.pillars} />
          </section>
        </aside>

        <section className="center-column">
          <section className="panel">
            <div className="section-title">
              <div>
                <h2>8-Pillars Historical Trends</h2>
                <p>Every line is derived from individualized quarterly values.</p>
              </div>
              <span className="badge">Average + Individual</span>
            </div>
            <LineChart metrics={model.pillars} />
          </section>

          <section className="panel">
            <h2>Overall Wellness Trend</h2>
            <LineChart metrics={[model.overall]} compact />
          </section>

          <section className="three-up">
            <section className="panel">
              <h2>Most Improved</h2>
              {model.mostImproved.slice(0, 4).map((metric, i) => (
                <RankRow key={metric.definitionId} rank={i + 1} label={metric.label} value={metric.trend.baselineChange} />
              ))}
            </section>

            <section className="panel">
              <h2>Top Opportunities</h2>
              {model.opportunities.slice(0, 4).map((metric, i) => (
                <RankRow key={metric.definitionId} rank={i + 1} label={metric.label} value={metric.currentScore} suffix="" />
              ))}
            </section>

            <section className="panel">
              <h2>Achievements</h2>
              {model.achievements.length ? model.achievements.map((x) => <p key={x}>✓ {x}</p>) : <p>No milestone yet.</p>}
            </section>
          </section>
        </section>

        <aside className="right-column">
          <section className="panel">
            <div className="section-title">
              <h2>8-Pillars at a Glance</h2>
              <small>Current vs baseline</small>
            </div>
            <div className="pillar-grid">
              {model.pillars.map((metric) => {
                const def = PILLARS.find((p) => p.id === metric.definitionId)!;
                return (
                  <button
                    className={`pillar-card ${selectedPillarId === metric.definitionId ? "selected" : ""}`}
                    key={metric.definitionId}
                    onClick={() => setSelectedPillarId(metric.definitionId)}
                    style={{ "--pillar-color": def.color } as React.CSSProperties}
                  >
                    <div className="pillar-title"><span>{def.icon}</span>{def.shortLabel}</div>
                    <strong>{Math.round(metric.currentScore)}</strong>
                    <Sparkline values={metric.historical.map((p) => p.score)} color={def.color} />
                    <small>{metric.visualization.arrow} {Math.round(metric.trend.baselineChange)}</small>
                  </button>
                );
              })}
            </div>
          </section>

          <section className="panel">
            <div className="section-title">
              <h2>Pillar Breakdown: {selectedPillar.label}</h2>
              <span className="badge">{selectedPillar.visualization.statusLabel}</span>
            </div>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr><th>Subcategory</th><th>Baseline</th><th>Current</th><th>Trend</th></tr>
                </thead>
                <tbody>
                  {subcategories.map((metric) => (
                    <tr key={metric.definitionId}>
                      <td>{metric.label}</td>
                      <td>{Math.round(metric.trend.baseline ?? 0)}</td>
                      <td>{Math.round(metric.currentScore)}</td>
                      <td><Sparkline values={metric.historical.map((p) => p.score)} color={selectedPillar.visualization.band.color} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <section className="panel insight-panel">
            <h2>Adaptive Intelligence Insights</h2>
            {model.insights.map((insight) => (
              <article key={insight.id} className={`insight ${insight.severity}`}>
                <div className="insight-heading">
                  <strong>{insight.title}</strong>
                  <span>{Math.round(insight.confidence * 100)}%</span>
                </div>
                <p>{insight.narrative}</p>
                {insight.recommendation && <small>{insight.recommendation}</small>}
              </article>
            ))}
          </section>

          <section className="panel">
            <h2>Correlation Discoveries</h2>
            {model.correlations.slice(0, 4).map((c) => (
              <div className="correlation-row" key={`${c.metricA}-${c.metricB}-${c.lagQuarters}`}>
                <span>{c.metricA.replaceAll("_", " ")}</span>
                <strong>{c.coefficient.toFixed(2)}</strong>
                <span>{c.metricB.replaceAll("_", " ")}</span>
              </div>
            ))}
          </section>
        </aside>
      </section>
    </main>
  );
}

function MetricRow({ label, value, delta, suffix = "" }: { label: string; value: number; delta?: number; suffix?: string }) {
  return (
    <div className="metric-row">
      <span>{label}</span>
      <strong>{value}{suffix}</strong>
      <small>{delta ? `↑ ${delta}` : ""}</small>
    </div>
  );
}

function RankRow({ rank, label, value, suffix = " pts" }: { rank: number; label: string; value: number; suffix?: string }) {
  return (
    <div className="rank-row">
      <span>{rank}</span>
      <p>{label}</p>
      <strong>{Math.round(value)}{suffix}</strong>
    </div>
  );
}
