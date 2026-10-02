<?php
/**
 * ULS CF viewer — selected-member record panel for ULS_CF_ tables.
 *
 * Shortcode:
 *   [uls_cf_viewer form="ULS_CF_BIO"]
 *   [uls_cf_viewer form="ULS_CF_BIO" mode="both" rows="8" fields="sex,dob" hide="locked"]
 *
 * Listens for document event uls:selected-member { email, user_id }
 * fired by uls-members on row click. Also hydrates from the provider's
 * stored uls_selected_email on first paint.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_enqueue_scripts', 'ulscf_viewer_register_assets' );
add_shortcode( 'uls_cf_viewer', 'ulscf_viewer_shortcode' );
add_action( 'wp_ajax_ulscf_viewer_record', 'ulscf_viewer_ajax_record' );

function ulscf_viewer_register_assets() {
  $base = plugin_dir_url( __FILE__ );
  $ver  = defined( 'ULS_CF_VERSION' ) ? ULS_CF_VERSION : '0.10.0';
  wp_register_style( 'uls-cf-viewer', $base . 'assets/uls-cf-viewer.css', array(), $ver );
  wp_register_script( 'uls-cf-viewer', $base . 'assets/uls-cf-viewer.js', array(), $ver, true );
  wp_localize_script( 'uls-cf-viewer', 'ULS_CF_VIEWER', array(
    'ajaxurl' => admin_url( 'admin-ajax.php' ),
    'action'  => 'ulscf_viewer_record',
    'nonce'   => wp_create_nonce( 'ulscf_viewer' ),
  ) );
}

/**
 * @param array $atts
 * @return string
 */
function ulscf_viewer_shortcode( $atts ) {
  if ( ! is_user_logged_in() ) {
    return '<p class="ulscf-viewer__note">Log in to view this record.</p>';
  }

  $atts = shortcode_atts( array(
    'form'   => '',
    'table'  => '',
    'mode'   => 'latest',
    'fields' => '',
    'hide'   => 'id,user_id',
    'rows'   => 10,
    'title'  => '',
    'empty'  => 'No record for this member.',
    'labels' => '',
  ), $atts, 'uls_cf_viewer' );

  $form = ulscf_viewer_normalize_form( $atts['form'] !== '' ? $atts['form'] : $atts['table'] );
  if ( $form === '' ) {
    return '<p class="ulscf-viewer__note">uls_cf_viewer needs form="ULS_CF_…".</p>';
  }

  $mode = strtolower( (string) $atts['mode'] );
  if ( ! in_array( $mode, array( 'latest', 'history', 'both' ), true ) ) {
    $mode = 'latest';
  }

  wp_enqueue_style( 'uls-cf-viewer' );
  wp_enqueue_script( 'uls-cf-viewer' );

  $uid   = get_current_user_id();
  $email = (string) get_user_meta( $uid, 'uls_selected_email', true );
  $sel   = (int) get_user_meta( $uid, 'uls_selected_user_id', true );

  $title = $atts['title'] !== '' ? $atts['title'] : ulscf_viewer_humanize( preg_replace( '/^ULS_CF_/i', '', $form ) );

  ob_start();
  ?>
  <div class="ulscf-viewer"
    data-form="<?php echo esc_attr( $form ); ?>"
    data-mode="<?php echo esc_attr( $mode ); ?>"
    data-fields="<?php echo esc_attr( $atts['fields'] ); ?>"
    data-hide="<?php echo esc_attr( $atts['hide'] ); ?>"
    data-rows="<?php echo esc_attr( (string) max( 1, (int) $atts['rows'] ) ); ?>"
    data-empty="<?php echo esc_attr( $atts['empty'] ); ?>"
    data-labels="<?php echo esc_attr( $atts['labels'] ); ?>"
    data-email="<?php echo esc_attr( $email ); ?>"
    data-user-id="<?php echo esc_attr( (string) $sel ); ?>">
    <div class="ulscf-viewer__head">
      <h3 class="ulscf-viewer__title"><?php echo esc_html( $title ); ?></h3>
      <span class="ulscf-viewer__who"><?php echo $email !== '' ? esc_html( $email ) : 'No member selected'; ?></span>
    </div>
    <div class="ulscf-viewer__body">
      <p class="ulscf-viewer__note"><?php echo $email !== '' ? 'Loading record…' : 'Select a member to view this record.'; ?></p>
    </div>
  </div>
  <?php
  return ob_get_clean();
}

