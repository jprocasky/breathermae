<?php
/**
 * Plugin Name: ULS CF Forms — Per-form Tables with Locking + Prefill (PHP + JS)
 * Description: Elementor Pro forms whose Form Name starts with "ULS_CF_": per-form tables, lock/unlock, update or insert; supports shortcodes for single-value defaults AND auto-prefill via render filters when no editor default is set. Adds JS fallback to reliably prefill all fields after widget render and popup show.
 * Version: 0.9.8
 * Author: Jeff Procasky
 * License: GPL-2.0-or-later
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** -------------------------------
 * CONSTANTS & DEBUG
 * ------------------------------- */
define( 'ULS_CF_PREFIX', 'ULS_CF_' );          // Elementor Form Name must start with this
define( 'ULS_CF_TABLE_PREFIX', 'ulscf_' );     // legacy table prefix (kept for fallback reads)
define( 'ULS_CF_VERSION', '0.9.8' );
if ( ! defined( 'ULS_CF_DEBUG' ) ) define( 'ULS_CF_DEBUG', false );         // error_log traces
if ( ! defined( 'ULS_CF_FORCE_PREFILL' ) ) define( 'ULS_CF_FORCE_PREFILL', false ); // testing flag
function ulscf_dbg( $msg ) { if ( ULS_CF_DEBUG ) error_log( '[ULS_CF] ' . $msg ); }

/** -------------------------------
 * HELPERS
 * ------------------------------- */
function ulscf_slug( $s ) {
  $s = strtolower( (string) $s );
  $s = preg_replace( '/[^a-z0-9_]+/', '_' , $s );
  return trim( $s, '_' );
}

/** Prefer custom_id, then id/_id; normalize everywhere (save + render). */
function ulscf_pick_field_id( $array_key = null, $field_item = array() ) {
  if ( ! empty( $array_key ) ) return $array_key;
  if ( is_array( $field_item ) ) {
    if ( ! empty( $field_item['custom_id'] ) ) return $field_item['custom_id'];
    if ( ! empty( $field_item['id'] ) )       return $field_item['id'];
    if ( ! empty( $field_item['_id'] ) )      return $field_item['_id']; // render items often have this
    if ( ! empty( $field_item['title'] ) )    return $field_item['title'];
  }
  return 'field';
}

function ulscf_sql_type_for_field( $type ) {
  switch ( $type ) {
    case 'number': return 'DECIMAL(18,6)';
    case 'date':   return 'DATE';
    case 'time':   return 'TIME';
    default:       return 'TEXT';
  }
}

/**
 * Table name helpers:
 * - New scheme: wp_prefix + exact Elementor form name (e.g., ULS_ULS_CF_BIO)
 * - Legacy scheme: wp_prefix + 'ulscf_' + slug(lowercase form name) (e.g., ULS_ulscf_uls_cf_bio)
 */
function ulscf_table_name_new( $form_name ) {
  global $wpdb;
  return $wpdb->prefix . (string) $form_name; // EXACT form name appended to site prefix
}
function ulscf_table_name_legacy( $form_name ) {
  global $wpdb;
  return $wpdb->prefix . ULS_CF_TABLE_PREFIX . ulscf_slug( $form_name );
}
function ulscf_table_exists( $table_name ) {
  global $wpdb;
  $exists = $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
    $table_name
  ) );
  return intval( $exists ) > 0;
}
/** Resolve table name: prefer new scheme, else fallback to legacy if present, else new for create. */
function ulscf_resolve_table_name( $form_name ) {
  $t_new    = ulscf_table_name_new( $form_name );
  $t_legacy = ulscf_table_name_legacy( $form_name );
  if ( ulscf_table_exists( $t_new ) )    return $t_new;
  if ( ulscf_table_exists( $t_legacy ) ) return $t_legacy;
  return $t_new;
}

