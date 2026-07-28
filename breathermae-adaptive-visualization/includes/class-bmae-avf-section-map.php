<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps bm_form_sections.id → canonical registry subcategory IDs.
 *
 * Source: live forms 18–25 section list (2026-07-28 export).
 * Forms are treated as the source of truth for what is actually assessed.
 */
final class BMAE_AVF_Section_Map {

    /**
     * form_id → pillar_id (matches BMF_Pillars_Saver).
     */
    public static function form_to_pillar(): array {
        return [
            18 => 'physical',
            19 => 'mental',
            20 => 'spiritual',
            21 => 'occupational',
            22 => 'financial',
            23 => 'social',
            24 => 'environmental',
            25 => 'emotional',
        ];
    }

    /**
     * section_id → subcategory registry id.
     */
    public static function section_to_subcategory(): array {
        return [
            // Physical (form 18)
            296 => 'overall_physical_health',
            297 => 'physical_activity_daily_movement',
            298 => 'daily_habits_health_behaviors',
            299 => 'health_maintenance_medical_beliefs',

            // Mental (form 19)
            260 => 'cognitive_functioning',           // Everyday Thinking & Memory
            261 => 'self_efficacy',                   // Confidence in Handling Everyday Challenges
            262 => 'curiosity',                       // Curiosity and Learning
            263 => 'growth_mindset',                  // Beliefs About Personal Change
            264 => 'mind_body_connection',            // Exploring the Mind-Body Connection (forms-only)

            // Spiritual (form 20)
            265 => 'meaning_purpose',
            266 => 'spirituality',                    // Spirituality and Support
            267 => 'mindful_attention',               // Mindful Awareness in the Moment
            268 => 'everyday_practice_frequency',     // Everyday Practices that Support Inner Well-being

            // Occupational (form 21)
            269 => 'work_life_balance',
            270 => 'job_satisfaction',
            271 => 'confidence_work_abilities',
            272 => 'workplace_relationships_support',
            273 => 'career_growth_work_environment',

            // Financial (form 22)
            274 => 'financial_status_experience',
            275 => 'financial_status_experience_continued',
            276 => 'financial_self_efficacy',
            277 => 'financial_management_habits',
            278 => 'financial_reflection',

            // Social (form 23) — forms has 5 sections; registry also lists social_anxiety (not in forms)
            279 => 'social_satisfaction_comfort',
            280 => 'emotional_support',
            281 => 'communication',
            282 => 'taking_part_activities',
            283 => 'boundaries_conflict_resolution',

            // Environmental (form 24)
            284 => 'connection_nature',
            285 => 'environmental_actions_behaviors',
            286 => 'environmental_identity',          // Environmental Identity and Reflection
            287 => 'limiting_radiation_device_exposure',
            288 => 'environmental_reflection',        // forms-only extra

            // Emotional (form 25)
            289 => 'emotion_regulation',
            290 => 'emotional_self_efficacy',         // Emotion Regulation Confidence
            291 => 'emotional_self_awareness',
            292 => 'self_compassion',
            293 => 'resilience',
            294 => 'self_care',
        ];
    }

    public static function pillar_form_ids(): array {
        return array_keys(self::form_to_pillar());
    }

    public static function subcategory_for_section(int $section_id): ?string {
        $map = self::section_to_subcategory();
        return $map[$section_id] ?? null;
    }

    public static function pillar_for_form(int $form_id): ?string {
        $map = self::form_to_pillar();
        return $map[$form_id] ?? null;
    }
}
