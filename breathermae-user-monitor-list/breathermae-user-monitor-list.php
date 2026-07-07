<?php
/**
 * Plugin Name: BreatherMae User Monitor List
 * Plugin URI: https://github.com/jprocasky/breathermae
 * Description: Internal dashboard shortcode [user_monitor_list] for registered users. Shows Username, First/Last Name, Last Visit Date, Last Page Visited (excluding session-expired), switchable IP/Geo from live-user-monitor table, and dynamic WP Fusion status tag columns (e.g. green check for RSI_COMPLETE). Fully configurable via shortcode params. Works great on WP Fusion-protected pages and with Elementor Pro. Complements your existing live-user-monitor and uls-* plugins.
 * Version: 1.0.0
 * Author: Jeff Procasky / BreatherMae
 * Author URI: https://www.breathermae.com
 * License: GPL v2 or later
 * Text Domain: breathermae-user-monitor-list
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * Usage example:
 * [user_monitor_list status_tags="RSI|RSI_COMPLETE, BSI|BSI_COMPLETE, 8Pillars|8_PILLARS_COMPLETE" show_ip="1" show_geo="1" per_page="50"]
 *
 * The status_tags param format: "Label1|TAG_SLUG1, Label2|TAG_SLUG2"
 * Default sort: Last Visit Date DESC
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

class BreatherMae_User_Monitor_List {

  public function __construct() {
    add_shortcode( 'user_monitor_list', array( $this, 'render_shortcode' ) );
    add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
  }

  /**
   * Enqueue dashicons and our custom CSS
   */
  public function enqueue_assets() {
    wp_enqueue_style( 'dashicons' );
    wp_enqueue_style(
      'breathermae-user-monitor-list',
      plugin_dir_url( __FILE__ ) . 'breathermae-user-monitor-list.css',
      array(),
      '1.0.0'
    );
  }

  /**
   * Main shortcode renderer
   */
  public function render_shortcode( $atts ) {
    $atts = shortcode_atts( array(
      'status_tags' => '',
      'show_ip'     => '0',
      'show_geo'    => '0',
      'per_page'    => '50',
      'search'      => '',
    ), $atts, 'user_monitor_list' );

    $status_tags_str = sanitize_text_field( $atts['status_tags'] );
    $show_ip         = (bool) intval( $atts['show_ip'] );
    $show_geo        = (bool) intval( $atts['show_geo'] );
    $per_page        = max( 5, min( 200, intval( $atts['per_page'] ) ) );

    // Parse dynamic status tag columns: "RSI|RSI_COMPLETE,BSI|BSI_COMPLETE"
    $status_columns = array();
    if ( ! empty( $status_tags_str ) ) {
      $pairs = explode( ',', $status_tags_str );
      foreach ( $pairs as $pair ) {
        $parts = array_map( 'trim', explode( '|', $pair ) );
        if ( count( $parts ) === 2 && ! empty( $parts[0] ) && ! empty( $parts[1] ) ) {
          $status_columns[] = array(
            'label' => sanitize_text_field( $parts[0] ),
            'tag'   => sanitize_text_field( $parts[1] ),
          );
        }
      }
    }

    // Interactive params from URL (search + pagination)
    $paged  = isset( $_GET['um_paged'] ) ? max( 1, intval( $_GET['um_paged'] ) ) : 1;
    $search = isset( $_GET['um_search'] ) 
      ? sanitize_text_field( wp_unslash( $_GET['um_search'] ) ) 
      : sanitize_text_field( $atts['search'] );

    $offset = ( $paged - 1 ) * $per_page;

    global $wpdb;

    // ============================================================
    // TABLE CONFIGURATION - ADJUST TO MATCH YOUR live-user-monitor-fixed.php
    // ============================================================
    // Common possibilities based on your repo: wp_live_user_monitor, wp_lum_activity, etc.
    // Check your live-user-monitor-fixed.php for the exact $table_name or CREATE TABLE.
    $live_table = $wpdb->prefix . 'live_user_monitor';

    // Verify table exists
    $table_exists = $wpdb->get_var( 
      $wpdb->prepare( "SHOW TABLES LIKE %s", $live_table ) 
    ) === $live_table;

    if ( ! $table_exists ) {
      return '<div class="notice notice-error" style="padding:12px; border-left:4px solid #dc2626;">
        <p><strong>BreatherMae User Monitor List:</strong> Live user monitor table <code>' . esc_html( $live_table ) . '</code> not found.</p>
        <p>Please edit <code>breathermae-user-monitor-list.php</code> and update <code>$live_table</code> to match your <strong>live-user-monitor-fixed.php</strong> implementation. 
        You can also add IP/Geo columns once the table name is correct.</p>
      </div>';
    }

    // Build search WHERE clause
    $where      = '1=1';
    $where_args = array();

    if ( ! empty( $search ) ) {
      $like = '%' . $wpdb->esc_like( $search ) . '%';
      $where .= " AND ( u.user_login LIKE %s OR u.display_name LIKE %s OR fn.meta_value LIKE %s OR ln.meta_value LIKE %s )";
      $where_args = array( $like, $like, $like, $like );
    }

    // ============================================================
    // MAIN DATA QUERY
    // Gets users + their most recent non-session-expired activity from live monitor table
    // Compatible with MySQL 5.7 / MariaDB (no window functions)
    // ============================================================
    $sql = "
      SELECT 
        u.ID,
        u.user_login,
        u.user_email,
        u.display_name,
        fn.meta_value AS first_name,
        ln.meta_value AS last_name,
        l.last_page,
        l.last_visit,
        l.ip_address,
        l.geo_location
      FROM {$wpdb->users} u
      LEFT JOIN {$wpdb->usermeta} fn 
        ON fn.user_id = u.ID AND fn.meta_key = 'first_name'
      LEFT JOIN {$wpdb->usermeta} ln 
        ON ln.user_id = u.ID AND ln.meta_key = 'last_name'
      LEFT JOIN (
        SELECT 
          l1.user_id,
          l1.last_page,
          l1.timestamp AS last_visit,
          l1.ip_address,
          l1.geo_location
        FROM {$live_table} l1
        INNER JOIN (
          SELECT 
            user_id, 
            MAX(timestamp) AS max_ts
          FROM {$live_table}
          WHERE last_page NOT LIKE '%%session-expired%%'
          GROUP BY user_id
        ) lmax 
          ON l1.user_id = lmax.user_id 
         AND l1.timestamp = lmax.max_ts
      ) l ON l.user_id = u.ID
      WHERE {$where}
      ORDER BY 
        CASE WHEN l.last_visit IS NULL THEN 1 ELSE 0 END,
        l.last_visit DESC,
        u.user_registered DESC
      LIMIT %d, %d
    ";

    $query_args = array_merge( $where_args, array( $offset, $per_page ) );
    $users = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ) );

    if ( $wpdb->last_error ) {
      return '<p style="color:#dc2626;">Database error: ' . esc_html( $wpdb->last_error ) . '</p>';
    }

    // Total count for pagination (simplified)
    $count_sql = "
      SELECT COUNT(DISTINCT u.ID)
      FROM {$wpdb->users} u
      LEFT JOIN {$wpdb->usermeta} fn 
        ON fn.user_id = u.ID AND fn.meta_key = 'first_name'
      LEFT JOIN {$wpdb->usermeta} ln 
        ON ln.user_id = u.ID AND ln.meta_key = 'last_name'
      LEFT JOIN (
        SELECT user_id, MAX(timestamp) AS max_ts
        FROM {$live_table}
        WHERE last_page NOT LIKE '%%session-expired%%'
        GROUP BY user_id
      ) lmax ON u.ID = lmax.user_id
      WHERE {$where}
    ";
    $total_users  = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where_args ) );
    $total_pages  = max( 1, ceil( $total_users / $per_page ) );

    // Check for WP Fusion has_tag support
    $has_wpf = function_exists( 'wp_fusion' ) && 
               isset( wp_fusion()->user ) && 
               method_exists( wp_fusion()->user, 'has_tag' );

    ob_start();
    ?>
    <div class="breathermae-user-monitor">
      <div class="monitor-header">
        <h2>User Monitor Dashboard</h2>
        <div class="monitor-controls">
          <form method="get" action="" class="monitor-search-form">
            <input 
              type="text" 
              name="um_search" 
              value="<?php echo esc_attr( $search ); ?>" 
              placeholder="Search username or name..." 
              style="min-width:220px;"
            />
            <button type="submit" class="button button-secondary">Search</button>
            <?php if ( ! empty( $search ) ) : ?>
              <a href="<?php echo esc_url( remove_query_arg( array( 'um_search', 'um_paged' ) ) ); ?>" class="button">Clear</a>
            <?php endif; ?>
          </form>

          <button 
            type="button" 
            onclick="exportTableToCSV('breathermae-user-monitor-table', 'breathermae-user-monitor-<?php echo date('Y-m-d'); ?>.csv')" 
            class="button button-primary"
          >
            Export CSV
          </button>
        </div>
      </div>

      <?php if ( empty( $users ) ) : ?>
        <div class="notice notice-warning" style="padding:12px;">
          <p>No users found<?php echo ! empty( $search ) ? ' matching your search.' : '.'; ?></p>
        </div>
      <?php else : ?>

        <div class="table-responsive">
          <table 
            id="breathermae-user-monitor-table" 
            class="breathermae-user-monitor-table wp-list-table widefat fixed striped"
          >
            <thead>
              <tr>
                <th>Username</th>
                <th>First Name</th>
                <th>Last Name</th>
                <th>Last Visit Date</th>
                <th>Last Page Visited</th>
                <?php if ( $show_ip ) : ?>
                  <th>IP Address</th>
                <?php endif; ?>
                <?php if ( $show_geo ) : ?>
                  <th>Geo Location</th>
                <?php endif; ?>
                <?php foreach ( $status_columns as $col ) : ?>
                  <th class="status-col"><?php echo esc_html( $col['label'] ); ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ( $users as $user ) : 
                $first_name  = ! empty( $user->first_name ) ? $user->first_name : '';
                $last_name   = ! empty( $user->last_name ) ? $user->last_name : '';
                $last_visit  = ! empty( $user->last_visit ) 
                  ? esc_html( date_i18n( 'Y-m-d H:i', strtotime( $user->last_visit ) ) ) 
                  : '—';

                $last_page_raw = ! empty( $user->last_page ) ? $user->last_page : '';
                // Clean up last_page to show a nice slug if it's a full URL
                if ( $last_page_raw ) {
                  $path = parse_url( $last_page_raw, PHP_URL_PATH );
                  $last_page = $path ? trim( basename( $path ), '/' ) : esc_html( $last_page_raw );
                  if ( empty( $last_page ) ) $last_page = esc_html( $last_page_raw );
                } else {
                  $last_page = '—';
                }

                $ip_display  = ( $show_ip && ! empty( $user->ip_address ) ) ? esc_html( $user->ip_address ) : '';
                $geo_display = ( $show_geo && ! empty( $user->geo_location ) ) ? esc_html( $user->geo_location ) : '';
              ?>
                <tr>
                  <td><strong><?php echo esc_html( $user->user_login ); ?></strong></td>
                  <td><?php echo esc_html( $first_name ); ?></td>
                  <td><?php echo esc_html( $last_name ); ?></td>
                  <td><?php echo $last_visit; ?></td>
                  <td><?php echo esc_html( $last_page ); ?></td>

                  <?php if ( $show_ip ) : ?>
                    <td><?php echo $ip_display ?: '—'; ?></td>
                  <?php endif; ?>

                  <?php if ( $show_geo ) : ?>
                    <td><?php echo $geo_display ?: '—'; ?></td>
                  <?php endif; ?>

                  <?php 
                  // Dynamic status columns
                  foreach ( $status_columns as $col ) : 
                    $has_tag = false;

                    if ( $has_wpf ) {
                      // Preferred: Use WP Fusion's official method
                      $has_tag = wp_fusion()->user->has_tag( $user->ID, $col['tag'] );
                    } else {
                      // Fallback: check common WP Fusion usermeta storage
                      $wpf_tags = get_user_meta( $user->ID, 'wpf_tags', true );
                      if ( is_array( $wpf_tags ) ) {
                        $has_tag = in_array( $col['tag'], $wpf_tags, true ) || 
                                   in_array( strtolower( $col['tag'] ), array_map( 'strtolower', $wpf_tags ), true );
                      }
                    }

                    $icon = $has_tag 
                      ? '<span class="dashicons dashicons-yes" style="color:#16a34a; font-size:24px; line-height:1;"></span>' 
                      : '<span class="dashicons dashicons-minus" style="color:#9ca3af; font-size:24px; line-height:1;"></span>';
                  ?>
                    <td class="status-cell"><?php echo $icon; ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Simple Pagination -->
        <?php if ( $total_pages > 1 ) : ?>
          <div class="tablenav" style="margin-top:12px;">
            <div class="tablenav-pages">
              <?php
                $base = remove_query_arg( 'um_paged' );
                for ( $i = 1; $i <= $total_pages; $i++ ) {
                  $url   = add_query_arg( 'um_paged', $i, $base );
                  $class = ( $i === $paged ) ? 'current button-primary' : 'button';
                  echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '" style="margin-right:4px; min-width:32px; text-align:center;">' . $i . '</a>';
                }
              ?>
              <span class="displaying-num" style="margin-left:12px; color:#6b7280;">
                <?php echo number_format_i18n( $total_users ); ?> users total
              </span>
            </div>
          </div>
        <?php endif; ?>

      <?php endif; ?>

      <p class="monitor-footer">
        <small>
          Internal use only • Protected by WP Fusion • 
          Data source: live-user-monitor table + WP Fusion tags • 
          Default sort: Last Visit (newest first)
        </small>
      </p>
    </div>

    <script>
    /**
     * Export the monitor table to CSV (useful for your Excel VBA workflows)
     */
    function exportTableToCSV(tableId, filename) {
      const table = document.getElementById(tableId);
      if (!table) {
        alert('Table not found for export.');
        return;
      }

      const rows = table.querySelectorAll('tr');
      const csv = [];

      for (let i = 0; i < rows.length; i++) {
        const cols = rows[i].querySelectorAll('td, th');
        const row = [];

        for (let j = 0; j < cols.length; j++) {
          let text = cols[j].innerText.trim().replace(/"/g, '""');

          // Convert status icons to simple check/empty for CSV
          if (cols[j].querySelector('.dashicons-yes')) {
            text = '✓';
          } else if (cols[j].querySelector('.dashicons-minus')) {
            text = '☐';
          }

          row.push('"' + text + '"');
        }
        csv.push(row.join(','));
      }

      const csvContent = csv.join('\n');
      const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      const url = URL.createObjectURL(blob);

      link.setAttribute('href', url);
      link.setAttribute('download', filename);
      link.style.visibility = 'hidden';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    }
    </script>
    <?php
    return ob_get_clean();
  }
}

// Initialize
new BreatherMae_User_Monitor_List();
