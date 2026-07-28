<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical Eight Pillars and subcategory registry.
 *
 * All identifiers are stable machine keys. Display labels can be filtered
 * without changing stored data or API contracts.
 */
final class BMAE_AVF_Eight_Pillars_Registry {
    public static function all(): array {
        $pillars = [
            'physical' => [
                'label' => 'Physical Wellness',
                'short_label' => 'Physical',
                'weight' => 1.0,
                'accent' => '#39c5aa',
                'subcategories' => [
                    'overall_physical_health' => ['label' => 'Overall Physical Health', 'weight' => 1.0],
                    'physical_activity_daily_movement' => ['label' => 'Physical Activity & Daily Movement', 'weight' => 1.0],
                    'daily_habits_health_behaviors' => ['label' => 'Daily Habits & Health Behaviors', 'weight' => 1.0],
                    'health_maintenance_medical_beliefs' => ['label' => 'Health Maintenance & Medical Beliefs', 'weight' => 1.0],
                ],
            ],
            'mental' => [
                'label' => 'Mental Wellness',
                'short_label' => 'Mental',
                'weight' => 1.0,
                'accent' => '#2d9cdb',
                'subcategories' => [
                    'cognitive_functioning' => ['label' => 'Cognitive Functioning', 'weight' => 1.0],
                    'self_efficacy' => ['label' => 'Self-Efficacy', 'weight' => 1.0],
                    'curiosity' => ['label' => 'Curiosity', 'weight' => 1.0],
                    'growth_mindset' => ['label' => 'Growth Mindset', 'weight' => 1.0],
                ],
            ],
            'emotional' => [
                'label' => 'Emotional Wellness',
                'short_label' => 'Emotional',
                'weight' => 1.0,
                'accent' => '#ef7f5b',
                'subcategories' => [
                    'emotion_regulation' => ['label' => 'Emotion Regulation', 'weight' => 1.0],
                    'emotional_self_efficacy' => ['label' => 'Emotional Self-Efficacy', 'weight' => 1.0],
                    'emotional_self_awareness' => ['label' => 'Emotional Self-Awareness', 'weight' => 1.0],
                    'self_compassion' => ['label' => 'Self-Compassion', 'weight' => 1.0],
                    'resilience' => ['label' => 'Resilience', 'weight' => 1.0],
                    'self_care' => ['label' => 'Self-Care', 'weight' => 1.0],
                ],
            ],
            'spiritual' => [
                'label' => 'Spiritual Wellness',
                'short_label' => 'Spiritual',
                'weight' => 1.0,
                'accent' => '#9b7bd3',
                'subcategories' => [
                    'meaning_purpose' => ['label' => 'Meaning and Purpose', 'weight' => 1.0],
                    'spirituality' => ['label' => 'Spirituality', 'weight' => 1.0],
                    'mindful_attention' => ['label' => 'Mindful Attention', 'weight' => 1.0],
                    'everyday_practice_frequency' => ['label' => 'Everyday Practice Frequency', 'weight' => 1.0],
                ],
            ],
            'social' => [
                'label' => 'Social Wellness',
                'short_label' => 'Social',
                'weight' => 1.0,
                'accent' => '#43a5d8',
                'subcategories' => [
                    'social_satisfaction_comfort' => ['label' => 'Social Satisfaction & Comfort', 'weight' => 1.0],
                    'social_anxiety' => ['label' => 'Social Anxiety', 'weight' => 1.0],
                    'emotional_support' => ['label' => 'Emotional Support', 'weight' => 1.0],
                    'communication' => ['label' => 'Communication', 'weight' => 1.0],
                    'taking_part_activities' => ['label' => 'Taking Part in Activities', 'weight' => 1.0],
                    'boundaries_conflict_resolution' => ['label' => 'Boundaries & Conflict Resolution', 'weight' => 1.0],
                ],
            ],
            'occupational' => [
                'label' => 'Occupational Wellness',
                'short_label' => 'Occupational',
                'weight' => 1.0,
                'accent' => '#e0a95b',
                'subcategories' => [
                    'work_life_balance' => ['label' => 'Work-Life Balance', 'weight' => 1.0],
                    'job_satisfaction' => ['label' => 'Job Satisfaction', 'weight' => 1.0],
                    'confidence_work_abilities' => ['label' => 'Confidence in Work Abilities', 'weight' => 1.0],
                    'workplace_relationships_support' => ['label' => 'Workplace Relationships & Support', 'weight' => 1.0],
                    'career_growth_work_environment' => ['label' => 'Career Growth & Work Environment', 'weight' => 1.0],
                ],
            ],
            'financial' => [
                'label' => 'Financial Wellness',
                'short_label' => 'Financial',
                'weight' => 1.0,
                'accent' => '#66b36b',
                'subcategories' => [
                    'financial_status_experience' => ['label' => 'Financial Status & Experience', 'weight' => 1.0],
                    'financial_status_experience_continued' => ['label' => 'Financial Status & Experience — Continued', 'weight' => 1.0],
                    'financial_self_efficacy' => ['label' => 'Financial Self-Efficacy', 'weight' => 1.0],
                    'financial_management_habits' => ['label' => 'Financial Management Habits', 'weight' => 1.0],
                    'financial_reflection' => ['label' => 'Financial Reflection', 'weight' => 1.0],
                ],
            ],
            'environmental' => [
                'label' => 'Environmental Wellness',
                'short_label' => 'Environmental',
                'weight' => 1.0,
                'accent' => '#47c0b6',
                'subcategories' => [
                    'connection_nature' => ['label' => 'Connection to Nature', 'weight' => 1.0],
                    'environmental_actions_behaviors' => ['label' => 'Environmental Actions & Behaviors', 'weight' => 1.0],
                    'environmental_identity' => ['label' => 'Environmental Identity', 'weight' => 1.0],
                    'limiting_radiation_device_exposure' => ['label' => 'Limiting Radiation & Device Exposure', 'weight' => 1.0],
                ],
            ],
        ];

        return apply_filters('bmae_avf_eight_pillars_registry', $pillars);
    }

    public static function score_band(float $score): array {
        if ($score >= 80.0) {
            return ['id' => 'high', 'label' => 'High', 'minimum' => 80, 'maximum' => 100];
        }

        if ($score >= 40.0) {
            return ['id' => 'moderate', 'label' => 'Moderate', 'minimum' => 40, 'maximum' => 79.99];
        }

        return ['id' => 'low', 'label' => 'Low', 'minimum' => 0, 'maximum' => 39.99];
    }

    public static function public_registry(): array {
        $public = [];

        foreach (self::all() as $pillar_id => $pillar) {
            $subcategories = [];

            foreach ($pillar['subcategories'] as $subcategory_id => $subcategory) {
                $subcategories[] = [
                    'id' => $subcategory_id,
                    'label' => $subcategory['label'],
                    'weight' => (float) $subcategory['weight'],
                ];
            }

            $public[] = [
                'id' => $pillar_id,
                'label' => $pillar['label'],
                'short_label' => $pillar['short_label'],
                'weight' => (float) $pillar['weight'],
                'accent' => $pillar['accent'],
                'subcategories' => $subcategories,
            ];
        }

        return $public;
    }
}
