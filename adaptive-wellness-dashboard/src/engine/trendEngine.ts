import { ENGINE_CONFIG } from "../config/wellnessConfig";
import { AssessmentPoint, MetricDirection, MomentumState, TrendAnalysis } from "../domain/types";
import { clamp, linearRegression, mean, stdDev } from "./math";

const confidenceLevel = (value: number): "low" | "moderate" | "high" =>
  value >= 0.8 ? "high" : value >= 0.6 ? "moderate" : "low";

export function analyzeTrend(history: AssessmentPoint[]): TrendAnalysis {
  const values = history.map((p) => p.score);
  const current = values.at(-1) ?? null;
  const previous = values.at(-2) ?? null;
  const baseline = values.at(0) ?? null;

  if (values.length < 2 || current === null || previous === null || baseline === null) {
    return {
      direction: "insufficient",
      momentum: "stable",
      current,
      previous,
      baseline,
      absoluteChange: 0,
      baselineChange: 0,
      percentChange: null,
      slopePerQuarter: 0,
      velocity: 0,
      acceleration: 0,
      volatility: 0,
      consistency: 0,
      plateauLength: 0,
      inflectionDetected: false,
      inflectionIndex: null,
      confidence: 0.25,
      confidenceLevel: "low",
      forecast: []
    };
  }

  const changes = values.slice(1).map((value, index) => value - values[index]);
  const velocity = changes.at(-1) ?? 0;
  const previousVelocity = changes.at(-2) ?? velocity;
  const acceleration = velocity - previousVelocity;
  const regression = linearRegression(values);
  const volatility = stdDev(changes);

  let plateauLength = 0;
  for (let i = changes.length - 1; i >= 0; i--) {
    if (Math.abs(changes[i]) <= ENGINE_CONFIG.plateau.maxAbsoluteQuarterlyChange) plateauLength++;
    else break;
  }

  let direction: MetricDirection = "plateau";
  if (
    plateauLength < ENGINE_CONFIG.plateau.minConsecutiveIntervals &&
    regression.slope >= ENGINE_CONFIG.trend.meaningfulSlopePerQuarter
  ) direction = "up";
  else if (
    plateauLength < ENGINE_CONFIG.plateau.minConsecutiveIntervals &&
    regression.slope <= -ENGINE_CONFIG.trend.meaningfulSlopePerQuarter
  ) direction = "down";

  let momentum: MomentumState = "stable";
  if (direction === "up" && acceleration > 1) momentum = "accelerating";
  else if (direction === "up") momentum = "improving";
  else if (direction === "down" && acceleration < -1) momentum = "declining";
  else if (direction === "down") momentum = "slowing";

  let inflectionIndex: number | null = null;
  if (changes.length >= 3) {
    for (let i = 1; i < changes.length; i++) {
      if (
        Math.sign(changes[i]) !== Math.sign(changes[i - 1]) &&
        Math.abs(changes[i] - changes[i - 1]) >= ENGINE_CONFIG.inflection.minimumSlopeChange
      ) {
        inflectionIndex = i;
      }
    }
  }

  const directionAgreement =
    changes.length === 0 ? 0 : Math.abs(mean(changes.map((c) => Math.sign(c))));
  const sampleFactor = Math.min(1, values.length / 6);
  const volatilityPenalty = clamp(1 - volatility / 15, 0, 1);
  const confidence = clamp(
    (0.4 * regression.r2 + 0.25 * sampleFactor + 0.2 * directionAgreement + 0.15 * volatilityPenalty) * 100,
    0,
    100
  ) / 100;

  const residualStd = stdDev(
    values.map((value, index) => value - (regression.intercept + regression.slope * index))
  );
  const forecast = Array.from({ length: ENGINE_CONFIG.forecast.quarters }, (_, i) => {
    const quarterOffset = i + 1;
    const projectedIndex = values.length - 1 + quarterOffset;
    const score = clamp(regression.intercept + regression.slope * projectedIndex);
    const interval = ENGINE_CONFIG.forecast.confidenceZ * residualStd * Math.sqrt(1 + quarterOffset / values.length);
    return {
      quarterOffset,
      score,
      lower: clamp(score - interval),
      upper: clamp(score + interval)
    };
  });

  const consistency = clamp(100 - volatility * 5);

  return {
    direction,
    momentum,
    current,
    previous,
    baseline,
    absoluteChange: current - previous,
    baselineChange: current - baseline,
    percentChange: baseline === 0 ? null : ((current - baseline) / baseline) * 100,
    slopePerQuarter: regression.slope,
    velocity,
    acceleration,
    volatility,
    consistency,
    plateauLength,
    inflectionDetected: inflectionIndex !== null,
    inflectionIndex,
    confidence,
    confidenceLevel: confidenceLevel(confidence),
    forecast
  };
}