/** Create/evolve the per-form table via dbDelta(). */
function ulscf_ensure_table( $form_name, $fields_raw ) {
  global $wpdb;
  $table_name = ulscf_table_name_new( $form_name ); // always create/upgrade new scheme

    if ( ulscf_table_exists( $table_name ) ) {
        return; // ✅ Do NOT run dbDelta again
    }

  $cols = array(
    "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT",
    "email VARCHAR(191) NOT NULL",
    "user_id BIGINT(20) UNSIGNED NULL",
    "locked TINYINT(1) NOT NULL DEFAULT 0",
    "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    "updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
  );
  foreach ( $fields_raw as $id => $field ) {
    $fid  = ulscf_pick_field_id( $id, $field );
    $col  = 'f_' . ulscf_slug( $fid );
    $type = ulscf_sql_type_for_field( isset( $field['type'] ) ? $field['type'] : 'text' );
    $cols[] = $col . ' ' . $type . ' NULL';
  }

  $keys = array(
    "PRIMARY KEY (id)",
    "KEY email (email)",
    "KEY locked (locked)",
  );

  $charset_collate = $wpdb->get_charset_collate();
  $sql = "CREATE TABLE {$table_name} (\n " . implode(",\n ", $cols) . ",\n " . implode(",\n ", $keys) . "\n) {$charset_collate};";

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta( $sql );
}

/** Arrays → newline-joined string (legacy-compatible storage). */
function ulscf_normalize_value( $val ) {
  if ( is_array( $val ) ) return implode( "\n", array_map( 'strval', $val ) );
  return (string) $val;
}

function ulscf_should_insert_new( $fields_raw, $latest_row ) {
  if ( isset( $_GET['entry_mode'] ) && 'new' === strtolower( sanitize_text_field( $_GET['entry_mode'] ) ) ) return true;
  if ( isset( $fields_raw['entry_mode']['value'] ) && 'new' === strtolower( $fields_raw['entry_mode']['value'] ) ) return true;
  if ( $latest_row && intval( $latest_row->locked ) === 1 ) return true;
  return false;
}

function ulscf_get_latest_row( $table_name, $email, $only_unlocked = false ) {
  global $wpdb;
  $where = $only_unlocked ? "AND locked = 0" : "";
  $sql   = "SELECT * FROM $table_name WHERE email = %s $where ORDER BY id DESC LIMIT 1";
  return $wpdb->get_row( $wpdb->prepare( $sql, $email ) );
}

/**
 * Detect if the editor has set any default for the field.
 * We consider default present if 'default_value' or 'field_value' is non-empty.
 * If ULS_CF_FORCE_PREFILL is true, always return false (force prefill).
 */
function ulscf_has_editor_default( $item ) {
  if ( ULS_CF_FORCE_PREFILL ) return false;
  if ( ! is_array( $item ) ) return false;

  $dv = '';
  if ( array_key_exists( 'default_value', $item ) && $item['default_value'] !== null ) {
    $dv = is_array( $item['default_value'] ) ? implode( "\n", $item['default_value'] ) : (string) $item['default_value'];
  }
  $fv = '';
  if ( array_key_exists( 'field_value', $item ) && $item['field_value'] !== null ) {
    $fv = is_array( $item['field_value'] ) ? implode( "\n", $item['field_value'] ) : (string) $item['field_value'];
  }
  return ( trim( $dv ) !== '' || trim( $fv ) !== '' );
}

/**
 * Fetch latest unlocked value for a specific field column and email.
 * Returns array (for multi) or string; returns null when locked or not found.
 */
function ulscf_get_latest_field_value( $form_name, $fid, $email ) {
  global $wpdb;
  $table_name = ulscf_resolve_table_name( $form_name );
  $col        = 'f_' . ulscf_slug( $fid );

  $col_exists = $wpdb->get_var( $wpdb->prepare(
    "SHOW COLUMNS FROM $table_name LIKE %s",
    $col
  ) );
  if ( ! $col_exists ) { ulscf_dbg( "no column: $table_name.$col" ); return null; }

  $row = $wpdb->get_row( $wpdb->prepare(
    "SELECT locked, $col AS value FROM $table_name WHERE email = %s ORDER BY id DESC LIMIT 1",
    $email
  ) );
  if ( ! $row ) { ulscf_dbg( "no row for $email in $table_name" ); return null; }
  if ( intval( $row->locked ) === 1 ) { ulscf_dbg( "row locked for $email" ); return null; }

  $v = (string) ( $row->value ?? '' );
  if ( $v === '' ) return null;

  // DELIMITER TOLERANCE: pipe |, newline \n, comma ,
  if ( strpos( $v, '|' ) !== false ) return array_map( 'trim', explode( '|', $v ) );
  if ( strpos( $v, "\n" ) !== false ) return array_map( 'trim', explode( "\n", $v ) );
  if ( strpos( $v, ','  ) !== false ) return array_map( 'trim', explode( ',',  $v ) );
  return $v;
}

