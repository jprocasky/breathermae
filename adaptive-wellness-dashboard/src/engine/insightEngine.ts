import { ENGINE_CONFIG } from "../config/wellnessConfig";
import {
  AdaptiveInsight,
  CorrelationFinding,
  MetricModel
} from "../domain/types";

const fmt = (value: number) => `${value >= 0 ? "+" : ""}${Math.round(value)}`;

export function generateInsights(
  overall: MetricModel,
  pillars: MetricModel[],
  subcategoriesByPillar: Record<string, MetricModel[]>,
  correlations: CorrelationFinding[]
): AdaptiveInsight[] {
  const insights: AdaptiveInsight[] = [];

  if (overall.trend.direction === "up") {
    insights.push({
      id: "overall-improvement",
      title: "Overall wellness is improving",
      narrative: `Your overall wellness score has increased ${fmt(overall.trend.baselineChange)} points from baseline, with a current quarterly velocity of ${fmt(overall.trend.velocity)} points.`,
      severity: "positive",
      confidence: overall.trend.confidence,
      relatedMetricIds: ["overall"],
      evidence: [
        `Current score: ${Math.round(overall.currentScore)}`,
        `Baseline change: ${fmt(overall.trend.baselineChange)}`,
        `Trend confidence: ${Math.round(overall.trend.confidence * 100)}%`
      ],
      recommendation: "Continue the behaviors associated with the strongest sustained gains.",
      priority: 95
    });
  }

  const plateau = pillars
    .filter((metric) => metric.trend.direction === "plateau")
    .sort((a, b) => a.currentScore - b.currentScore)[0];

  if (plateau) {
    const sub = (subcategoriesByPillar[plateau.definitionId] ?? [])
      .sort((a, b) => a.currentScore - b.currentScore)[0];

    insights.push({
      id: `plateau-${plateau.definitionId}`,
      title: `${plateau.label} has plateaued`,
      narrative: `${plateau.label} has remained relatively stable for ${Math.max(2, plateau.trend.plateauLength)} consecutive quarters. ${sub ? `${sub.label} is the lowest current contributor at ${Math.round(sub.currentScore)}.` : ""}`,
      severity: plateau.currentScore >= 75 ? "neutral" : "opportunity",
      confidence: plateau.trend.confidence,
      relatedMetricIds: [plateau.definitionId, ...(sub ? [sub.definitionId] : [])],
      evidence: [
        `Slope: ${plateau.trend.slopePerQuarter.toFixed(1)} points per quarter`,
        `Consistency: ${Math.round(plateau.trend.consistency)}%`
      ],
      recommendation: sub ? `Prioritize one realistic action connected to ${sub.label}.` : "Review the behaviors underlying this pillar.",
      priority: 80
    });
  }

  const declining = pillars
    .filter((metric) => metric.trend.direction === "down")
    .sort((a, b) => a.trend.slopePerQuarter - b.trend.slopePerQuarter)[0];

  if (declining) {
    insights.push({
      id: `decline-${declining.definitionId}`,
      title: `${declining.label} needs attention`,
      narrative: `${declining.label} is trending downward at ${Math.abs(declining.trend.slopePerQuarter).toFixed(1)} points per quarter. The pattern is ${declining.trend.momentum}.`,
      severity: "watch",
      confidence: declining.trend.confidence,
      relatedMetricIds: [declining.definitionId],
      evidence: [
        `Current score: ${Math.round(declining.currentScore)}`,
        `Change from baseline: ${fmt(declining.trend.baselineChange)}`
      ],
      recommendation: "Review recent contextual changes and select the smallest high-impact corrective action.",
      priority: 90
    });
  }

  const strongest = [...pillars].sort((a, b) => b.trend.baselineChange - a.trend.baselineChange)[0];
  if (strongest && strongest.trend.baselineChange > 4) {
    insights.push({
      id: `strength-${strongest.definitionId}`,
      title: `${strongest.label} is your strongest growth area`,
      narrative: `${strongest.label} has improved ${fmt(strongest.trend.baselineChange)} points since baseline and is currently ${strongest.trend.momentum}.`,
      severity: "positive",
      confidence: strongest.trend.confidence,
      relatedMetricIds: [strongest.definitionId],
      evidence: [
        `Current score: ${Math.round(strongest.currentScore)}`,
        `Quarterly slope: ${strongest.trend.slopePerQuarter.toFixed(1)}`
      ],
      recommendation: "Identify which behaviors are transferable to another pillar.",
      priority: 78
    });
  }

  const correlation = correlations[0];
  if (correlation) {
    const labels = new Map(
      [overall, ...pillars, ...Object.values(subcategoriesByPillar).flat()].map((m) => [m.definitionId, m.label])
    );
    insights.push({
      id: "correlation-primary",
      title: "A meaningful relationship is emerging",
      narrative: `${labels.get(correlation.metricA)} and ${labels.get(correlation.metricB)} have moved in a ${correlation.direction} relationship across available assessments${correlation.lagQuarters ? `, with an apparent ${correlation.lagQuarters}-quarter lag` : ""}.`,
      severity: "neutral",
      confidence: correlation.confidence,
      relatedMetricIds: [correlation.metricA, correlation.metricB],
      evidence: [
        `Correlation: ${correlation.coefficient.toFixed(2)}`,
        `Confidence: ${Math.round(correlation.confidence * 100)}%`
      ],
      recommendation: "Treat this as a pattern to monitor, not proof of causation.",
      priority: 72
    });
  }

  return insights
    .filter((insight) => insight.confidence >= ENGINE_CONFIG.insight.minimumConfidence)
    .sort((a, b) => b.priority - a.priority)
    .slice(0, ENGINE_CONFIG.insight.maxInsights);
}
