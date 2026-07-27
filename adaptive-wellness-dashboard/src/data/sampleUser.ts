import { PILLARS } from "../config/wellnessConfig";
import { MetricSeries, UserWellnessHistory } from "../domain/types";

const dates = [
  ["2026-03-11", "Baseline"],
  ["2026-06-09", "Q2 2026"],
  ["2026-09-07", "Q3 2026"],
  ["2026-12-06", "Q4 2026"],
  ["2027-03-07", "Year 1"]
] as const;

const seed: Record<string, number[]> = {
  physical: [42, 47, 51, 56, 53],
  mental: [64, 71, 78, 82, 84],
  emotional: [49, 60, 71, 76, 79],
  spiritual: [58, 67, 74, 82, 86],
  social: [45, 53, 61, 68, 72],
  occupational: [52, 61, 70, 77, 82],
  financial: [37, 45, 53, 62, 68],
  environmental: [61, 72, 80, 85, 87]
};

const makeValues = (scores: number[]) =>
  dates.map(([date, quarterLabel], index) => ({
    assessmentId: `assessment-${index + 1}`,
    date,
    quarterLabel,
    score: scores[index],
    confidence: 0.9,
    sourceCount: 4
  }));

const series: MetricSeries[] = [];

for (const pillar of PILLARS) {
  const pillarScores = seed[pillar.id];
  pillar.subcategories.forEach((sub, subIndex) => {
    const offset = (subIndex - (pillar.subcategories.length - 1) / 2) * 3;
    const scores = pillarScores.map((score, idx) => {
      const wave = ((idx + subIndex) % 3 - 1) * 1.3;
      return Math.max(0, Math.min(100, Math.round(score + offset + wave)));
    });

    series.push({
      metricId: sub.id,
      metricLabel: sub.label,
      pillarId: pillar.id,
      parentMetricId: pillar.id,
      weight: sub.weight,
      values: makeValues(scores)
    });
  });
}

export const sampleUser: UserWellnessHistory = {
  userId: "demo-user-001",
  displayName: "Personal Wellness Profile",
  baselineAssessmentId: "assessment-1",
  generatedAt: new Date().toISOString(),
  series
};