/**
 * Map stored tokens to actual option VALUES expected by Elementor.
 * Accepts both raw option values and labels; returns an array of values.
 */
function ulscf_map_tokens_to_option_values( $item, $tokens ) {
  if ( ! is_array( $tokens ) ) $tokens = array( (string) $tokens );
  if ( empty( $tokens ) ) return array();

  // Options may be provided in different keys depending on Elementor build.
  $options = array();
  if ( isset( $item['options'] ) && is_array( $item['options'] ) ) {
    $options = $item['options']; // value => label
  } elseif ( isset( $item['field_options'] ) && is_array( $item['field_options'] ) ) {
    $options = $item['field_options']; // some builds use field_options
  }

  if ( empty( $options ) ) {
    // No known options → return tokens as-is (Elementor will ignore unknowns)
    return array_map( 'strval', $tokens );
  }

  // Reverse map: normalized label → value
  $label_to_value = array();
  foreach ( $options as $val => $label ) {
    $label_to_value[ strtolower( trim( (string) $label ) ) ] = (string) $val;
  }

  $out = array();
  foreach ( $tokens as $t ) {
    $t = (string) $t;
    $trim = trim( $t );
    if ( $trim === '' ) continue;

    // 1) Direct value match
    if ( array_key_exists( $trim, $options ) ) {
      $out[] = $trim;
      continue;
    }

    // 2) Label match (case-insensitive)
    $key = strtolower( $trim );
    if ( array_key_exists( $key, $label_to_value ) ) {
      $out[] = $label_to_value[ $key ];
      continue;
    }

    // 3) Fallback: keep as-is (Elementor will ignore if not a valid value)
    $out[] = $trim;
  }

  // Unique, non-empty
  $out = array_values( array_filter( array_unique( $out ), function( $v ){ return $v !== ''; } ) );
  return $out;
}

/**
 * Robust check whether a SELECT field is configured as multiple.
 * Supports Elementor variants: true, 1, '1', 'yes', 'true', 'on', 'multiple'.
 */
function ulscf_is_item_multiple( $item ) {
  foreach ( array( 'multiple', 'allow_multiple', 'is_multiple' ) as $k ) {
    if ( isset( $item[ $k ] ) ) {
      $v = $item[ $k ];
      if ( $v === true || $v === 1 || $v === '1' ) return true;
      if ( is_string( $v ) ) {
        $lv = strtolower( $v );
        if ( in_array( $lv, array( 'true','yes','on','multiple','1' ), true ) ) return true;
      }
    }
  }
  return false;
}

/** -------------------------------
 * SUBMISSION HANDLER
 * ------------------------------- */
