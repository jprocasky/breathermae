<?php
if (!defined('ABSPATH')) {
    exit;
}

final class BMAE_AVF_Eight_Pillars_Provider {
    public static function dashboard_payload(int $user_id): array {
        $raw_history = apply_filters(
            'bmae_avf_eight_pillars_history',
            [],
            $user_id
        );

        $source = 'platform';

        // Prefer live data from bm_pillars_results when the filter did not supply history.
        if (!is_array($raw_history) || count($raw_history) === 0) {
            $adapter_history = BMAE_AVF_Pillars_Results_Adapter::history_for_user($user_id);
            if (is_array($adapter_history) && count($adapter_history) > 0) {
                $raw_history = $adapter_history;
                $source = 'bm_pillars_results';
            }
        }

        // Fall back to deterministic demo data only when nothing else is available.
        if ((!is_array($raw_history) || count($raw_history) === 0)
            && (bool) BMAE_AVF_Config::setting('enable_demo_data', true)
        ) {
            $raw_history = self::demo_history($user_id);
            $source = 'demo';
        }

        if (!is_array($raw_history)) {
            $raw_history = [];
        }

        $validation = BMAE_AVF_Data_Validator::validate_history($raw_history);
        $computed_history = self::compute_history($validation['history']);
        $current = count($computed_history) > 0
            ? $computed_history[array_key_last($computed_history)]
            : null;

        return [
            'status' => $validation['valid'] ? 'ready' : 'validation_error',
            'dashboard_id' => 'eight-pillars',
            'schema_version' => '2.1.0',
            'source' => $source,
            'user_id' => $user_id,
            'generated_at' => gmdate('c'),
            'cadence' => 'quarterly',
            'score_scale' => ['minimum' => 0, 'maximum' => 100],
            'score_bands' => [
                ['id' => 'low', 'label' => 'Low', 'minimum' => 0, 'maximum' => 39.99],
                ['id' => 'moderate', 'label' => 'Moderate', 'minimum' => 40, 'maximum' => 79.99],
                ['id' => 'high', 'label' => 'High', 'minimum' => 80, 'maximum' => 100],
            ],
            'registry' => BMAE_AVF_Eight_Pillars_Registry::public_registry(),
            'validation' => [
                'valid' => $validation['valid'],
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
            ],
            'summary' => self::summary($computed_history),
            'current' => $current,
            'history' => $computed_history,
        ];
    }

    private static function compute_history(array $history): array {
        $registry = BMAE_AVF_Eight_Pillars_Registry::all();
        $computed = [];

        foreach ($history as $assessment) {
            $pillar_results = [];
            $overall_weighted_total = 0.0;
            $overall_weight_total = 0.0;

            foreach ($registry as $pillar_id => $pillar_definition) {
                $pillar_payload = $assessment['pillars'][$pillar_id] ?? [];
                if (!is_array($pillar_payload)) {
                    $pillar_payload = [];
                }

                // Support both shapes:
                // 1) Direct pillar score (from bm_pillars_results)
                // 2) Subcategory scores (weighted average)
                $direct_score = array_key_exists('score', $pillar_payload)
                    ? self::nullable_float($pillar_payload['score'])
                    : null;

                $subscores = $pillar_payload['subcategories'] ?? [];
                if (!is_array($subscores)) {
                    $subscores = [];
                }

                $weighted_total = 0.0;
                $weight_total = 0.0;
                $public_subcategories = [];
                $has_any_sub = false;

                foreach ($pillar_definition['subcategories'] as $subcategory_id => $subcategory_definition) {
                    $score = array_key_exists($subcategory_id, $subscores)
                        ? self::nullable_float($subscores[$subcategory_id])
                        : null;

                    if ($score !== null) {
                        $has_any_sub = true;
                        $weight = (float) $subcategory_definition['weight'];
                        $weighted_total += $score * $weight;
                        $weight_total += $weight;
                    }

                    $band = $score === null
                        ? null
                        : BMAE_AVF_Eight_Pillars_Registry::score_band($score);

                    $public_subcategories[] = [
                        'id' => $subcategory_id,
                        'label' => $subcategory_definition['label'],
                        'score' => $score,
                        'band' => $band,
                    ];
                }

                // Prefer explicit pillar score when present; otherwise derive from subcategories.
                $pillar_score = $direct_score;
                if ($pillar_score === null && $weight_total > 0) {
                    $pillar_score = round($weighted_total / $weight_total, 1);
                }

                if ($pillar_score !== null) {
                    $pillar_weight = (float) $pillar_definition['weight'];
                    $overall_weighted_total += $pillar_score * $pillar_weight;
                    $overall_weight_total += $pillar_weight;
                }

                // When only pillar-level data exists, do not invent empty subcategory rows.
                if (!$has_any_sub) {
                    $public_subcategories = [];
                }

                $pillar_results[$pillar_id] = [
                    'id' => $pillar_id,
                    'label' => $pillar_definition['label'],
                    'score' => $pillar_score,
                    'band' => $pillar_score === null
                        ? null
                        : BMAE_AVF_Eight_Pillars_Registry::score_band($pillar_score),
                    'subcategories' => $public_subcategories,
                    'detail_level' => $has_any_sub ? 'subcategory' : 'pillar',
                ];
            }

            // Prefer stored master_score when the adapter supplied it.
            $master = self::nullable_float($assessment['master_score'] ?? null);
            $overall_score = $master !== null
                ? $master
                : ($overall_weight_total > 0
                    ? round($overall_weighted_total / $overall_weight_total, 1)
                    : null);

            $computed[] = [
                'assessment_id' => $assessment['assessment_id'],
                'assessment_date' => $assessment['assessment_date'],
                'label' => $assessment['label'],
                'overall_score' => $overall_score,
                'overall_band' => $overall_score === null
                    ? null
                    : BMAE_AVF_Eight_Pillars_Registry::score_band($overall_score),
                'pillars' => $pillar_results,
            ];
        }

        return self::attach_change_metrics($computed);
    }