function ulscf_viewer_ajax_record() {
  check_ajax_referer( 'ulscf_viewer', 'nonce' );
  if ( ! is_user_logged_in() ) {
    wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
  }

  $form = ulscf_viewer_normalize_form( isset( $_POST['form'] ) ? wp_unslash( $_POST['form'] ) : '' );
  if ( $form === '' ) {
    wp_send_json_error( array( 'message' => 'Invalid form' ), 400 );
  }

  $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
  $posted_uid = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
  if ( ! is_email( $email ) && $posted_uid ) {
    $u = get_user_by( 'id', $posted_uid );
    if ( $u ) {
      $email = $u->user_email;
    }
  }
  if ( ! is_email( $email ) ) {
    $email = (string) get_user_meta( get_current_user_id(), 'uls_selected_email', true );
  }
  if ( ! is_email( $email ) ) {
    wp_send_json_success( array(
      'email'   => '',
      'form'    => $form,
      'columns' => array(),
      'latest'  => null,
      'rows'    => array(),
      'empty'   => 'Select a member to view this record.',
    ) );
  }

  if ( ! ulscf_viewer_can_see_email( $email ) ) {
    wp_send_json_error( array( 'message' => 'Not allowed for this member' ), 403 );
  }

  if ( ! function_exists( 'ulscf_resolve_table_name' ) || ! function_exists( 'ulscf_table_exists' ) ) {
    wp_send_json_error( array( 'message' => 'CF forms not loaded' ), 500 );
  }

  $table = ulscf_resolve_table_name( $form );
  if ( ! ulscf_table_exists( $table ) ) {
    wp_send_json_success( array(
      'email'   => $email,
      'form'    => $form,
      'columns' => array(),
      'latest'  => null,
      'rows'    => array(),
      'empty'   => 'No table for this form yet.',
    ) );
  }

  $mode  = isset( $_POST['mode'] ) ? strtolower( sanitize_key( wp_unslash( $_POST['mode'] ) ) ) : 'latest';
  if ( ! in_array( $mode, array( 'latest', 'history', 'both' ), true ) ) {
    $mode = 'latest';
  }
  $limit = isset( $_POST['rows'] ) ? max( 1, min( 50, (int) $_POST['rows'] ) ) : 10;
  if ( $mode === 'latest' ) {
    $limit = 1;
  }

  $requested = ulscf_viewer_csv( isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : '' );
  $hidden    = ulscf_viewer_csv( isset( $_POST['hide'] ) ? wp_unslash( $_POST['hide'] ) : 'id,user_id' );
  $label_map = ulscf_viewer_label_map( isset( $_POST['labels'] ) ? wp_unslash( $_POST['labels'] ) : '' );

  global $wpdb;
  // Table name comes only from ulscf_resolve_table_name(); columns from SHOW COLUMNS.
  $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
  if ( ! is_array( $columns ) ) {
    $columns = array();
  }
  $columns = array_map( 'strval', $columns );
  $wanted  = ulscf_viewer_pick_columns( $columns, $requested, $hidden );

  $rows = $wpdb->get_results(
    $wpdb->prepare( "SELECT * FROM `{$table}` WHERE email = %s ORDER BY id DESC LIMIT %d", $email, $limit ),
    ARRAY_A
  );
  if ( ! is_array( $rows ) ) {
    $rows = array();
  }

  $shaped = array();
  foreach ( $rows as $row ) {
    $shaped[] = ulscf_viewer_shape_row( $row, $wanted );
  }

  $col_meta = array();
  foreach ( $wanted as $col ) {
    $col_meta[] = array(
      'key'   => $col,
      'label' => isset( $label_map[ strtolower( $col ) ] ) ? $label_map[ strtolower( $col ) ] : ulscf_viewer_column_label( $col ),
    );
  }

  wp_send_json_success( array(
    'email'   => $email,
    'form'    => $form,
    'table'   => $table,
    'mode'    => $mode,
    'columns' => $col_meta,
    'latest'  => isset( $shaped[0] ) ? $shaped[0] : null,
    'rows'    => $shaped,
  ) );
}

