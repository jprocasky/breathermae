import { SCORE_BANDS } from "../config/wellnessConfig";
import { TrendAnalysis, VisualizationState } from "../domain/types";

export function visualState(score: number, trend: TrendAnalysis): VisualizationState {
  const band = SCORE_BANDS.find((candidate) => score >= candidate.min && score <= candidate.max) ?? SCORE_BANDS.at(-1)!;

  const arrow =
    trend.direction === "up" ? "↑" :
    trend.direction === "down" ? "↓" :
    trend.direction === "plateau" ? "→" : "•";

  const statusLabel =
    trend.momentum === "accelerating" ? "Accelerating" :
    trend.momentum === "improving" ? "Improving" :
    trend.momentum === "declining" ? "Declining" :
    trend.momentum === "slowing" ? "Slowing" : "Stable";

  return {
    band,
    direction: trend.direction,
    momentum: trend.momentum,
    statusLabel,
    arrow,
    opacity: trend.confidence < 0.55 ? 0.62 : 1,
    emphasis: Math.min(1, Math.abs(trend.slopePerQuarter) / 5)
  };
}