add_action(
  'elementor_pro/forms/new_record',
  function( $record, $handler = null, $ajax_handler = null ) {
    try {
      $form_name = (string) $record->get_form_settings( 'form_name' );
      if ( 0 !== strpos( $form_name, ULS_CF_PREFIX ) ) return;

      $fields_raw = $record->get( 'fields' );
      if ( empty( $fields_raw ) || ! is_array( $fields_raw ) ) return;

      $current_user = wp_get_current_user();
      $email = '';
      if ( isset( $fields_raw['email']['value'] ) && is_email( $fields_raw['email']['value'] ) ) {
        $email = sanitize_email( $fields_raw['email']['value'] );
      } elseif ( $current_user && $current_user->user_email ) {
        $email = sanitize_email( $current_user->user_email );
      }
      if ( empty( $email ) ) return;

      // Ensure table (NEW scheme)
      ulscf_ensure_table( $form_name, $fields_raw );

      global $wpdb;
      $table_name = ulscf_resolve_table_name( $form_name );

      $data = array(
        'email'   => $email,
        'user_id' => $current_user ? intval( $current_user->ID ) : null,
      );

      foreach ( $fields_raw as $id => $field ) {
        $fid = ulscf_pick_field_id( $id, $field );
        $col = 'f_' . ulscf_slug( $fid );
        $val = ( isset( $field['raw_value'] ) && is_array( $field['raw_value'] ) )
          ? $field['raw_value']
          : ( isset( $field['value'] ) ? $field['value'] : '' );
        $data[ $col ] = ulscf_normalize_value( $val );
      }

      $latest_any      = ulscf_get_latest_row( $table_name, $email, false );
      $latest_unlocked = ulscf_get_latest_row( $table_name, $email, true );

      if ( ulscf_should_insert_new( $fields_raw, $latest_any ) || ! $latest_unlocked ) {
        $wpdb->insert( $table_name, $data );
      } else {
        $wpdb->update( $table_name, $data, array( 'id' => intval( $latest_unlocked->id ) ) );
      }
    } catch ( \Throwable $e ) {
      error_log( '[ULS_CF] ' . $e->getMessage() );
    }
  },
  10,
  3
);

/** -------------------------------
 * SHORTCODES
 * ------------------------------- */
add_shortcode( 'uls_cf_value', function( $atts ) {
  $atts = shortcode_atts( array(
    'form'    => '',
    'field'   => '',
    'email'   => '',
    'default' => ''
  ), $atts );

  if ( empty( $atts['form'] ) || empty( $atts['field'] ) ) return $atts['default'];

  $email = $atts['email'];
  if ( empty( $email ) ) {
    $u = wp_get_current_user();
    if ( $u && $u->user_email ) $email = $u->user_email;
  }
  if ( empty( $email ) ) return $atts['default'];

  global $wpdb;
  $table_name = ulscf_resolve_table_name( $atts['form'] );
  $col        = 'f_' . ulscf_slug( $atts['field'] );

  $exists = $wpdb->get_var( $wpdb->prepare(
    "SHOW COLUMNS FROM $table_name LIKE %s",
    $col
  ) );
  if ( ! $exists ) return $atts['default'];

  $row = $wpdb->get_row( $wpdb->prepare(
    "SELECT locked, $col AS value FROM $table_name WHERE email = %s ORDER BY id DESC LIMIT 1",
    $email
  ) );
  if ( ! $row || intval( $row->locked ) === 1 ) return $atts['default'];

  if ( is_string( $row->value ) && strpos( $row->value, "\n" ) !== false ) {
    return esc_html( str_replace( "\n", ', ', $row->value ) );
  }
  return ( $row->value !== null && $row->value !== '' ) ? esc_html( $row->value ) : $atts['default'];
} );

add_shortcode( 'uls_cf_lock', function( $atts ) {
  if ( ! current_user_can( 'manage_options' ) ) return '';
  $atts = shortcode_atts( array( 'form'=>'', 'email'=>'', 'state'=>'lock' ), $atts );
  if ( empty( $atts['form'] ) || empty( $atts['email'] ) ) return '';

  global $wpdb;
  $table_name = ulscf_resolve_table_name( $atts['form'] );
  $email      = sanitize_email( $atts['email'] );
  $row        = ulscf_get_latest_row( $table_name, $email, false );
  if ( ! $row ) return 'no-row';

  $locked = ( strtolower( $atts['state'] ) === 'lock' ) ? 1 : 0;
  $wpdb->update( $table_name, array( 'locked' => $locked ), array( 'id' => intval( $row->id ) ) );
  return $locked ? 'locked' : 'unlocked';
} );

add_shortcode( 'uls_cf_status', function( $atts ) {
  $atts = shortcode_atts( array( 'form'=>'', 'email'=>'' ), $atts );
  if ( empty( $atts['form'] ) ) return '';

  $email = $atts['email'];
  if ( empty( $email ) ) {
    $u = wp_get_current_user();
    if ( $u && $u->user_email ) $email = $u->user_email;
  }
  if ( empty( $email ) ) return '';

  global $wpdb;
  $table_name = ulscf_resolve_table_name( $atts['form'] );
  $row        = ulscf_get_latest_row( $table_name, $email, false );
  if ( ! $row ) return 'none';
  return intval( $row->locked ) === 1 ? 'locked' : 'unlocked';
} );