function ulscf_viewer_normalize_form( $raw ) {
  $raw = trim( (string) $raw );
  if ( $raw === '' ) {
    return '';
  }
  global $wpdb;
  $prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : '';
  if ( $prefix !== '' && stripos( $raw, $prefix ) === 0 ) {
    $raw = substr( $raw, strlen( $prefix ) );
  }
  if ( stripos( $raw, 'ulscf_' ) === 0 ) {
    return '';
  }
  if ( stripos( $raw, 'ULS_CF_' ) !== 0 ) {
    return '';
  }
  if ( ! preg_match( '/^ULS_CF_[A-Za-z0-9_]+$/', $raw ) ) {
    return '';
  }
  return $raw;
}

function ulscf_viewer_csv( $raw ) {
  $parts = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
  return array_values( $parts );
}

function ulscf_viewer_label_map( $raw ) {
  $map = array();
  foreach ( explode( ',', (string) $raw ) as $pair ) {
    $pair = trim( $pair );
    if ( $pair === '' || strpos( $pair, ':' ) === false ) {
      continue;
    }
    list( $k, $v ) = array_map( 'trim', explode( ':', $pair, 2 ) );
    if ( $k === '' || $v === '' ) {
      continue;
    }
    $map[ strtolower( $k ) ] = $v;
    $slug = function_exists( 'ulscf_slug' ) ? ulscf_slug( $k ) : strtolower( $k );
    $map[ 'f_' . $slug ] = $v;
    $map[ $slug ] = $v;
  }
  return $map;
}

function ulscf_viewer_pick_columns( array $existing, array $requested, array $hidden ) {
  $existing_l = array();
  foreach ( $existing as $col ) {
    $existing_l[ strtolower( $col ) ] = $col;
  }
  $hide = array();
  foreach ( $hidden as $h ) {
    $hide[ strtolower( $h ) ] = true;
    $hide[ 'f_' . strtolower( $h ) ] = true;
  }

  $out = array();
  if ( ! empty( $requested ) ) {
    foreach ( $requested as $req ) {
      $key = strtolower( $req );
      $candidates = array( $key, 'f_' . $key );
      if ( function_exists( 'ulscf_slug' ) ) {
        $candidates[] = 'f_' . ulscf_slug( $req );
      }
      foreach ( $candidates as $cand ) {
        if ( isset( $existing_l[ $cand ] ) && empty( $hide[ $cand ] ) ) {
          $out[] = $existing_l[ $cand ];
          break;
        }
      }
    }
    return array_values( array_unique( $out ) );
  }

  foreach ( $existing as $col ) {
    if ( ! empty( $hide[ strtolower( $col ) ] ) ) {
      continue;
    }
    $out[] = $col;
  }
  return $out;
}

function ulscf_viewer_shape_row( array $row, array $wanted ) {
  $out = array();
  $lower = array();
  foreach ( $row as $k => $v ) {
    $lower[ strtolower( (string) $k ) ] = $v;
  }
  foreach ( $wanted as $col ) {
    $val = array_key_exists( strtolower( $col ), $lower ) ? $lower[ strtolower( $col ) ] : '';
    $out[ $col ] = is_scalar( $val ) || $val === null ? (string) $val : '';
  }
  if ( isset( $row['id'] ) ) {
    $out['_id'] = (string) $row['id'];
  }
  return $out;
}

