<?php
/**
 * Breathermae – BSI Saver (single open row per user via mapping table)
 *
 * This helper consolidates all in‑progress pillars (F1..F9) for a user into
 * a single OPEN row in {prefix}bm_bsi_results, referenced by
 * {prefix}bm_bsi_open (user_email -> row_id).
 *
 * Usage:
 *   require_once __DIR__ . '/class-bmf-bsi-saver.php';
 *   BMF_BSI_Saver::save_pillar( $user_id, $form_id, $pillar_score );
 *
 * Assumptions:
 *   - Table {prefix}bm_bsi_results has columns:
 *       id (PK, BIGINT AUTO_INCREMENT), user_email (VARCHAR),
 *       current_flag TINYINT(1) DEFAULT 0,
 *       is_final TINYINT(1) DEFAULT 0,
 *       results_date DATETIME NULL,
 *       updated_at DATETIME NULL,
 *       F1..F9 DECIMAL/DOUBLE NULL,
 *       final_sci_score DECIMAL/DOUBLE NULL
 *   - Mapping table {prefix}bm_bsi_open (user_email PK, row_id UNIQUE, FK->results.id)
 *   - Form index (1..9) can be resolved via BMF_BSI_FormId_Resolver::get_form_index($form_id)
 */

if ( ! defined('ABSPATH') ) { exit; }

