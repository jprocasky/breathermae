<?php
if (!defined('ABSPATH')) exit;

class BMF_RSI_Saver {

  protected static function norm100($v) {
    $f = (float)$v;
    return ($f <= 1.0) ? round($f * 100, 2) : round($f, 2);
  }

protected static function ensure_open_row($user_id) {
    global $wpdb;

    $t_res  = $wpdb->prefix . 'bm_rsi_results';
    $t_open = $wpdb->prefix . 'bm_rsi_open';

    $user = get_userdata($user_id);
    if (!$user || empty($user->user_email)) return 0;
    $email = $user->user_email;

    $now   = current_time('mysql', 1);
    $today = substr($now, 0, 10);

    // --------------------------------------------------
    // 1) Reuse existing RSI row for (user_email, results_date)
    //    — this matches uniq_user_date constraint
    // --------------------------------------------------
    $row_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id
             FROM {$t_res}
             WHERE user_email = %s
               AND results_date = %s
             LIMIT 1",
            $email,
            $today
        )
    );

    if ($row_id) {
        // Ensure / refresh open-map (idempotent)
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$t_open} (user_email, row_id)
                 VALUES (%s, %d)
                 ON DUPLICATE KEY UPDATE row_id = VALUES(row_id)",
                $email,
                $row_id
            )
        );
        return $row_id;
    }

    // --------------------------------------------------
    // 2) No row yet for today → create it
    // --------------------------------------------------
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

    // --------------------------------------------------
    // 3) Create / refresh open mapping
    // --------------------------------------------------
    $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO {$t_open} (user_email, row_id)
             VALUES (%s, %d)
             ON DUPLICATE KEY UPDATE row_id = VALUES(row_id)",
            $email,
            $row_id
        )
    );

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
   */
  public static function save_domain($user_id, $domain, $val) {
    if (!in_array($domain, ['R11','R12'], true)) return false;

    global $wpdb; $t_res = $wpdb->prefix.'bm_rsi_results'; $t_open = $wpdb->prefix.'bm_rsi_open';
    $user = get_userdata($user_id); if (!$user || empty($user->user_email)) return false;
    $email = $user->user_email;

    $row_id = self::ensure_open_row($user_id);
    if (!$row_id) return false;

    $norm = self::norm100($val);
    $wpdb->query($wpdb->prepare(
      "UPDATE {$t_res} SET {$domain}=%f, updated_at=NOW() WHERE id=%d AND user_email=%s AND current_flag=1",
      $norm, $row_id, $email
    ));

    // Check if both domains present -> finalize
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT R11, R12 FROM {$t_res} WHERE id=%d LIMIT 1", $row_id
    ), ARRAY_A);
    if (!$row) return true;

    $haveR11 = isset($row['R11']) && $row['R11'] !== null && $row['R11'] !== '';
    $haveR12 = isset($row['R12']) && $row['R12'] !== null && $row['R12'] !== '';
    if ($haveR11 && $haveR12) {
      $wpdb->query($wpdb->prepare(
        "UPDATE {$t_res}
         SET is_final=1, current_flag=0, updated_at=NOW()
         WHERE id=%d", $row_id
      ));
      $wpdb->delete($t_open, ['user_email'=>$email], ['%s']);
      do_action('bmf_rsi_results_finalized', $user_id);
    }
    return true;
  }
}