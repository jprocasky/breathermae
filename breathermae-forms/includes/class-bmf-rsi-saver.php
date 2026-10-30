<?php
if (!defined('ABSPATH')) exit;

/**
 * RSI Saver — single open row per user via mapping table.
 *
 * Lifecycle matches BSI and Pillars:
 *   - One OPEN row per user, tracked in {prefix}bm_rsi_open (user_email → row_id).
 *   - Incomplete assessments may span multiple days; date is NOT used to locate the open row.
 *   - On finalize (both R11 and R12 present): is_final=1, current_flag=0,
 *     results_date stamped to completion day, open mapping removed.
 *
 * Unique key uniq_user_date (user_email, results_date) remains for finalized rows.
 */
class BMF_RSI_Saver {

  protected static function norm100($v) {
    $f = (float)$v;
    return ($f <= 1.0) ? round($f * 100, 2) : round($f, 2);
  }

  /** Return row_id of the single OPEN row for this user, or 0 if none. */
  public static function get_open_row_id($user_id) {
    global $wpdb;
    $t_open = $wpdb->prefix . 'bm_rsi_open';
    $user = get_userdata($user_id);
    if (!$user || empty($user->user_email)) return 0;
    $email = $user->user_email;
    $row_id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT row_id FROM {$t_open} WHERE user_email = %s LIMIT 1",
      $email
    ));
    return $row_id ?: 0;
  }

  /**
   * Ensure a single OPEN row exists for the user; return its row_id.
   * Looks up the open map first (not results_date). Creates only when no open row exists.
   */
  public static function ensure_open_row($user_id) {
    global $wpdb;

    $t_res  = $wpdb->prefix . 'bm_rsi_results';
    $t_open = $wpdb->prefix . 'bm_rsi_open';

    $user = get_userdata($user_id);
    if (!$user || empty($user->user_email)) return 0;
    $email = $user->user_email;

    // 1) Reuse existing open mapping if the target row is still present
    $row_id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT row_id FROM {$t_open} WHERE user_email = %s LIMIT 1",
      $email
    ));

    if ($row_id) {
      $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(1) FROM {$t_res} WHERE id = %d AND user_email = %s AND current_flag = 1",
        $row_id,
        $email
      ));
      if ($exists) {
        return $row_id;
      }
      // Mapping points at a missing or already-finalized row — drop it and create fresh
      $wpdb->delete($t_open, ['user_email' => $email], ['%s']);
      $row_id = 0;
    }

    // 2) No open row → create one. results_date is a placeholder until finalize.
    $now   = current_time('mysql', 1);
    $today = substr($now, 0, 10);

    $ins = $wpdb->insert(
      $t_res,
      [
        'user_email'   => $email,
        'current_flag' => 1,
        'is_final'     => 0,
        'results_date' => $today,
        'updated_at'   => $now,
      ],
      ['%s', '%d', '%d', '%s', '%s']
    );

    if (!$ins) return 0;

    $row_id = (int) $wpdb->insert_id;

    // 3) Create / refresh open mapping
    $wpdb->query($wpdb->prepare(
      "INSERT INTO {$t_open} (user_email, row_id)
       VALUES (%s, %d)
       ON DUPLICATE KEY UPDATE row_id = VALUES(row_id)",
      $email,
      $row_id
    ));

    return $row_id;
  }

  public static function save_extra($user_id, $field, $val) {
      global $wpdb;
      $t_res = $wpdb->prefix.'bm_rsi_results';
      $user = get_userdata($user_id);
      if (!$user || empty($user->user_email)) return false;
      $row_id = self::ensure_open_row($user_id);
      if (!$row_id) return false;
      $norm = self::norm100($val);
      $wpdb->query($wpdb->prepare(
          "UPDATE {$t_res} SET {$field}=%f, updated_at=NOW() WHERE id=%d AND user_email=%s AND current_flag=1",
          $norm, $row_id, $user->user_email
      ));
      return true;
  }

  /**
   * Save an RSI domain into the open row. $domain = 'R11' or 'R12'; $val is 0..1 or 0..100.
   * Finalizes (and stamps results_date to today) when both domains are present.
   */
  public static function save_domain($user_id, $domain, $val) {
    if (!in_array($domain, ['R11','R12'], true)) return false;

    global $wpdb;
    $t_res  = $wpdb->prefix . 'bm_rsi_results';
    $t_open = $wpdb->prefix . 'bm_rsi_open';
    $user = get_userdata($user_id);
    if (!$user || empty($user->user_email)) return false;
    $email = $user->user_email;

    $row_id = self::ensure_open_row($user_id);
    if (!$row_id) return false;

    $norm = self::norm100($val);
    $wpdb->query($wpdb->prepare(
      "UPDATE {$t_res} SET {$domain}=%f, updated_at=NOW() WHERE id=%d AND user_email=%s AND current_flag=1",
      $norm, $row_id, $email
    ));

    // Check if both domains present → finalize
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT R11, R12 FROM {$t_res} WHERE id=%d LIMIT 1",
      $row_id
    ), ARRAY_A);
    if (!$row) return true;

    $haveR11 = isset($row['R11']) && $row['R11'] !== null && $row['R11'] !== '';
    $haveR12 = isset($row['R12']) && $row['R12'] !== null && $row['R12'] !== '';
    if ($haveR11 && $haveR12) {
      $now   = current_time('mysql', 1);
      $today = substr($now, 0, 10);
      $wpdb->query($wpdb->prepare(
        "UPDATE {$t_res}
         SET is_final=1, current_flag=0, results_date=%s, updated_at=%s
         WHERE id=%d",
        $today, $now, $row_id
      ));
      $wpdb->delete($t_open, ['user_email' => $email], ['%s']);
      do_action('bmf_rsi_results_finalized', $user_id);
    }
    return true;
  }
}
