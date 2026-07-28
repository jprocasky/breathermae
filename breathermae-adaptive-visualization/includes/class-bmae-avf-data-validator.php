<?php
if (!defined('ABSPATH')) {
    exit;
}

final class BMAE_AVF_Data_Validator {
    public static function validate_history(array $history): array {
        $errors = [];
        $warnings = [];
        $normalized = [];
        $registry = BMAE_AVF_Eight_Pillars_Registry::all();
        $seen_assessment_ids = [];
        $seen_dates = [];

        foreach ($history as $index => $assessment) {
            if (!is_array($assessment)) {
                $errors[] = sprintf('Assessment at index %d is not an array.', $index);
                continue;
            }

            $assessment_id = sanitize_key((string) ($assessment['assessment_id'] ?? ''));
            $assessment_date = sanitize_text_field((string) ($assessment['assessment_date'] ?? ''));
            $label = sanitize_text_field((string) ($assessment['label'] ?? ''));

            if ($assessment_id === '') {
                $errors[] = sprintf('Assessment at index %d has no assessment_id.', $index);
                continue;
            }

            if (isset($seen_assessment_ids[$assessment_id])) {
                $errors[] = sprintf('Duplicate assessment_id: %s.', $assessment_id);
                continue;
            }
            $seen_assessment_ids[$assessment_id] = true;

            $timestamp = strtotime($assessment_date);
            if ($timestamp === false) {
                $errors[] = sprintf('Assessment %s has an invalid assessment_date.', $assessment_id);
                continue;
            }

            $date_key = gmdate('Y-m-d', $timestamp);
            if (isset($seen_dates[$date_key])) {
                $warnings[] = sprintf('More than one assessment occurs on %s.', $date_key);
            }
            $seen_dates[$date_key] = true;

            $incoming_pillars = $assessment['pillars'] ?? [];
            if (!is_array($incoming_pillars)) {
                $incoming_pillars = [];
            }

            $normalized_pillars = [];
            $has_any_subcategory_data = false;

            foreach ($registry as $pillar_id => $pillar_definition) {
                $incoming_pillar = $incoming_pillars[$pillar_id] ?? [];
                if (!is_array($incoming_pillar)) {
                    $incoming_pillar = [];
                }

                // Direct pillar score (pillar-level history from bm_pillars_results).
                $direct_score = null;
                if (array_key_exists('score', $incoming_pillar)) {
                    $raw_direct = $incoming_pillar['score'];
                    if ($raw_direct !== null && $raw_direct !== '' && is_numeric($raw_direct)) {
                        $direct_score = (float) $raw_direct;
                        if ($direct_score < 0 || $direct_score > 100) {
                            $errors[] = sprintf(
                                'Assessment %s contains an out-of-range score for pillar %s.',
                                $assessment_id,
                                $pillar_id
                            );
                            $direct_score = max(0, min(100, $direct_score));
                        }
                        $direct_score = round($direct_score, 2);
                    } elseif ($raw_direct !== null && $raw_direct !== '' && !is_numeric($raw_direct)) {
                        $errors[] = sprintf(
                            'Assessment %s contains a nonnumeric score for pillar %s.',
                            $assessment_id,
                            $pillar_id
                        );
                    }
                }

                $incoming_subcategories = $incoming_pillar['subcategories'] ?? [];
                if (!is_array($incoming_subcategories)) {
                    // Legacy shape: pillar value is the subcategory map itself.
                    if ($direct_score === null && !array_key_exists('score', $incoming_pillar)) {
                        $incoming_subcategories = $incoming_pillar;
                    } else {
                        $incoming_subcategories = [];
                    }
                }

                $normalized_subcategories = [];

                foreach ($pillar_definition['subcategories'] as $subcategory_id => $subcategory_definition) {
                    $raw_score = $incoming_subcategories[$subcategory_id] ?? null;

                    if ($raw_score === null || $raw_score === '') {
                        $normalized_subcategories[$subcategory_id] = null;
                        continue;
                    }

                    $has_any_subcategory_data = true;

                    if (!is_numeric($raw_score)) {
                        $errors[] = sprintf(
                            'Assessment %s contains a nonnumeric score for %s.%s.',
                            $assessment_id,
                            $pillar_id,
                            $subcategory_id
                        );
                        $normalized_subcategories[$subcategory_id] = null;
                        continue;
                    }

                    $score = (float) $raw_score;

                    if ($score < 0 || $score > 100) {
                        $errors[] = sprintf(
                            'Assessment %s contains an out-of-range score for %s.%s.',
                            $assessment_id,
                            $pillar_id,
                            $subcategory_id
                        );
                        $score = max(0, min(100, $score));
                    }

                    $normalized_subcategories[$subcategory_id] = round($score, 2);
                }

                $normalized_pillars[$pillar_id] = [
                    'score' => $direct_score,
                    'subcategories' => $normalized_subcategories,
                ];
            }

            if (!$has_any_subcategory_data) {
                // Pillar-only history is valid; avoid flooding warnings for every missing subcategory.
                $warnings[] = sprintf(
                    'Assessment %s supplies pillar-level scores only (no subcategory detail).',
                    $assessment_id
                );
            }

            $normalized[] = [
                'assessment_id'   => $assessment_id,
                'assessment_date' => $date_key,
                'label'           => $label !== '' ? $label : gmdate('M Y', $timestamp),
                'master_score'    => self::nullable_float($assessment['master_score'] ?? null),
                'rank'            => isset($assessment['rank']) ? sanitize_text_field((string) $assessment['rank']) : null,
                'pillars'         => $normalized_pillars,
            ];
        }

        usort(
            $normalized,
            static fn(array $a, array $b): int =>
                strcmp($a['assessment_date'], $b['assessment_date'])
        );

        return [
            'valid' => count($errors) === 0,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'history' => $normalized,
        ];
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
}
