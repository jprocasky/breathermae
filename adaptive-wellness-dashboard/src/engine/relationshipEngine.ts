import { ENGINE_CONFIG } from "../config/wellnessConfig";
import { CorrelationFinding, MetricModel } from "../domain/types";
import { pearson } from "./math";

function alignedValues(a: MetricModel, b: MetricModel, lag = 0): [number[], number[]] {
  const av = a.historical.map((point) => point.score);
  const bv = b.historical.map((point) => point.score);
  if (lag === 0) return [av, bv];
  return [av.slice(0, -lag), bv.slice(lag)];
}

export function discoverCorrelations(metrics: MetricModel[]): CorrelationFinding[] {
  const findings: CorrelationFinding[] = [];

  for (let i = 0; i < metrics.length; i++) {
    for (let j = i + 1; j < metrics.length; j++) {
      const a = metrics[i];
      const b = metrics[j];

      for (let lag = 0; lag <= ENGINE_CONFIG.correlation.maxLagQuarters; lag++) {
        const [av, bv] = alignedValues(a, b, lag);
        if (Math.min(av.length, bv.length) < ENGINE_CONFIG.correlation.minimumObservations) continue;

        const coefficient = pearson(av, bv);
        if (Math.abs(coefficient) < ENGINE_CONFIG.correlation.minimumAbsoluteCoefficient) continue;

        findings.push({
          metricA: a.definitionId,
          metricB: b.definitionId,
          coefficient,
          direction: coefficient >= 0 ? "positive" : "inverse",
          confidence: Math.min(0.98, 0.5 + Math.abs(coefficient) * 0.4 + Math.min(av.length, bv.length) * 0.015),
          lagQuarters: lag
        });
      }
    }
  }

  return findings
    .sort((a, b) => Math.abs(b.coefficient) * b.confidence - Math.abs(a.coefficient) * a.confidence)
    .slice(0, 12);
}