/** -------------------------------
 * FIELD RENDER FILTERS (prefill only when no editor default)
 * ------------------------------- */
/**
 * Generic: prefill single-value text-like fields when no editor default is set.
 * (text, email, tel, url, textarea, hidden, number, date, time, password)
 * NOTE: 'select' and 'radio' removed from generic list to avoid interfering with their dedicated filters.
 */
add_filter( 'elementor_pro/forms/render/item', function( $item, $index, $form ) {
  $form_name = $form->get_settings_for_display( 'form_name' );
  if ( strpos( $form_name, ULS_CF_PREFIX ) !== 0 ) return $item;

  // Guard: $item must be array
  if ( ! is_array( $item ) ) return $item;

  // Respect editor default (including Dynamic Tag/shortcode)
  if ( ulscf_has_editor_default( $item ) ) return $item;

  // Logged-in user's email
  $u = wp_get_current_user();
  $email = ( $u && $u->user_email ) ? $u->user_email : '';
  if ( $email === '' ) return $item;

  // Field ID → column
  $fid = ulscf_pick_field_id( null, $item );
  if ( ! $fid ) return $item;

  // Determine field type; only apply to single-value text-like inputs
  $type = '';
  if ( isset( $item['field_type'] ) ) { $type = $item['field_type']; }
  elseif ( isset( $item['type'] ) )   { $type = $item['type']; }

  $single_types = array( 'text','email','tel','url','textarea','hidden','number','date','time','password' ); // 'select','radio' removed
  if ( ! in_array( $type, $single_types, true ) ) return $item;

  // Pull latest unlocked value
  $val = ulscf_get_latest_field_value( $form_name, $fid, $email );
  if ( $val === null ) return $item;

  $item['field_value'] = is_array( $val ) ? (string) reset( $val ) : $val;
  return $item;
}, 10, 3 );

/**
 * SELECT: prefill single OR multiple when no editor default is set.
 * For multiple, pass **comma-separated string**; for single, pass scalar.
 */
add_filter( 'elementor_pro/forms/render/item/select', function( $item, $index, $form ) {
  $form_name = $form->get_settings_for_display( 'form_name' );
  if ( strpos( $form_name, ULS_CF_PREFIX ) !== 0 ) return $item;
  if ( ! is_array( $item ) ) return $item;
  if ( ulscf_has_editor_default( $item ) ) return $item;

  $u     = wp_get_current_user();
  $email = ( $u && $u->user_email ) ? $u->user_email : '';
  if ( $email === '' ) return $item;

  $fid = ulscf_pick_field_id( null, $item );
  if ( ! $fid ) return $item;

  $val = ulscf_get_latest_field_value( $form_name, $fid, $email );
  if ( $val === null ) return $item;

  $is_multiple = ulscf_is_item_multiple( $item ) || ( is_array( $val ) && count( (array) $val ) > 1 );

  // Normalize tokens → option VALUES
  $tokens = is_array( $val ) ? $val : array( (string) $val );
  $values = ulscf_map_tokens_to_option_values( $item, $tokens );

  if ( $is_multiple ) {
    // Strip empty placeholders like ""
    $values = array_values( array_filter( $values, function($v){ return $v !== ''; } ) );
    // Ensure Elementor treats this as multiple
    $item['multiple']       = true;
    $item['allow_multiple'] = true;
    // IMPORTANT: pass comma-separated string to avoid explode(Array) fatal
    $item['field_value'] = implode( ',', $values );
    // DO NOT set default_value
  } else {
    $item['field_value'] = count( $values ) ? (string) $values[0] : '';
  }

  return $item;
}, 100, 3 );

/**
 * CHECKBOX: prefill checked values when no editor default is set.
 * Pass an array of option values (Elementor handles arrays for checkboxes).
 */