function ulscf_viewer_column_label( $col ) {
  $col = (string) $col;
  if ( stripos( $col, 'f_' ) === 0 ) {
    $col = substr( $col, 2 );
  }
  return ulscf_viewer_humanize( $col );
}

function ulscf_viewer_humanize( $s ) {
  $s = str_replace( array( '_', '-' ), ' ', (string) $s );
  return ucwords( trim( $s ) );
}

function ulscf_viewer_tag_labels( $user_id ) {
  $labels = array();
  if ( function_exists( 'wpf_get_tags' ) ) {
    $tags = wpf_get_tags( $user_id );
    if ( is_array( $tags ) ) {
      foreach ( $tags as $tag ) {
        if ( function_exists( 'wpf_get_tag_label' ) ) {
          $label = wpf_get_tag_label( $tag );
          if ( is_string( $label ) && $label !== '' ) {
            $labels[] = $label;
            continue;
          }
        }
        if ( is_string( $tag ) && $tag !== '' ) {
          $labels[] = $tag;
        }
      }
    }
  }
  return array_values( array_unique( $labels ) );
}

function ulscf_viewer_pattern_matches( $pattern, $label ) {
  $pattern = trim( (string) $pattern );
  $label   = trim( (string) $label );
  if ( $pattern === '' || $label === '' ) {
    return false;
  }
  if ( strcasecmp( $pattern, $label ) === 0 ) {
    return true;
  }
  $quoted = str_replace( '\\*', '.*', preg_quote( $pattern, '/' ) );
  return (bool) preg_match( '/^' . $quoted . '$/i', $label );
}

/**
 * Admin, the member themselves, or a provider whose parent/child tag
 * patterns cover one of the member's WP Fusion tags.
 */
function ulscf_viewer_can_see_email( $email ) {
  if ( ! is_user_logged_in() || ! is_email( $email ) ) {
    return false;
  }
  $me = wp_get_current_user();
  if ( $me && strcasecmp( $me->user_email, $email ) === 0 ) {
    return true;
  }
  if ( current_user_can( 'manage_options' ) ) {
    return true;
  }
  $member = get_user_by( 'email', $email );
  if ( ! $member ) {
    return false;
  }

  $viewer_tags = ulscf_viewer_tag_labels( $me->ID );
  $member_tags = ulscf_viewer_tag_labels( $member->ID );
  if ( empty( $viewer_tags ) || empty( $member_tags ) ) {
    return false;
  }

  global $wpdb;
  $patterns = $viewer_tags;
  $child = $wpdb->get_col( $wpdb->prepare(
    'SELECT child_pattern FROM `uls_parent_child_tags` WHERE parent_tag IN (' . implode( ',', array_fill( 0, count( $viewer_tags ), '%s' ) ) . ')',
    $viewer_tags
  ) );
  $child = array_values( array_filter( array_map( 'trim', (array) $child ) ) );
  $patterns = array_merge( $patterns, $child );
  if ( ! empty( $child ) ) {
    $grand = $wpdb->get_col( $wpdb->prepare(
      'SELECT child_pattern FROM `uls_parent_child_tags` WHERE parent_tag IN (' . implode( ',', array_fill( 0, count( $child ), '%s' ) ) . ')',
      $child
    ) );
    $patterns = array_merge( $patterns, array_map( 'trim', (array) $grand ) );
  }
  foreach ( $viewer_tags as $vt ) {
    if ( preg_match( '/^(SA\d+)/i', $vt, $m ) ) {
      $patterns[] = $m[1] . '*';
    }
  }
  $patterns = array_values( array_unique( array_filter( $patterns ) ) );

  foreach ( $member_tags as $mt ) {
    foreach ( $patterns as $pat ) {
      if ( ulscf_viewer_pattern_matches( $pat, $mt ) ) {
        return true;
      }
    }
  }
  return false;
}
