<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('BMF_Pillars_Saver')) {

    class BMF_Pillars_Saver {

        const SERIES_FULL  = 'full';
        const SERIES_RAPID = 'rapid';

        /** Combined Rapid 8 Pillars form (free snapshot). */
        const RAPID_FORM_ID = 17;

        /** Map form_id (18-25) → pillar column slug */
        public static $form_to_pillar = [
            18 => 'physical',
            19 => 'mental',
            20 => 'spiritual',
            21 => 'occupational',
            22 => 'financial',
            23 => 'social',
            24 => 'environmental',
            25 => 'emotional',
        ];

        public static $pillar_columns = [
            'physical',
            'mental',
            'emotional',
            'financial',
            'occupational',
            'environmental',
            'spiritual',
            'social',
        ];

        public static function rapid_slugs() {
            return [
                '8_pillars',
                '8-pillars',
                'rapid-8-pillars',
                'rapid_8_pillars',
                '8pillars',
                'rapid-pillars',
            ];
        }

        /** Normalize score to 0-100 (consistent with most BMF scoring) */
        protected static function norm100($v) {
            $f = (float)$v;
            return ($f <= 1.0) ? round($f * 100, 2) : round($f, 2);
        }

        public static function is_rapid_form($form_id, $slug = '') {
            $form_id = (int) $form_id;
            if ($form_id === self::RAPID_FORM_ID) {
                return true;
            }
            $slug = strtolower(trim((string) $slug));
            if ($slug !== '' && in_array($slug, self::rapid_slugs(), true)) {
                return true;
            }
            if ($form_id > 0) {
                if (class_exists('BMF_Repository')) {
                    $row = BMF_Repository::get_form($form_id);
                    if ($row && !empty($row->slug) && in_array(strtolower((string) $row->slug), self::rapid_slugs(), true)) {
                        return true;
                    }
                }
            }
            return false;
        }

        public static function series_is_full_sql($column = 'series') {
            return "( {$column} = 'full' OR {$column} IS NULL OR {$column} = '' )";
        }

        /** Return row_id of the single OPEN (full-series) row for this user (or 0) */
        public static function get_open_row_id($user_id) {
            global $wpdb;
            $t_open = $wpdb->prefix . 'bm_pillars_open';
            $t_res  = $wpdb->prefix . 'bm_pillars_results';
            $user = get_userdata($user_id);
            if (!$user || empty($user->user_email)) return 0;
            $email = $user->user_email;

            $row_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT o.row_id
                   FROM {$t_open} o
                   INNER JOIN {$t_res} r ON r.id = o.row_id
                  WHERE o.user_email = %s
                    AND " . self::series_is_full_sql('r.series') . "
                  LIMIT 1",
                $email
            ));
            return $row_id;
        }

        /** Ensure a single OPEN full-series row exists for the user. Creates if missing. */
        public static function ensure_open_row($user_id) {
            global $wpdb;
            $t_res  = $wpdb->prefix . 'bm_pillars_results';
            $t_open = $wpdb->prefix . 'bm_pillars_open';

            $user = get_userdata($user_id);
            if (!$user || empty($user->user_email)) return 0;
            $email = $user->user_email;

            $row_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT o.row_id
                   FROM {$t_open} o
                   INNER JOIN {$t_res} r ON r.id = o.row_id
                  WHERE o.user_email = %s
                    AND " . self::series_is_full_sql('r.series') . "
                  LIMIT 1",
                $email
            ));

            if ($row_id) {
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(1) FROM {$t_res}
                      WHERE id = %d AND user_email = %s
                        AND " . self::series_is_full_sql('series'),
                    $row_id, $email
                ));
                if ($exists) return $row_id;

                $wpdb->delete($t_open, ['user_email' => $email], ['%s']);
                $row_id = 0;
            }

            $now = current_time('mysql', 1);
            $ins = $wpdb->insert($t_res, [
                'user_email'   => $email,
                'current_flag' => 1,
                'is_final'     => 0,
                'results_date' => substr($now, 0, 10),
                'updated_at'   => $now,
                'series'       => self::SERIES_FULL,
            ], ['%s', '%d', '%d', '%s', '%s', '%s']);

            if (!$ins) return 0;

            $row_id = (int) $wpdb->insert_id;

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$t_open} (user_email, row_id)
                 VALUES (%s, %d)
                 ON DUPLICATE KEY UPDATE row_id = VALUES(row_id)",
                $email, $row_id
            ));

            return $row_id;
        }

        /**
         * Save one pillar average (from pivot view) into the open FULL row.
         * $form_id must be 18-25. $avg can be 0-1 or 0-100.
         */
        public static function save_pillar($user_id, $form_id, $avg) {
            global $wpdb;
            $t_res = $wpdb->prefix . 'bm_pillars_results';

            if (!isset(self::$form_to_pillar[$form_id])) return false;

            $user = get_userdata($user_id);
            if (!$user || empty($user->user_email)) return false;
            $email = $user->user_email;

            $pillar = self::$form_to_pillar[$form_id];
            $norm   = self::norm100($avg);

            $row_id = self::ensure_open_row($user_id);
            if (!$row_id) return false;

            $wpdb->query($wpdb->prepare(
                "UPDATE {$t_res}
                    SET {$pillar} = %f,
                        series = %s,
                        updated_at = NOW()
                  WHERE id = %d AND user_email = %s AND current_flag = 1
                    AND " . self::series_is_full_sql('series'),
                $norm, self::SERIES_FULL, $row_id, $email
            ));

            self::maybe_finalize($user_id, $row_id);
            return true;
        }

        /**
         * Save the rank string (from form_id=26, question_id=1314 free_text).
         */
        public static function save_rank($user_id, $rank_string) {
            global $wpdb;
            $t_res = $wpdb->prefix . 'bm_pillars_results';

            $user = get_userdata($user_id);
            if (!$user || empty($user->user_email)) return false;
            $email = $user->user_email;

            $row_id = self::ensure_open_row($user_id);
            if (!$row_id) return false;

            $wpdb->query($wpdb->prepare(
                "UPDATE {$t_res}
                    SET `rank` = %s,
                        series = %s,
                        updated_at = NOW()
                  WHERE id = %d AND user_email = %s AND current_flag = 1
                    AND " . self::series_is_full_sql('series'),
                $rank_string, self::SERIES_FULL, $row_id, $email
            ));

            self::maybe_finalize($user_id, $row_id);
            return true;
        }

        /** Check if all 8 pillars + rank are present → finalize the FULL row */
        protected static function maybe_finalize($user_id, $row_id) {
            global $wpdb;
            $t_res  = $wpdb->prefix . 'bm_pillars_results';
            $t_open = $wpdb->prefix . 'bm_pillars_open';

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT occupational, social, spiritual, mental, financial, environmental, physical, emotional, `rank`
                   FROM {$t_res}
                  WHERE id=%d
                  LIMIT 1",
                $row_id
            ), ARRAY_A);

            if (!$row) return;

            $pillars = [];
            $all_present = true;

            foreach (['occupational','social','spiritual','mental','financial','environmental','physical','emotional'] as $col) {
                $val = $row[$col] ?? null;
                if ($val === null || $val === '') {
                    $all_present = false;
                } else {
                    $pillars[] = (float)$val;
                }
            }

            $has_rank = !empty($row['rank']);

            if ($all_present && $has_rank && count($pillars) === 8) {
                $master = round(array_sum($pillars) / 8, 2);
                $now = current_time('mysql', 1);

                $wpdb->query($wpdb->prepare(
                    "UPDATE {$t_res}
                        SET is_final=1, current_flag=0, master_score=%f, results_date=%s, updated_at=%s, series=%s
                      WHERE id=%d",
                    $master, substr($now, 0, 10), $now, self::SERIES_FULL, $row_id
                ));

                $user = get_userdata($user_id);
                if ($user && !empty($user->user_email)) {
                    $wpdb->delete($t_open, ['user_email' => $user->user_email], ['%s']);
                }

                do_action('bmf_pillars_results_finalized', $user_id);
            }
        }

        /** Force finalization check (useful after batch imports or manual triggers) */
        public static function finalize_if_complete($user_id) {
            $row_id = self::get_open_row_id($user_id);
            if ($row_id) {
                self::maybe_finalize($user_id, $row_id);
            }
        }

        /**
         * Map a form-17 section / interpreter label onto a pillar column.
         * Longest token first so "environmental" is not stolen by "mental",
         * and "environment" (Rapid section title) maps to environmental.
         */
        protected static function title_to_pillar($title) {
            $t = strtolower(trim((string) $title));
            $t = preg_replace('/[^a-z]/', '', $t);
            if ($t === '') {
                return null;
            }

            $needles = [
                'environmental' => 'environmental',
                'environment'   => 'environmental',
                'occupational'  => 'occupational',
                'occupation'    => 'occupational',
                'emotional'     => 'emotional',
                'financial'     => 'financial',
                'spiritual'     => 'spiritual',
                'physical'      => 'physical',
                'mental'        => 'mental',
                'social'        => 'social',
            ];
            uksort($needles, function ($a, $b) {
                return strlen($b) - strlen($a);
            });

            foreach ($needles as $needle => $col) {
                if ($t === $needle || strpos($t, $needle) === 0 || strpos($t, $needle) !== false) {
                    return $col;
                }
            }
            return null;
        }

        /**
         * Persist a Rapid (form 17) completion as its own finalized series row.
         * Never writes into the paid 18-25 open row.
         */
        public static function save_rapid_from_response($user_id, $response_id, $interp = null) {
            global $wpdb;

            $user_id     = (int) $user_id;
            $response_id = (int) $response_id;
            if ($response_id <= 0) {
                return 0;
            }

            if (class_exists('BMF_Repository')) {
                BMF_Repository::maybe_upgrade_pillars_schema();
            }

            $user = $user_id ? get_userdata($user_id) : null;
            $email = ($user && !empty($user->user_email)) ? $user->user_email : '';

            $p          = $wpdb->prefix;
            $t_res      = $p . 'bm_pillars_results';
            $t_responses= $p . 'bm_responses';
            $t_sections = $p . 'bm_form_sections';
            $t_scores   = $p . 'bm_section_scores';
            $t_items    = $p . 'bm_response_items';
            $t_qs       = $p . 'bm_questions';

            $resp = $wpdb->get_row($wpdb->prepare(
                "SELECT r.id, r.user_id, r.form_id, r.submitted_at, u.user_email
                   FROM {$t_responses} r
                   LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                  WHERE r.id = %d
                  LIMIT 1",
                $response_id
            ), ARRAY_A);

            if (!$resp) {
                return 0;
            }

            if ($email === '' && !empty($resp['user_email'])) {
                $email = $resp['user_email'];
            }
            if ($user_id <= 0 && !empty($resp['user_id'])) {
                $user_id = (int) $resp['user_id'];
            }
            if ($email === '') {
                return 0;
            }

            $form_id = (int) ($resp['form_id'] ?? 0);
            if (!self::is_rapid_form($form_id)) {
                return 0;
            }

            $sections = $wpdb->get_results($wpdb->prepare(
                "SELECT id, title FROM {$t_sections} WHERE form_id = %d",
                $form_id
            ), ARRAY_A);

            $section_map = [];
            foreach ((array) $sections as $s) {
                $section_map[(int) $s['id']] = (string) $s['title'];
            }

            $scores = $wpdb->get_results($wpdb->prepare(
                "SELECT section_id, score FROM {$t_scores} WHERE response_id = %d",
                $response_id
            ), ARRAY_A);

            $values = [];
            foreach ((array) $scores as $row) {
                $sid   = (int) $row['section_id'];
                $title = $section_map[$sid] ?? '';
                if (strtolower(trim($title)) === 'wellness priorities') {
                    continue;
                }
                $col = self::title_to_pillar($title);
                if (!$col) {
                    continue;
                }
                $values[$col] = self::norm100($row['score']);
            }

            if (is_array($interp) && !empty($interp['pillars']) && is_array($interp['pillars'])) {
                foreach ($interp['pillars'] as $p_row) {
                    $col = self::title_to_pillar($p_row['label'] ?? '');
                    if (!$col) {
                        continue;
                    }
                    if (!isset($values[$col])) {
                        $raw = $p_row['percent'] ?? $p_row['score'] ?? null;
                        if ($raw !== null && $raw !== '') {
                            $values[$col] = self::norm100($raw);
                        }
                    }
                }
            }

            $rank = '';
            if (is_array($interp) && !empty($interp['perceived_ranking']) && is_array($interp['perceived_ranking'])) {
                $rank = implode(',', array_map('trim', $interp['perceived_ranking']));
            }
            if ($rank === '') {
                $ranking_raw = $wpdb->get_var($wpdb->prepare(
                    "SELECT ri.choice_value
                       FROM {$t_items} ri
                       JOIN {$t_qs} q ON q.id = ri.question_id
                      WHERE ri.response_id = %d
                        AND q.type = 'rank'
                      LIMIT 1",
                    $response_id
                ));
                if ($ranking_raw) {
                    $rank = implode(',', array_map('trim', explode(',', urldecode((string) $ranking_raw))));
                }
            }

            if (count($values) < 1) {
                if (function_exists('bm_log')) {
                    bm_log('PILLARS RAPID SAVE SKIP | no section scores | response_id=' . $response_id);
                }
                return 0;
            }

            $present = [];
            foreach (self::$pillar_columns as $col) {
                if (isset($values[$col])) {
                    $present[] = $values[$col];
                }
            }
            $master = $present ? round(array_sum($present) / count($present), 2) : null;

            $date = !empty($resp['submitted_at']) ? substr((string) $resp['submitted_at'], 0, 10) : substr(current_time('mysql', 1), 0, 10);
            $now  = current_time('mysql', 1);
            $json = (is_array($interp) && !empty($interp)) ? wp_json_encode($interp) : null;

            $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$t_res}
                  WHERE source_response_id = %d AND series = %s
                  LIMIT 1",
                $response_id, self::SERIES_RAPID
            ));
            if (!$existing_id) {
                $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$t_res}
                      WHERE user_email = %s AND results_date = %s AND series = %s
                      LIMIT 1",
                    $email, $date, self::SERIES_RAPID
                ));
            }

            $payload = [
                'user_email'          => $email,
                'current_flag'        => 0,
                'is_final'            => 1,
                'results_date'        => $date,
                'updated_at'          => $now,
                'series'              => self::SERIES_RAPID,
                'source_form_id'      => $form_id,
                'source_response_id'  => $response_id,
                'rank'                => $rank !== '' ? $rank : null,
                'master_score'        => $master,
                'interpretation_json' => $json,
            ];
            foreach (self::$pillar_columns as $col) {
                $payload[$col] = $values[$col] ?? null;
            }

            $formats = [];
            foreach ($payload as $k => $v) {
                if (in_array($k, self::$pillar_columns, true) || $k === 'master_score') {
                    $formats[] = '%f';
                } elseif (in_array($k, ['current_flag','is_final','source_form_id','source_response_id'], true)) {
                    $formats[] = '%d';
                } else {
                    $formats[] = '%s';
                }
            }

            if ($existing_id) {
                $wpdb->update($t_res, $payload, ['id' => $existing_id], $formats, ['%d']);
                $row_id = $existing_id;
            } else {
                $ok = $wpdb->insert($t_res, $payload, $formats);
                if (!$ok) {
                    if (function_exists('bm_log')) {
                        bm_log('PILLARS RAPID SAVE FAIL | ' . $wpdb->last_error);
                    }
                    return 0;
                }
                $row_id = (int) $wpdb->insert_id;
            }

            if (function_exists('bm_log')) {
                bm_log('PILLARS RAPID SAVE | response_id=' . $response_id . ' | row_id=' . $row_id . ' | date=' . $date);
            }

            do_action('bmf_pillars_rapid_saved', $user_id, $row_id, $response_id);
            return $row_id;
        }

        /**
         * Write / refresh rapid rows for already-submitted form 17 responses.
         * Upserts by source_response_id (or email+date), so a forced rerun
         * fills missing columns instead of inserting duplicates.
         *
         * $force=true ignores bmf_pillars_rapid_backfill_done.
         */
        public static function backfill_rapid_rows($force = false) {
            if (!$force && get_option('bmf_pillars_rapid_backfill_done')) {
                return;
            }
            global $wpdb;
            $p = $wpdb->prefix;

            $ids = [self::RAPID_FORM_ID];
            if (class_exists('BMF_Repository')) {
                foreach (self::rapid_slugs() as $slug) {
                    $row = BMF_Repository::get_form_by_slug($slug);
                    if ($row && !empty($row->id)) {
                        $ids[] = (int) $row->id;
                    }
                }
            }
            $ids = array_values(array_unique(array_filter($ids)));
            if (!$ids) {
                update_option('bmf_pillars_rapid_backfill_done', 1, false);
                return;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $responses = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, user_id FROM {$p}bm_responses
                      WHERE form_id IN ({$placeholders})
                        AND status = 'submitted'
                      ORDER BY id ASC",
                    $ids
                ),
                ARRAY_A
            );

            $n = 0;
            foreach ((array) $responses as $r) {
                $rid = (int) $r['id'];
                $uid = (int) $r['user_id'];
                $interp = class_exists('BMF_Interpreter') ? BMF_Interpreter::interpret($rid) : [];
                if (self::save_rapid_from_response($uid, $rid, $interp)) {
                    $n++;
                }
            }

            update_option('bmf_pillars_rapid_backfill_done', 1, false);
            if (function_exists('bm_log')) {
                bm_log('PILLARS RAPID BACKFILL | rows=' . $n);
            }
        }
    }
}
