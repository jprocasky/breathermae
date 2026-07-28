<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads historical Eight Pillars scores from the existing bm_pillars_results table.
 *
 * Table columns (pillar level only):
 *   id, user_email, current_flag, is_final, results_date, updated_at,
 *   occupational, social, spiritual, mental, financial, environmental, physical, emotional,
 *   rank, master_score, notes
 *
 * Returns the history shape expected by BMAE_AVF_Eight_Pillars_Provider.
 * Subcategory data is not available in this table; pillar scores are supplied directly.
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

    /**
     * @return array<int, array<string, mixed>>  Assessment history or empty array.
     */
    public static function history_for_user(int $user_id): array {
        global $wpdb;

        $user = get_userdata($user_id);
        if (!$user || empty($user->user_email)) {
            return [];
        }

        $email = $user->user_email;
        $table = $wpdb->prefix . 'bm_pillars_results';

        // Confirm table exists to avoid fatal errors on sites without the forms plugin yet.
        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );
        if ($table_exists !== $table) {
            return [];
        }

        // Prefer finalized assessments; fall back to any rows if none are final.
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
            // Include non-final / open rows as a fallback so partial history still appears.
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

        $history = [];

        foreach ($rows as $row) {
            $assessment_id = 'bmpr-' . (int) ($row['id'] ?? 0);
            $date = self::normalize_date((string) ($row['results_date'] ?? ''));
            if ($date === '') {
                continue;
            }

            $label = self::quarter_label($date);

            $pillars = [];
            foreach (self::PILLAR_COLUMNS as $pillar_id) {
                $raw = $row[$pillar_id] ?? null;
                $score = self::normalize_score($raw);

                // Direct pillar score — no subcategory breakdown available from this table.
                $pillars[$pillar_id] = [
                    'score' => $score,
                    'subcategories' => [],
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

    private static function normalize_score(mixed $value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $score = (float) $value;
        // Some historical rows may still be 0–1; normalize to 0–100.
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
