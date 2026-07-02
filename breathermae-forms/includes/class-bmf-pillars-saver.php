<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('BMF_Pillars_Saver')) {

    class BMF_Pillars_Saver {

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

        /** Normalize score to 0-100 (consistent with most BMF scoring) */
        protected static function norm100($v) {
            $f = (float)$v;
            return ($f <= 1.0) ? round($f * 100, 2) : round($f, 2);
        }

        /** Return row_id of the single OPEN row for this user (or 0) */
        public static function get_open_row_id($user_id) {
            global $wpdb;
            $t_open = $wpdb->prefix . 'bm_pillars_open';
            $user = get_userdata($user_id);
            if (!$user || empty($user->user_email)) return 0;
            $email = $user->user_email;

            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT row_id FROM {$t_open} WHERE user_email = %s LIMIT 1",
                $email
            ));
        }

        /** Ensure a single OPEN row exists for the user. Creates if missing. */
        public static function ensure_open_row($user_id) {
            global $wpdb;
            $t_res  = $wpdb->prefix . 'bm_pillars_results';
            $t_open = $wpdb->prefix . 'bm_pillars_open';

            $user = get_userdata($user_id);
            if (!$user || empty($user->user_email)) return 0;
            $email = $user->user_email;

            // 1) Check for existing open mapping
            $row_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT row_id FROM {$t_open} WHERE user_email = %s LIMIT 1",
                $email
            ));

            if ($row_id) {
                // Verify the row still exists
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(1) FROM {$t_res} WHERE id = %d AND user_email = %s",
                    $row_id, $email
                ));
                if ($exists) return $row_id;

                // Stale mapping — clean it up
                $wpdb->delete($t_open, ['user_email' => $email], ['%s']);
                $row_id = 0;
            }

            // 2) Create new OPEN row
            $now = current_time('mysql', 1);
            $ins = $wpdb->insert($t_res, [
                'user_email'   => $email,
                'current_flag' => 1,
                'is_final'     => 0,
                'results_date' => substr($now, 0, 10), // DATE portion
                'updated_at'   => $now,
            ], ['%s', '%d', '%d', '%s', '%s']);

            if (!$ins) return 0;

            $row_id = (int) $wpdb->insert_id;

            // 3) Create/refresh open mapping
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$t_open} (user_email, row_id)
                 VALUES (%s, %d)
                 ON DUPLICATE KEY UPDATE row_id = VALUES(row_id)",
                $email, $row_id
            ));

            return $row_id;
        }

        /**
         * Save one pillar average (from pivot view) into the open row.
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
                        updated_at = NOW()
                  WHERE id = %d AND user_email = %s AND current_flag = 1",
                $norm, $row_id, $email
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
                "UPDATE {$t_res} SET `rank` = %s,  updated_at = NOW() WHERE id = %d AND user_email = %s AND current_flag = 1", $rank_string, $row_id, $email ));

            self::maybe_finalize($user_id, $row_id);
            return true;
        }

        /** Check if all 8 pillars + rank are present → finalize the row */
        protected static function maybe_finalize($user_id, $row_id) {
            global $wpdb;
            $t_res  = $wpdb->prefix . 'bm_pillars_results';
            $t_open = $wpdb->prefix . 'bm_pillars_open';

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT occupational,social,spiritual,mental,financial,environmental,physical,emotional,'rank' FROM {$t_res} WHERE id=%d LIMIT 1",
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
                    "UPDATE {$t_res} SET is_final=1, current_flag=0, master_score=%f, results_date=%s, updated_at=%s WHERE id=%d",
                    $master, substr($now, 0, 10), $now, $row_id
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
    }
}