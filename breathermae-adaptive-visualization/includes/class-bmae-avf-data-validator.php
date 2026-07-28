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

            foreach ($registry as $pillar_id => $pillar_definition) {
                $incoming_pillar = $incoming_pillars[$pillar_id] ?? [];
                $incoming_subcategories = is_array($incoming_pillar)
                    ? ($incoming_pillar['subcategories'] ?? $incoming_pillar)
                    : [];

                if (!is_array($incoming_subcategories)) {
                    $incoming_subcategories = [];
                }

                $normalized_subcategories = [];

                foreach ($pillar_definition['subcategories'] as $subcategory_id => $subcategory_definition) {
                    $raw_score = $incoming_subcategories[$subcategory_id] ?? null;

                    if ($raw_score === null || $raw_score === '') {
                        $warnings[] = sprintf(
                            'Assessment %s is missing %s.%s.',
                            $assessment_id,
                            $pillar_id,
                            $subcategory_id
                        );
                        $normalized_subcategories[$subcategory_id] = null;
                        continue;
                    }

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
                    'subcategories' => $normalized_subcategories,
                ];
            }

            $normalized[] = [
                'assessment_id' => $assessment_id,
                'assessment_date' => $date_key,
                'label' => $label !== '' ? $label : gmdate('M Y', $timestamp),
                'pillars' => $normalized_pillars,
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
}