add_filter( 'elementor_pro/forms/render/item/checkbox', function( $item, $index, $form ) {
  $form_name = $form->get_settings_for_display( 'form_name' );
  if ( strpos( $form_name, ULS_CF_PREFIX ) !== 0 ) return $item;
  if ( ! is_array( $item ) ) return $item;
  if ( ulscf_has_editor_default( $item ) ) return $item;

  $u     = wp_get_current_user();
  $email = ( $u && $u->user_email ) ? $u->user_email : '';
  if ( $email === '' ) return $item;

  $fid = ulscf_pick_field_id( null, $item );
  if ( ! $fid ) return $item;

  $val = ulscf_get_latest_field_value( $form_name, $fid, $email );
  if ( $val === null ) return $item;

  $tokens = is_array( $val ) ? $val : array( (string) $val );
  $values = ulscf_map_tokens_to_option_values( $item, $tokens );
  $values = array_values( array_filter( $values, function($v){ return $v !== ''; } ) );

  $item['field_value'] = $values;
  // DO NOT set default_value
  return $item;
}, 100, 3 );

/**
 * RADIO: prefill a single choice when no editor default is set.
 * Maps stored token (label or value) → actual option VALUE and sets scalar field_value.
 */
add_filter( 'elementor_pro/forms/render/item/radio', function( $item, $index, $form ) {
  $form_name = $form->get_settings_for_display( 'form_name' );
  if ( strpos( $form_name, ULS_CF_PREFIX ) !== 0 ) return $item;

  // Guard + respect editor default
  if ( ! is_array( $item ) ) return $item;
  if ( ulscf_has_editor_default( $item ) ) return $item;

  // Logged-in user's email
  $u = wp_get_current_user();
  $email = ( $u && $u->user_email ) ? $u->user_email : '';
  if ( $email === '' ) return $item;

  // Field ID and latest value
  $fid = ulscf_pick_field_id( null, $item );
  if ( ! $fid ) return $item;

  $val = ulscf_get_latest_field_value( $form_name, $fid, $email );
  if ( $val === null ) return $item;

  // Normalize to single token, then map to option VALUE
  $tokens = is_array( $val ) ? $val : array( (string) $val );
  $values = ulscf_map_tokens_to_option_values( $item, $tokens );

  // Pick the first mapped value as scalar; strip empties
  $values = array_values( array_filter( $values, function($v){ return $v !== ''; } ) );
  $item['field_value'] = count($values) ? (string) $values[0] : '';

  return $item;
}, 100, 3 );

/** -------------------------------
 * JS FALLBACK: print JSON payload of latest unlocked values & apply on DOM
 * ------------------------------- */
/** Build payload of all ULS_CF tables for the current user (latest unlocked row values). */
function ulscf_build_prefill_payload_for_user( $email ) {
  if ( empty( $email ) ) return array();

  global $wpdb;
  $payload = array();

  // Enumerate NEW tables: those that exactly match form names starting with ULS_CF_
  $prefix_new = $wpdb->prefix . ULS_CF_PREFIX; // e.g., "ULS_ULS_CF_"
  $tables_new = $wpdb->get_col( $wpdb->prepare(
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s",
    $prefix_new . '%' // match ULS_ULS_CF_*
  ) );

  // Enumerate LEGACY tables: wp_prefix + ulscf_*
  $prefix_legacy = $wpdb->prefix . ULS_CF_TABLE_PREFIX;
  $tables_legacy = $wpdb->get_col( $wpdb->prepare(
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s",
    $prefix_legacy . '%' // match ULS_ulscf_*
  ) );

  // Helper to add values for a table into payload (form_key & f_* columns as arrays)
  $add_table_values = function( $t, $form_key ) use ( $wpdb, $email, &$payload ) {
    $row = $wpdb->get_row( $wpdb->prepare(
      "SELECT * FROM $t WHERE email = %s AND locked = 0 ORDER BY id DESC LIMIT 1",
      $email
    ), ARRAY_A );
    if ( ! $row ) return;
    $payload[ $form_key ] = array();
    foreach ( $row as $col => $val ) {
      if ( strpos( $col, 'f_' ) !== 0 ) continue;
      if ( $val === '' || $val === null ) continue;

      // DELIMITER TOLERANCE for payload arrays
      $vals = ( strpos($val, '|') !== false ) ? array_map('trim', explode('|', $val))
            : ( ( strpos($val, "\n") !== false ) ? array_map('trim', explode("\n", $val))
            : ( ( strpos($val, ',') !== false ) ? array_map('trim', explode(',',  $val))
            : array( trim($val) ) ) );
      $payload[ $form_key ][ $col ] = $vals;
    }
  };

  // Add NEW scheme tables: form_key equals exact form name (suffix after wp prefix)
  foreach ( (array) $tables_new as $t ) {
    $form_key = substr( $t, strlen( $wpdb->prefix ) ); // exact form name (e.g., ULS_CF_BIO)
    $add_table_values( $t, $form_key );
  }

  // Add LEGACY scheme tables: form_key is the suffix after legacy prefix (e.g., uls_cf_bio)
  foreach ( (array) $tables_legacy as $t ) {
    $form_key = substr( $t, strlen( $prefix_legacy ) ); // legacy key (lowercase slug)
    $add_table_values( $t, $form_key );
  }

  return $payload;
}

