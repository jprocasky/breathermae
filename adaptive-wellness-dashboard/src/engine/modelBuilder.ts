import { PILLARS } from "../config/wellnessConfig";
import {
  AssessmentPoint,
  DashboardModel,
  MetricModel,
  MetricSeries,
  UserWellnessHistory
} from "../domain/types";
import { mean } from "./math";
import { analyzeTrend } from "./trendEngine";
import { visualState } from "./visualizationEngine";
import { discoverCorrelations } from "./relationshipEngine";
import { generateInsights } from "./insightEngine";

const buildMetric = (series: MetricSeries): MetricModel => {
  const trend = analyzeTrend(series.values);
  const currentScore = trend.current ?? 0;
  return {
    definitionId: series.metricId,
    label: series.metricLabel,
    pillarId: series.pillarId,
    currentScore,
    historical: series.values,
    trend,
    visualization: visualState(currentScore, trend)
  };
};

const dateAxis = (series: MetricSeries[]): AssessmentPoint[] => {
  const longest = [...series].sort((a, b) => b.values.length - a.values.length)[0];
  return longest?.values ?? [];
};

const aggregateSeries = (
  metricId: string,
  metricLabel: string,
  sourceSeries: MetricSeries[],
  weightResolver: (s: MetricSeries) => number
): MetricSeries => {
  const axis = dateAxis(sourceSeries);
  const values = axis.map((axisPoint, index) => {
    const available = sourceSeries
      .map((series) => ({ score: series.values[index]?.score, weight: weightResolver(series) }))
      .filter((item): item is { score: number; weight: number } => Number.isFinite(item.score));

    const weightTotal = available.reduce((sum, item) => sum + item.weight, 0);
    const score = weightTotal
      ? available.reduce((sum, item) => sum + item.score * item.weight, 0) / weightTotal
      : 0;

    return { ...axisPoint, score };
  });

  return { metricId, metricLabel, weight: 1, values };
};

export function buildDashboardModel(history: UserWellnessHistory): DashboardModel {
  const rawById = new Map(history.series.map((series) => [series.metricId, series]));
  const subcategoriesByPillar: Record<string, MetricModel[]> = {};
  const pillarModels: MetricModel[] = [];

  for (const pillar of PILLARS) {
    const subSeries = pillar.subcategories
      .map((subcategory) => rawById.get(subcategory.id))
      .filter((series): series is MetricSeries => Boolean(series));

    subcategoriesByPillar[pillar.id] = subSeries.map(buildMetric);

    const pillarSeries =
      rawById.get(pillar.id) ??
      aggregateSeries(
        pillar.id,
        pillar.label,
        subSeries,
        (series) => pillar.subcategories.find((sub) => sub.id === series.metricId)?.weight ?? 1
      );

    pillarModels.push(buildMetric(pillarSeries));
  }

  const pillarSeriesForOverall = pillarModels.map((model) => ({
    metricId: model.definitionId,
    metricLabel: model.label,
    weight: PILLARS.find((p) => p.id === model.definitionId)?.weight ?? 1,
    values: model.historical
  }));

  const overallSeries =
    rawById.get("overall") ??
    aggregateSeries("overall", "Overall Wellness", pillarSeriesForOverall, (series) => series.weight);

  const overall = buildMetric(overallSeries);
  const allMetrics = [overall, ...pillarModels, ...Object.values(subcategoriesByPillar).flat()];
  const correlations = discoverCorrelations(allMetrics);
  const insights = generateInsights(overall, pillarModels, subcategoriesByPillar, correlations);

  const mostImproved = [...allMetrics]
    .filter((metric) => metric.definitionId !== "overall")
    .sort((a, b) => b.trend.baselineChange - a.trend.baselineChange)
    .slice(0, 5);

  const opportunities = [...allMetrics]
    .filter((metric) => metric.definitionId !== "overall")
    .sort((a, b) => (a.currentScore + Math.max(0, a.trend.slopePerQuarter)) - (b.currentScore + Math.max(0, b.trend.slopePerQuarter)))
    .slice(0, 5);

  const declining = [...allMetrics]
    .filter((metric) => metric.trend.direction === "down")
    .sort((a, b) => a.trend.slopePerQuarter - b.trend.slopePerQuarter)
    .slice(0, 5);

  const achievements = [
    overall.currentScore >= 80 ? "Overall wellness reached 80 or above" : null,
    pillarModels.every((p) => p.currentScore >= 60) ? "All Eight Pillars are at least Growing" : null,
    pillarModels.filter((p) => p.trend.direction === "up").length >= 6 ? "Six or more pillars are improving" : null,
    mean(pillarModels.map((p) => p.trend.consistency)) >= 85 ? "High longitudinal consistency" : null,
    overall.trend.baselineChange >= 10 ? "Overall wellness improved by at least 10 points" : null
  ].filter((item): item is string => Boolean(item));

  return {
    userId: history.userId,
    displayName: history.displayName,
    generatedAt: history.generatedAt,
    overall,
    pillars: pillarModels,
    subcategoriesByPillar,
    mostImproved,
    opportunities,
    declining,
    correlations,
    insights,
    achievements
  };
}