if ( ! class_exists('BMF_BSI_Saver') ) {
    class BMF_BSI_Saver {
        /** Normalize score to 0..100 */
        protected static function norm( $val ) {
            $v = (float) $val;
            return ($v <= 1.0) ? round($v * 100, 2) : round($v, 2);
        }

        /** Return row_id of the single OPEN row for this user, or 0 if none. */
        public static function get_open_row_id( $user_id ) {
            global $wpdb; $t_open = $wpdb->prefix . 'bm_bsi_open';
            $user = get_userdata( $user_id ); if ( ! $user || empty($user->user_email) ) return 0;
            $email = $user->user_email;
            $row_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT row_id FROM {$t_open} WHERE user_email = %s LIMIT 1", $email
            ) );
            return $row_id ?: 0;
        }

        /** Ensure an OPEN row exists; return its row_id. Creates one if missing. */
        public static function ensure_open_row( $user_id ) {
            global $wpdb; $t_res = $wpdb->prefix . 'bm_bsi_results'; $t_open = $wpdb->prefix . 'bm_bsi_open';
            $user = get_userdata( $user_id ); if ( ! $user || empty($user->user_email) ) return 0;
            $email = $user->user_email;

            bm_log(__METHOD__ . ' ENTER | user_id=' . (int)$user_id . ' | email=' . $email);

            // 1) Do we already have a mapping?
            $row_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT row_id FROM {$t_open} WHERE user_email = %s LIMIT 1", $email
            ) );

            bm_log(__METHOD__ . ' OPEN LOOKUP | email=' . $email . ' | existing_row_id=' . $row_id);

            if ( $row_id ) {
                // Double-check row still exists; FK should guarantee this, but be defensive
                $exists = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(1) FROM {$t_res} WHERE id = %d AND user_email = %s", $row_id, $email
                ) );
                if ( $exists ) return $row_id; // good to use
                // mapping exists but row missing -> remove mapping and proceed to create
                $wpdb->delete( $t_open, [ 'user_email' => $email ], [ '%s' ] );
                $row_id = 0;
            }

            // 2) Create a new OPEN row
            $now = current_time( 'mysql', 1 );
            $ins = $wpdb->insert( $t_res, [
                'user_email'   => $email,
                'current_flag' => 1,
                'is_final'     => 0,
                'results_date' => $now,
                'updated_at'   => $now,
            ], [ '%s','%d','%d','%s','%s' ] );
            if ( ! $ins ) return 0;
            $row_id = (int) $wpdb->insert_id;


            if ( ! $ins ) {
                bm_log(__METHOD__ . 
                    ' INSERT FAILED | user_email=' . $email .
                    ' | results_date=' . $now .
                    ' | mysql_error=' . $wpdb->last_error
                );
                return 0;
            }


            // 3) Create/refresh mapping (last writer wins safely)
            $affected = $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$t_open} (user_email, row_id) VALUES (%s, %d)
                ON DUPLICATE KEY UPDATE row_id = VALUES(row_id)",
                $email, $row_id
            ) );

            if ( $affected === false ) {
                bm_log(__METHOD__ .
                    ' OPEN MAP INSERT FAILED | user_email=' . $email .
                    ' | row_id=' . $row_id .
                    ' | mysql_error=' . $wpdb->last_error
                );
            } else {
                bm_log(__METHOD__ .
                    ' OPEN MAP OK | user_email=' . $email .
                    ' | row_id=' . $row_id .
                    ' | affected_rows=' . $affected
                );
            }

            return $row_id;
        }

        /** Compute overall from pillars; prefer external scorer if available. */
        protected static function compute_overall_from_pillars( array $pillars ) {
            // $pillars should be normalized 0..100 values (non-null) for F1..F9
            if ( class_exists('BMF_BSI_Scorer') ) {
                // Accept either a flat array or an associative snapshot
                if ( method_exists('BMF_BSI_Scorer','compute_overall_from_pillars') ) {
                    // Build snapshot-like structure
                    $snap = [
                        'F1'=>null,'F2'=>null,'F3'=>null,'F4'=>null,'F5'=>null,'F6'=>null,'F7'=>null,'F8'=>null,'F9'=>null
                    ];
                    foreach ( $pillars as $k => $v ) {
                        $kk = is_int($k) ? ('F'.(int)$k) : $k; $snap[$kk] = $v;
                    }
                    $ov = (float) BMF_BSI_Scorer::compute_overall_from_pillars( $snap );
                    return $ov;
                }
            }
            // Fallback: arithmetic mean of available
            $vals = array_filter( array_map( 'floatval', $pillars ), function($x){ return $x !== null; } );
            if ( empty($vals) ) return null;
            $mean = array_sum($vals) / count($vals);
            return max(0, min(100, $mean));
        }

        /** Save a pillar score into the single open row; finalize if all 9 present. */
        public static function save_pillar( $user_id, $form_id, $raw_score ) {
            global $wpdb; $t_res = $wpdb->prefix . 'bm_bsi_results';
            $user = get_userdata( $user_id ); if ( ! $user || empty($user->user_email) ) return false;
            $email = $user->user_email;

            bm_log(__METHOD__ .
                ' ENTER | user_id=' . (int)$user_id .
                ' | form_id=' . (int)$form_id .
                ' | raw_score=' . (string)$raw_score
            );

            // Resolve pillar index 1..9
            if ( ! class_exists('BMF_BSI_FormId_Resolver') || ! method_exists('BMF_BSI_FormId_Resolver','get_form_index') ) {
                bm_log(__METHOD__ . ' ABORT | missing FormId_Resolver');
                return false;
            }

            $idx = (int) BMF_BSI_FormId_Resolver::get_form_index( $form_id );
            
            if ( $idx < 1 || $idx > 9 ) {
                bm_log(__METHOD__ . ' ABORT | invalid pillar index | idx=' . $idx);
                return false;
            }


            $col = 'F' . $idx;
            $norm = max(0.0, min(1.0, (float) $raw_score));

            bm_log(__METHOD__ .
                ' ENSURE OPEN ROW | user_email=' . $email .
                ' | pillar=' . $col .
                ' | norm_score=' . $norm
            );

            // Ensure there is a single open row
            $row_id = self::ensure_open_row( $user_id );
            if ( ! $row_id ) {
                bm_log(__METHOD__ .
                    ' ABORT | ensure_open_row failed | user_email=' . $email
                );
                return false;
            }

            // Update the pillar
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$t_res}
                    SET {$col} = %f,
                        updated_at = NOW()
                  WHERE id = %d AND user_email = %s AND current_flag = 1",
                $norm, $row_id, $email
            ) );

            if ( $wpdb->last_error ) {
                bm_log(__METHOD__ .
                    ' PILLAR UPDATE FAILED | row_id=' . $row_id .
                    ' | column=' . $col .
                    ' | mysql_error=' . $wpdb->last_error
                );
            } else {
                bm_log(__METHOD__ .
                    ' PILLAR UPDATE OK | row_id=' . $row_id .
                    ' | column=' . $col .
                    ' | value=' . $norm
                );
            }

            // Refresh the row to check completeness
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT F1,F2,F3,F4,F5,F6,F7,F8,F9 FROM {$t_res} WHERE id = %d LIMIT 1",
                $row_id
            ), ARRAY_A );
            if ( ! $row ) return true; // updated but cannot read back; non-fatal

            $have = 0; $pillars = [];
            for ( $i=1; $i<=9; $i++ ) {
                $v = array_key_exists('F'.$i, $row) ? $row['F'.$i] : null;
                if ( $v !== null && $v !== '' ) {
                    $vv = (float) $v; if ( $vv > 0 ) { $have++; $pillars['F'.$i] = $vv; }
                }
            }

            if ( $have >= 9 ) {
                // All pillars present -> finalize
                $overall = self::compute_overall_from_pillars( $pillars );
                $now = current_time( 'mysql', 1 );
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$t_res}
                        SET is_final = 1,
                            current_flag = 0,
                            final_sci_score = %f,
                            results_date = %s,
                            updated_at = %s
                      WHERE id = %d",
                    (float)$overall, $now, $now, $row_id
                ) );

                // Remove mapping (no longer open)
                $t_open = $wpdb->prefix . 'bm_bsi_open';
                $wpdb->delete( $t_open, [ 'user_email' => $email ], [ '%s' ] );

                /** Invalidate caches / notify downstream listeners */
                do_action( 'bmf_bsi_results_updated', $user_id, $form_id );
            }

            return true;
        }

        /** Force finalization for a user's open row (if all pillars present). */
        public static function finalize_if_complete( $user_id ) {
            global $wpdb; $t_res = $wpdb->prefix . 'bm_bsi_results'; $t_open = $wpdb->prefix . 'bm_bsi_open';
            $user = get_userdata( $user_id ); if ( ! $user || empty($user->user_email) ) return false;
            $email = $user->user_email;

            $row_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT row_id FROM {$t_open} WHERE user_email = %s LIMIT 1", $email
            ) );
            if ( ! $row_id ) return false;

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT F1,F2,F3,F4,F5,F6,F7,F8,F9 FROM {$t_res} WHERE id = %d LIMIT 1",
                $row_id
            ), ARRAY_A );
            if ( ! $row ) return false;

            $have = 0; $pillars = [];
            for ( $i=1; $i<=9; $i++ ) {
                $v = array_key_exists('F'.$i, $row) ? $row['F'.$i] : null;
                if ( $v !== null && $v !== '' ) { $vv = (float) $v; if ( $vv > 0 ) { $have++; $pillars['F'.$i] = $vv; } }
            }
            if ( $have < 9 ) return false; // not complete

            $overall = self::compute_overall_from_pillars( $pillars );
            $now = current_time( 'mysql', 1 );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$t_res}
                    SET is_final = 1,
                        current_flag = 0,
                        final_sci_score = %f,
                        results_date = %s,
                        updated_at = %s
                  WHERE id = %d",
                (float)$overall, $now, $now, $row_id
            ) );
            $wpdb->delete( $t_open, [ 'user_email' => $email ], [ '%s' ] );
            do_action( 'bmf_bsi_results_updated', $user_id, 0 );
            return true;
        }
    }
}