/** Enqueue tiny JS and print payload to window.ULSCF_PREFILL */
add_action( 'wp_enqueue_scripts', function() {
  if ( ! is_user_logged_in() ) return;
  $u = wp_get_current_user();
  if ( ! $u || ! $u->user_email ) return;

  $payload = ulscf_build_prefill_payload_for_user( $u->user_email );
  wp_register_script( 'uls-cf-prefill', false, array(), ULS_CF_VERSION, true );
  wp_enqueue_script( 'uls-cf-prefill' );
  $json = wp_json_encode( $payload );

  // IMPORTANT: use NOWDOC to avoid PHP variable interpolation inside JS ($scope, $, etc.)
  $js = <<<'JS'
window.ULSCF_PREFILL = __ULS_JSON__;
(function(){
  // Utility: set single-value inputs (text/email/url/number/date/time/textarea/password).
  function setSingle(el, value) {
    if (!el) return;
    if (!el.value) {
      el.value = value || '';
      el.setAttribute('data-uls-prefilled','1');
      el.dispatchEvent(new Event('input', {bubbles:true}));
      el.dispatchEvent(new Event('change', {bubbles:true}));
    }
  }
  // Utility: set select single (by value; fallback to option text).
  function setSelectSingle(sel, value) {
    if (!sel) return;
    if (sel.value) return;
    let matched = sel.querySelector('option[value="'+CSS.escape(String(value))+'"]');
    if (!matched) {
      Array.from(sel.options).some(opt => {
        if (opt.text.trim() === String(value).trim()) { matched = opt; return true; }
        return false;
      });
    }
    if (matched) {
      sel.value = matched.value;
      sel.setAttribute('data-uls-prefilled','1');
      sel.dispatchEvent(new Event('change', {bubbles:true}));
      if (window.jQuery && jQuery(sel).data('select2')) { jQuery(sel).trigger('change.select2'); }
    }
  }
  // Utility: set select multiple (array of values). Always ensure ALL intended are selected.
  function setSelectMultiple(sel, values) {
    if (!sel || !sel.multiple) return;
    const want = Array.from(new Set((values || []).map(v => String(v).trim()).filter(v => v !== '')));
    const opts = Array.from(sel.options);
    const hasAll = want.length > 0 && want.every(w =>
      opts.some(opt => opt.selected && (opt.value === w || opt.text.trim() === w))
    );
    if (!hasAll) {
      opts.forEach(opt => {
        const match = want.includes(opt.value) || want.includes(opt.text.trim());
        opt.selected = match;
      });
      sel.dispatchEvent(new Event('change', {bubbles:true}));
      if (window.jQuery && jQuery(sel).data('select2')) { jQuery(sel).trigger('change.select2'); }
    }
    sel.setAttribute('data-uls-prefilled','1');
  }
  // Checkbox group & radio
  function setChecks(scope, fid, values) {
    const cbs = scope.querySelectorAll('input[type="checkbox"][name="form_fields['+fid+'][]"]');
    if (cbs.length) {
      const want = Array.from(new Set((values || []).map(v => String(v))));
      const hasAll = want.length > 0 && want.every(w =>
        Array.from(cbs).some(cb => cb.checked && cb.value === w)
      );
      if (!hasAll) {
        cbs.forEach(cb => {
          const match = want.includes(cb.value);
          cb.checked = match;
          if (match) cb.setAttribute('data-uls-prefilled','1');
        });
        if (cbs[0]) cbs[0].dispatchEvent(new Event('change', {bubbles:true}));
      }
    }
    const rads = scope.querySelectorAll('input[type="radio"][name="form_fields['+fid+']"]');
    if (rads.length && values && values.length) {
      const target = String(values[0]);
      const hasOne = Array.from(rads).some(r => r.checked && r.value === target);
      if (!hasOne) {
        rads.forEach(r => { r.checked = (r.value === target); if (r.checked) r.setAttribute('data-uls-prefilled','1'); });
        if (rads[0]) rads[0].dispatchEvent(new Event('change', {bubbles:true}));
      }
    }
  }
  // Apply payload to one form scope
  function applyPrefillToScope(scope) {
    if (!window.ULSCF_PREFILL) return;
    Object.keys(window.ULSCF_PREFILL).forEach(function(formKey){
      const fields = window.ULSCF_PREFILL[formKey];
      Object.keys(fields).forEach(function(col){
        const fid = col.replace(/^f_/,'');
        const values = fields[col]; // array
        const first = values[0] || '';
        // Single inputs
        [
          'input[name="form_fields['+fid+']"]',
          'textarea[name="form_fields['+fid+']"]',
          'input[type="date"][name="form_fields['+fid+']"]',
          'input[type="time"][name="form_fields['+fid+']"]',
          'input[type="number"][name="form_fields['+fid+']"]',
          'input[type="email"][name="form_fields['+fid+']"]',
          'input[type="url"][name="form_fields['+fid+']"]',
          'input[type="password"][name="form_fields['+fid+']"]'
        ].forEach(sel => setSingle(scope.querySelector(sel), first));
        // Select single & multiple
        setSelectSingle(scope.querySelector('select[name="form_fields['+fid+']"]'), first);
        setSelectMultiple(scope.querySelector('select[name="form_fields['+fid+'][]"]'), values);
        // Checkbox & radio
        setChecks(scope, fid, values);
      });
    });
  }
  // Robust runner with retries (handles late Select2 init and re-renders).
  function runForScope(scope) {
    applyPrefillToScope(scope);
    [150, 400, 900, 1800, 3200].forEach(ms => setTimeout(() => applyPrefillToScope(scope), ms));
  }
  // Apply to all forms now
  function applyPrefillAll() {
    document.querySelectorAll('form.elementor-form').forEach(runForScope);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyPrefillAll);
  } else {
    applyPrefillAll();
  }
  // Popup open → reapply
  document.addEventListener('elementor/popup/show', applyPrefillAll);
  // Elementor widget-ready hook → apply within form scope
  if (window.jQuery && window.elementorFrontend && elementorFrontend.hooks) {
    jQuery(window).on('elementor/frontend/init', function(){
      elementorFrontend.hooks.addAction('frontend/element_ready/form.default', function($scope){
        const el = $scope && $scope[0] ? $scope[0] : null;
        if (el) runForScope(el);
      });
    });
  }
  // Observe dynamically inserted forms
  const mo = new MutationObserver(function(muts){
    muts.forEach(m => {
      m.addedNodes.forEach(n => {
        if (n.nodeType === 1) {
          if (n.matches && n.matches('form.elementor-form')) {
            runForScope(n);
          } else {
            const f = n.querySelector && n.querySelector('form.elementor-form');
            if (f) runForScope(f);
          }
        }
      });
    });
  });
  mo.observe(document.body, { childList:true, subtree:true });
})();
JS;
  // Inject JSON into the NOWDOC without interpolation issues
  $js = str_replace( '__ULS_JSON__', $json, $js );
  wp_add_inline_script( 'uls-cf-prefill', $js, 'after' );
} );
