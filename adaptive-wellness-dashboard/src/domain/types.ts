export type MetricDirection = "up" | "down" | "plateau" | "insufficient";
export type MomentumState = "accelerating" | "improving" | "stable" | "slowing" | "declining";
export type ScoreBandKey = "flourishing" | "strong" | "growing" | "developing" | "attention";
export type ConfidenceLevel = "low" | "moderate" | "high";

export interface ScoreBand {
  key: ScoreBandKey;
  label: string;
  min: number;
  max: number;
  color: string;
}

export interface SubcategoryDefinition {
  id: string;
  label: string;
  description?: string;
  weight: number;
}

export interface PillarDefinition {
  id: string;
  label: string;
  shortLabel: string;
  icon: string;
  color: string;
  weight: number;
  subcategories: SubcategoryDefinition[];
}

export interface AssessmentPoint {
  assessmentId: string;
  date: string;
  quarterLabel: string;
  score: number;
  confidence?: number;
  sourceCount?: number;
  context?: Record<string, string | number | boolean>;
}

export interface MetricSeries {
  metricId: string;
  metricLabel: string;
  pillarId?: string;
  parentMetricId?: string;
  weight: number;
  values: AssessmentPoint[];
}

export interface UserWellnessHistory {
  userId: string;
  displayName: string;
  timezone?: string;
  baselineAssessmentId: string;
  generatedAt: string;
  series: MetricSeries[];
  contextualSignals?: Record<string, Array<{ date: string; value: number | string | boolean }>>;
}

export interface TrendAnalysis {
  direction: MetricDirection;
  momentum: MomentumState;
  current: number | null;
  previous: number | null;
  baseline: number | null;
  absoluteChange: number;
  baselineChange: number;
  percentChange: number | null;
  slopePerQuarter: number;
  velocity: number;
  acceleration: number;
  volatility: number;
  consistency: number;
  plateauLength: number;
  inflectionDetected: boolean;
  inflectionIndex: number | null;
  confidence: number;
  confidenceLevel: ConfidenceLevel;
  forecast: Array<{ quarterOffset: number; score: number; lower: number; upper: number }>;
}

export interface VisualizationState {
  band: ScoreBand;
  direction: MetricDirection;
  momentum: MomentumState;
  statusLabel: string;
  arrow: string;
  opacity: number;
  emphasis: number;
}

export interface MetricModel {
  definitionId: string;
  label: string;
  pillarId?: string;
  currentScore: number;
  historical: AssessmentPoint[];
  trend: TrendAnalysis;
  visualization: VisualizationState;
}

export type InsightSeverity = "positive" | "neutral" | "opportunity" | "watch";

export interface AdaptiveInsight {
  id: string;
  title: string;
  narrative: string;
  severity: InsightSeverity;
  confidence: number;
  relatedMetricIds: string[];
  evidence: string[];
  recommendation?: string;
  priority: number;
}

export interface CorrelationFinding {
  metricA: string;
  metricB: string;
  coefficient: number;
  direction: "positive" | "inverse";
  confidence: number;
  lagQuarters: number;
}

export interface DashboardModel {
  userId: string;
  displayName: string;
  generatedAt: string;
  overall: MetricModel;
  pillars: MetricModel[];
  subcategoriesByPillar: Record<string, MetricModel[]>;
  mostImproved: MetricModel[];
  opportunities: MetricModel[];
  declining: MetricModel[];
  correlations: CorrelationFinding[];
  insights: AdaptiveInsight[];
  achievements: string[];
}
