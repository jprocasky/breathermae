import { PillarDefinition, ScoreBand } from "../domain/types";

export const SCORE_BANDS: ScoreBand[] = [
  { key: "flourishing", label: "Flourishing", min: 90, max: 100, color: "#23e5d3" },
  { key: "strong", label: "Strong", min: 75, max: 89.999, color: "#2f9bff" },
  { key: "growing", label: "Growing", min: 60, max: 74.999, color: "#9bd33d" },
  { key: "developing", label: "Developing", min: 40, max: 59.999, color: "#ffb020" },
  { key: "attention", label: "Needs Attention", min: 0, max: 39.999, color: "#ff5364" }
];

export const PILLARS: PillarDefinition[] = [
  {
    id: "physical",
    label: "Physical Wellness",
    shortLabel: "Physical",
    icon: "🏃",
    color: "#2f9bff",
    weight: 1,
    subcategories: [
      { id: "physical_overall_health", label: "Overall Physical Health", weight: 1 },
      { id: "physical_movement", label: "Physical Activity & Daily Movement", weight: 1 },
      { id: "physical_habits", label: "Daily Habits & Health Behaviors", weight: 1 },
      { id: "physical_maintenance", label: "Health Maintenance & Medical Beliefs", weight: 1 }
    ]
  },
  {
    id: "mental",
    label: "Mental Wellness",
    shortLabel: "Mental",
    icon: "🧠",
    color: "#33d8d2",
    weight: 1,
    subcategories: [
      { id: "mental_cognition", label: "Cognitive Functioning", weight: 1 },
      { id: "mental_self_efficacy", label: "Self-Efficacy", weight: 1 },
      { id: "mental_curiosity", label: "Curiosity", weight: 1 },
      { id: "mental_growth", label: "Growth Mindset", weight: 1 }
    ]
  },
  {
    id: "emotional",
    label: "Emotional Wellness",
    shortLabel: "Emotional",
    icon: "❤",
    color: "#ff3f8f",
    weight: 1,
    subcategories: [
      { id: "emotional_regulation", label: "Emotion Regulation", weight: 1 },
      { id: "emotional_self_efficacy", label: "Emotional Self-Efficacy", weight: 1 },
      { id: "emotional_awareness", label: "Emotional Self-Awareness", weight: 1 },
      { id: "emotional_compassion", label: "Self-Compassion", weight: 1 },
      { id: "emotional_resilience", label: "Resilience", weight: 1 },
      { id: "emotional_self_care", label: "Self-Care", weight: 1 }
    ]
  },
  {
    id: "spiritual",
    label: "Spiritual Wellness",
    shortLabel: "Spiritual",
    icon: "✦",
    color: "#a770ff",
    weight: 1,
    subcategories: [
      { id: "spiritual_meaning", label: "Meaning and Purpose", weight: 1 },
      { id: "spirituality", label: "Spirituality", weight: 1 },
      { id: "spiritual_mindfulness", label: "Mindful Attention", weight: 1 },
      { id: "spiritual_practice", label: "Everyday Practice Frequency", weight: 1 }
    ]
  },
  {
    id: "social",
    label: "Social Wellness",
    shortLabel: "Social",
    icon: "🤝",
    color: "#ff7a00",
    weight: 1,
    subcategories: [
      { id: "social_satisfaction", label: "Social Satisfaction & Comfort", weight: 1 },
      { id: "social_anxiety", label: "Social Anxiety", weight: 1 },
      { id: "social_support", label: "Emotional Support", weight: 1 },
      { id: "social_communication", label: "Communication", weight: 1 },
      { id: "social_activities", label: "Taking Part in Activities", weight: 1 },
      { id: "social_boundaries", label: "Boundaries & Conflict Resolution", weight: 1 }
    ]
  },
  {
    id: "occupational",
    label: "Occupational Wellness",
    shortLabel: "Occupational",
    icon: "💼",
    color: "#ffc428",
    weight: 1,
    subcategories: [
      { id: "occupational_balance", label: "Work-Life Balance", weight: 1 },
      { id: "occupational_satisfaction", label: "Job Satisfaction", weight: 1 },
      { id: "occupational_confidence", label: "Confidence in Work Abilities", weight: 1 },
      { id: "occupational_relationships", label: "Workplace Relationships & Support", weight: 1 },
      { id: "occupational_growth", label: "Career Growth & Work Environment", weight: 1 }
    ]
  },
  {
    id: "financial",
    label: "Financial Wellness",
    shortLabel: "Financial",
    icon: "$",
    color: "#49d65d",
    weight: 1,
    subcategories: [
      { id: "financial_status", label: "Financial Status & Experience", weight: 1 },
      { id: "financial_self_efficacy", label: "Financial Self-Efficacy", weight: 1 },
      { id: "financial_habits", label: "Financial Management Habits", weight: 1 },
      { id: "financial_reflection", label: "Financial Reflection", weight: 1 }
    ]
  },
  {
    id: "environmental",
    label: "Environmental Wellness",
    shortLabel: "Environmental",
    icon: "🌿",
    color: "#b8df36",
    weight: 1,
    subcategories: [
      { id: "environmental_nature", label: "Connection to Nature", weight: 1 },
      { id: "environmental_actions", label: "Environmental Actions & Behaviors", weight: 1 },
      { id: "environmental_identity", label: "Environmental Identity", weight: 1 },
      { id: "environmental_exposure", label: "Limiting Radiation & Device Exposure", weight: 1 }
    ]
  }
];

export const ENGINE_CONFIG = {
  plateau: {
    maxAbsoluteQuarterlyChange: 2.0,
    minConsecutiveIntervals: 2
  },
  trend: {
    meaningfulSlopePerQuarter: 1.25,
    strongSlopePerQuarter: 3.5
  },
  inflection: {
    minimumSlopeChange: 2.5
  },
  forecast: {
    quarters: 2,
    confidenceZ: 1.64
  },
  insight: {
    minimumConfidence: 0.55,
    maxInsights: 6
  },
  correlation: {
    minimumObservations: 4,
    minimumAbsoluteCoefficient: 0.55,
    maxLagQuarters: 1
  }
} as const;
