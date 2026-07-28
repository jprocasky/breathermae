<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads historical Eight Pillars scores from bm_pillars_results and enriches
 * each assessment with subcategory (section) scores from bm_section_scores.
 */
final class BMAE_AVF_Pillars_Results_Adapter {

    private const PILLAR_COLUMNS = [
        'physical',
        'mental',
        'emotional',
        'spiritual',
        'social',
        'occupational',
        'financial',
        'environmental',
    ];

    /** Grace window (days) when matching form responses to a results_date. */
    private const RESPONSE_GRACE_DAYS = 21;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function history_for_user(int $user_id): array {
        global $wpdb;

        $user = get_userdata($user_id);
        if (!$user || empty($user->user_email)) {
            return [];
        }

        $email = $user->user_email;
        $table = $wpdb->prefix . 'bm_pillars_results';

        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );
        if ($table_exists !== $table) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                   FROM {$table}
                  WHERE user_email = %s
                    AND is_final = 1
                  ORDER BY results_date ASC, id ASC",
                $email
            ),
            ARRAY_A
        );

        if (!is_array($rows) || count($rows) === 0) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT *
                       FROM {$table}
                      WHERE user_email = %s
                      ORDER BY results_date ASC, id ASC",
                    $email
                ),
                ARRAY_A
            );
        }

        if (!is_array($rows) || count($rows) === 0) {
            return [];
        }

        // Pre-load subcategory snapshots keyed by assessment date.
        $sub_by_date = self::subcategory_snapshots_for_user($user_id, $rows);

        $history = [];

        foreach ($rows as $row) {
            $assessment_id = 'bmpr-' . (int) ($row['id'] ?? 0);
            $date = self::normalize_date((string) ($row['results_date'] ?? ''));
            if ($date === '') {
                continue;
            }

            $label = self::quarter_label($date);
            $subs_for_assessment = $sub_by_date[$date] ?? [];

            $pillars = [];
            foreach (self::PILLAR_COLUMNS as $pillar_id) {
                $raw = $row[$pillar_id] ?? null;
                $score = self::normalize_score($raw);

                $pillars[$pillar_id] = [
                    'score' => $score,
                    'subcategories' => $subs_for_assessment[$pillar_id] ?? [],
                ];
            }

            $history[] = [
                'assessment_id'   => $assessment_id,
                'assessment_date' => $date,
                'label'           => $label,
                'master_score'    => self::normalize_score($row['master_score'] ?? null),
                'rank'            => isset($row['rank']) ? sanitize_text_field((string) $row['rank']) : null,
                'pillars'         => $pillars,
            ];
        }

        return $history;
    }

    /**
     * For each assessment date, find the best matching submitted response per
     * pillar form and collect section scores mapped to registry subcategory IDs.
     *
     * @param array<int, array<string, mixed>> $assessment_rows
     * @return array<string, array<string, array<string, float|null>>> date => pillar_id => subcategory_id => score
     */
    private static function subcategory_snapshots_for_user(int $user_id, array $assessment_rows): array {
        global $wpdb;

        $responses_table = $wpdb->prefix . 'bm_responses';
        $scores_table    = $wpdb->prefix . 'bm_section_scores';

        $responses_exist = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $responses_table));
        $scores_exist    = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $scores_table));

        if ($responses_exist !== $responses_table || $scores_exist !== $scores_table) {
            return [];
        }

        $form_ids = BMAE_AVF_Section_Map::pillar_form_ids();
        if (count($form_ids) === 0) {
            return [];
        }

        $form_id_list = implode(',', array_map('intval', $form_ids));

        // All submitted pillar-form responses for this user, newest first.
        $responses = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, form_id, submitted_at
                   FROM {$responses_table}
                  WHERE user_id = %d
                    AND form_id IN ({$form_id_list})
                    AND status = 'submitted'
                    AND submitted_at IS NOT NULL
                  ORDER BY submitted_at DESC, id DESC",
                $user_id
            ),
            ARRAY_A
        );

        if (!is_array($responses) || count($responses) === 0) {
            return [];
        }

        // Group responses by form_id for quick lookup.
        $by_form = [];
        foreach ($responses as $response) {
            $fid = (int) $response['form_id'];
            $by_form[$fid][] = $response;
        }

        $section_map = BMAE_AVF_Section_Map::section_to_subcategory();
        $snapshots = [];

        foreach ($assessment_rows as $row) {
            $date = self::normalize_date((string) ($row['results_date'] ?? ''));
            if ($date === '') {
                continue;
            }

            $target_ts = strtotime($date . ' 23:59:59');
            if ($target_ts === false) {
                continue;
            }

            $grace_start = $target_ts - (self::RESPONSE_GRACE_DAYS * DAY_IN_SECONDS);
            $grace_end   = $target_ts + (self::RESPONSE_GRACE_DAYS * DAY_IN_SECONDS);

            $pillar_subs = [];

            foreach (BMAE_AVF_Section_Map::form_to_pillar() as $form_id => $pillar_id) {
                $candidates = $by_form[$form_id] ?? [];
                $best = null;
                $best_delta = null;

                foreach ($candidates as $candidate) {
                    $submitted_ts = strtotime((string) $candidate['submitted_at']);
                    if ($submitted_ts === false) {
                        continue;
                    }

                    // Prefer responses on or before results_date within grace;
                    // otherwise allow slightly after (same grace window).
                    if ($submitted_ts < $grace_start || $submitted_ts > $grace_end) {
                        continue;
                    }

                    $delta = abs($target_ts - $submitted_ts);
                    // Prefer on-or-before when deltas are equal.
                    $prefer = ($submitted_ts <= $target_ts) ? 0 : 1;

                    if ($best === null
                        || $delta < $best_delta
                        || ($delta === $best_delta && $prefer === 0 && strtotime((string) $best['submitted_at']) > $target_ts)
                    ) {
                        $best = $candidate;
                        $best_delta = $delta;
                    }
                }

                if ($best === null) {
                    $pillar_subs[$pillar_id] = [];
                    continue;
                }

                $response_id = (int) $best['id'];
                $score_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT section_id, score
                           FROM {$scores_table}
                          WHERE response_id = %d",
                        $response_id
                    ),
                    ARRAY_A
                );

                $mapped = [];
                if (is_array($score_rows)) {
                    foreach ($score_rows as $score_row) {
                        $section_id = (int) ($score_row['section_id'] ?? 0);
                        $sub_id = $section_map[$section_id] ?? null;
                        if ($sub_id === null) {
                            continue;
                        }
                        $mapped[$sub_id] = self::normalize_score($score_row['score'] ?? null);
                    }
                }

                $pillar_subs[$pillar_id] = $mapped;
            }

            $snapshots[$date] = $pillar_subs;
        }

        return $snapshots;
    }

    private static function normalize_score(mixed $value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $score = (float) $value;
        if ($score > 0 && $score <= 1.0) {
            $score = $score * 100;
        }
        return round(max(0, min(100, $score)), 2);
    }

    private static function normalize_date(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return '';
        }
        return gmdate('Y-m-d', $ts);
    }

    private static function quarter_label(string $ymd): string {
        $ts = strtotime($ymd);
        if ($ts === false) {
            return $ymd;
        }
        $month = (int) gmdate('n', $ts);
        $year  = (int) gmdate('Y', $ts);
        $q = (int) ceil($month / 3);
        return sprintf('Q%d %d', $q, $year);
    }
}