    private static function nullable_float(mixed $value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return round((float) $value, 2);
    }

    private static function attach_change_metrics(array $history): array {
        foreach ($history as $index => &$assessment) {
            $previous = $index > 0 ? $history[$index - 1] : null;
            $assessment['overall_change'] = (
                $previous !== null
                && $assessment['overall_score'] !== null
                && $previous['overall_score'] !== null
            )
                ? round($assessment['overall_score'] - $previous['overall_score'], 1)
                : null;

            foreach ($assessment['pillars'] as $pillar_id => &$pillar) {
                $previous_score = $previous['pillars'][$pillar_id]['score'] ?? null;
                $pillar['change'] = (
                    $pillar['score'] !== null
                    && $previous_score !== null
                )
                    ? round($pillar['score'] - $previous_score, 1)
                    : null;
            }
            unset($pillar);
        }
        unset($assessment);

        return $history;
    }

    private static function summary(array $history): array {
        if (count($history) === 0) {
            return [
                'assessment_count' => 0,
                'baseline_score' => null,
                'current_score' => null,
                'total_change' => null,
                'direction' => 'insufficient_data',
                'most_improved_pillar' => null,
                'priority_pillar' => null,
            ];
        }

        $baseline = $history[0];
        $current = $history[array_key_last($history)];
        $total_change = (
            $baseline['overall_score'] !== null
            && $current['overall_score'] !== null
        )
            ? round($current['overall_score'] - $baseline['overall_score'], 1)
            : null;

        $improvements = [];
        foreach ($current['pillars'] as $pillar_id => $pillar) {
            $baseline_score = $baseline['pillars'][$pillar_id]['score'] ?? null;
            if ($baseline_score !== null && $pillar['score'] !== null) {
                $improvements[$pillar_id] = round($pillar['score'] - $baseline_score, 1);
            }
        }

        $most_improved_id = count($improvements) > 0
            ? array_search(max($improvements), $improvements, true)
            : null;

        $current_scores = [];
        foreach ($current['pillars'] as $pillar_id => $pillar) {
            if ($pillar['score'] !== null) {
                $current_scores[$pillar_id] = $pillar['score'];
            }
        }

        $priority_id = count($current_scores) > 0
            ? array_search(min($current_scores), $current_scores, true)
            : null;

        return [
            'assessment_count' => count($history),
            'baseline_score' => $baseline['overall_score'],
            'current_score' => $current['overall_score'],
            'total_change' => $total_change,
            'direction' => $total_change === null
                ? 'insufficient_data'
                : ($total_change > 0 ? 'improving' : ($total_change < 0 ? 'declining' : 'stable')),
            'most_improved_pillar' => $most_improved_id === null ? null : [
                'id' => $most_improved_id,
                'label' => $current['pillars'][$most_improved_id]['label'],
                'change' => $improvements[$most_improved_id],
            ],
            'priority_pillar' => $priority_id === null ? null : [
                'id' => $priority_id,
                'label' => $current['pillars'][$priority_id]['label'],
                'score' => $current_scores[$priority_id],
            ],
        ];
    }

    private static function demo_history(int $user_id): array {
        $dates = [
            ['id' => 'q1-2025', 'date' => '2025-01-15', 'label' => 'Q1 2025'],
            ['id' => 'q2-2025', 'date' => '2025-04-15', 'label' => 'Q2 2025'],
            ['id' => 'q3-2025', 'date' => '2025-07-15', 'label' => 'Q3 2025'],
            ['id' => 'q4-2025', 'date' => '2025-10-15', 'label' => 'Q4 2025'],
            ['id' => 'q1-2026', 'date' => '2026-01-15', 'label' => 'Q1 2026'],
            ['id' => 'q2-2026', 'date' => '2026-04-15', 'label' => 'Q2 2026'],
        ];

        $registry = BMAE_AVF_Eight_Pillars_Registry::all();
        $history = [];
        $seed = max(1, $user_id);

        foreach ($dates as $period_index => $period) {
            $pillars = [];

            foreach ($registry as $pillar_index => $pillar_definition) {
                $pillar_position = array_search($pillar_index, array_keys($registry), true);
                $subcategories = [];

                foreach ($pillar_definition['subcategories'] as $subcategory_id => $_definition) {
                    $subcategory_position = array_search(
                        $subcategory_id,
                        array_keys($pillar_definition['subcategories']),
                        true
                    );

                    $base = 47
                        + (($pillar_position * 7 + $subcategory_position * 4 + $seed) % 25);
                    $growth = $period_index * (1.8 + (($pillar_position + 1) % 3) * 0.7);
                    $wave = sin(($period_index + $subcategory_position + $seed) * 0.9) * 3.5;
                    $score = round(max(18, min(98, $base + $growth + $wave)), 1);

                    $subcategories[$subcategory_id] = $score;
                }

                $pillars[$pillar_index] = [
                    'subcategories' => $subcategories,
                ];
            }

            $history[] = [
                'assessment_id' => $period['id'],
                'assessment_date' => $period['date'],
                'label' => $period['label'],
                'pillars' => $pillars,
            ];
        }

        return $history;
    }
}
