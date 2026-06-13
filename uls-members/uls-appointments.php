<?php
/**
 * ULS Member Appointments Module
 * Store & render member appointments, with .ics download per item.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'ULS_Member_Appointments_Module' ) ) :

class ULS_Member_Appointments_Module {

    const DB_VERSION = '1.0.0';
    private static $instance = null;
    private $table = 'uls_member_appointments';

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'maybe_upgrade_schema' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX (logged-in only)
        add_action( 'wp_ajax_uls_get_member_appts', [ $this, 'ajax_get_member_appts' ] );
        add_action( 'wp_ajax_uls_save_member_appt', [ $this, 'ajax_save_member_appt' ] );
        add_action( 'wp_ajax_uls_delete_member_appt', [ $this, 'ajax_delete_member_appt' ] );

        // ICS download (logged-in only, outputs text/calendar)
        add_action( 'wp_ajax_uls_download_appt_ics', [ $this, 'ajax_download_appt_ics' ] );
        add_shortcode('uls_member_appointments', function ($atts) {

            if (!is_user_logged_in()) {
                return '<p>Please log in to view your reminders.</p>';
            }

            $user = wp_get_current_user();
            if (!$user || !is_email($user->user_email)) {
                return '<p>No reminders available.</p>';
            }

            $atts = shortcode_atts([
                'mode'   => 'list',
                'future' => '1', // ✅ NEW
            ], $atts, 'uls_member_appointments');

            ob_start();
            ?>
            <div
                class="uls-appts-panel"
                data-self="1"
                data-mode="<?php echo esc_attr($atts['mode']); ?>"
                data-future="<?php echo esc_attr($atts['future']); ?>"
                data-email="<?php echo esc_attr($user->user_email); ?>">
            </div>
            <?php
            return ob_get_clean();
        });  
    }

    /** Assets */
    public function enqueue_assets() {
        $css_ver = @filemtime( plugin_dir_path(__FILE__) . 'uls-appointments.css' ) ?: time();
        $js_ver  = @filemtime( plugin_dir_path(__FILE__) . 'uls-appointments.js' ) ?: time();

        wp_register_style( 'uls-appts-css', plugins_url( 'uls-appointments.css', __FILE__ ), [], $css_ver );
        wp_enqueue_style( 'uls-appts-css' );

        wp_register_script( 'uls-appts-js', plugins_url( 'uls-appointments.js', __FILE__ ), [ 'jquery' ], $js_ver, true );
        wp_localize_script( 'uls-appts-js', 'ULS_APPTS', [
            'ajaxurl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'uls_member_appts' ),
            'getAction'  => 'uls_get_member_appts',
            'saveAction' => 'uls_save_member_appt',
            'delAction'  => 'uls_delete_member_appt',
            'icsAction'  => 'uls_download_appt_ics',
            'canEdit'    => 1,
        ] );
        wp_enqueue_script( 'uls-appts-js' );
    }

    /** Schema */
    public function maybe_upgrade_schema() {
        $opt = 'uls_member_appts_db_version';
        $installed = get_option( $opt );
        if ( $installed === self::DB_VERSION ) return;

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $this->table;

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `member_email`   VARCHAR(191) NOT NULL,
            `member_user_id` BIGINT UNSIGNED NULL,
            `start_at` DATETIME NOT NULL,
            `end_at`   DATETIME NULL,
            `subject`  VARCHAR(200) NOT NULL,
            `description` LONGTEXT NULL,
            `location` VARCHAR(255) NULL,
            `created_by` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_by` BIGINT UNSIGNED NULL,
            `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_member_email` (`member_email`),
            KEY `idx_start_at` (`start_at`)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( $opt, self::DB_VERSION );
    }

    /** Helpers */
    private function fmt_dt( $mysql_dt ) {
        if ( empty( $mysql_dt ) ) return '';

        // Parse the stored 'Y-m-d H:i:s' AS site-local time, not server time
        $site_tz = wp_timezone(); // WP site tz object
        try {
            // Create an "unaware" local datetime by telling DateTime it's in site tz
            $dt = new DateTime( $mysql_dt, $site_tz );
        } catch ( Exception $e ) {
            return $mysql_dt; // fallback raw
        }

        // Format using WP's site tz and formats (wp_date uses site tz by default)
        $fmt = trim( sprintf(
            '%s %s',
            (string) get_option( 'date_format', 'M j, Y' ),
            (string) get_option( 'time_format', 'g:i a' )
        ) );

        return wp_date( $fmt, $dt->getTimestamp() );
    }

    private function tz() {
        // WP timezone (for ICS TZID)
        return wp_timezone_string() ?: 'UTC';
    }

    private function get_member_wp_user_id( $email ) {
        $u = get_user_by( 'email', $email );
        return $u ? intval( $u->ID ) : null;
    }

    private function parse_local_dt( $s ) {
        // Accepts HTML5 datetime-local string "YYYY-MM-DDTHH:MM" (no timezone)
        $s = trim( (string) $s );
        if ( $s === '' ) return null;
        // Treat input as WP site local time; store as 'Y-m-d H:i:s' (site local) for consistency with your other modules.
        $dt = date_create_from_format( 'Y-m-d\TH:i', $s, wp_timezone() );
        if ( ! $dt ) return null;
        return $dt->format( 'Y-m-d H:i:s' );
    }

    private function from_utc_iso_to_site_mysql( $iso_utc ) {
        $iso_utc = trim( (string) $iso_utc );
        if ( $iso_utc === '' ) return null;

        try {
            $dt = new DateTime( $iso_utc, new DateTimeZone('UTC') ); // incoming UTC
            $dt->setTimezone( wp_timezone() );                       // convert to site TZ
            return $dt->format( 'Y-m-d H:i:s' );                     // store as local DATETIME
        } catch ( Exception $e ) {
            return null;
        }
    }    

    /** AJAX: List */
    public function ajax_get_member_appts() {
        check_ajax_referer( 'uls_member_appts', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
        }
        $email = isset($_POST['email']) ? sanitize_email( wp_unslash($_POST['email']) ) : '';
        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Invalid email' ], 400 );
        }

        // Hardcode future_only for now
        $future_only = '1';
        $where = 'WHERE member_email = %s';
        $params = [$email];
        if ( $future_only ) {
            $where .= ' AND start_at >= %s';
            $params[] = current_time( 'mysql' );
        }   

        global $wpdb; $table = $this->table;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` {$where} AND `is_deleted` = 0 ORDER BY `start_at` ASC LIMIT 30",
            $params
        ), ARRAY_A );
        

        $appts = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                // Build DateTime from site-local stored DATETIME
                $site_tz  = wp_timezone();
                $start_dt = new DateTime( $r['start_at'], $site_tz );
                $end_dt   = ! empty( $r['end_at'] ) ? new DateTime( $r['end_at'], $site_tz ) : null;

                $appts[] = [
                    'id'          => (int) $r['id'],
                    'subject'     => (string) $r['subject'],
                    'description' => (string) ($r['description'] ?? ''),
                    'location'    => (string) ($r['location'] ?? ''),

                    // raw strings (unchanged)
                    'start_at'    => (string) $r['start_at'],
                    'end_at'      => $r['end_at'] ? (string) $r['end_at'] : null,

                    // server-side formatted (kept for fallback)
                    'start_fmt'   => $this->fmt_dt( $r['start_at'] ),
                    'end_fmt'     => $r['end_at'] ? $this->fmt_dt( $r['end_at'] ) : '',

                    // NEW: UTC epoch seconds so the browser can render in user’s local tz
                    'start_ts'    => $start_dt->getTimestamp(),
                    'end_ts'      => $end_dt ? $end_dt->getTimestamp() : null,

                    'created_by'  => (int) $r['created_by'],
                    'created_at'  => $this->fmt_dt( $r['created_at'] ),
                    'updated_by'  => $r['updated_by'] ? (int) $r['updated_by'] : null,
                    'updated_at'  => $this->fmt_dt( $r['updated_at'] ),
                ];
            }
        }

        wp_send_json_success( [ 'appts' => $appts ] );
    }

    /** AJAX: Save */
    public function ajax_save_member_appt() {
      
        check_ajax_referer( 'uls_member_appts', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
        }

        $email  = isset($_POST['email']) ? sanitize_email( wp_unslash($_POST['email']) ) : '';
        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Invalid email' ], 400 );
        }

        $subject = isset($_POST['subject']) ? sanitize_text_field( wp_unslash($_POST['subject']) ) : '';
        if ( $subject === '' ) {
            wp_send_json_error( [ 'message' => 'Subject is required' ], 400 );
        }

        $start_in     = isset($_POST['start'])      ? sanitize_text_field( wp_unslash($_POST['start']) )      : '';
        $end_in       = isset($_POST['end'])        ? sanitize_text_field( wp_unslash($_POST['end']) )        : '';
        $start_utc_in = isset($_POST['start_utc'])  ? sanitize_text_field( wp_unslash($_POST['start_utc']) )  : '';
        $end_utc_in   = isset($_POST['end_utc'])    ? sanitize_text_field( wp_unslash($_POST['end_utc']) )    : '';

        // Prefer UTC payload (exactly what the user picked in browser local time)
        if ( $start_utc_in !== '' ) {
            $start_at = $this->from_utc_iso_to_site_mysql( $start_utc_in );
        } else {
            $start_at = $this->parse_local_dt( $start_in ); // legacy fallback
        }

        if ( $end_utc_in !== '' ) {
            $end_at = $this->from_utc_iso_to_site_mysql( $end_utc_in );
        } else {
            $end_at = $this->parse_local_dt( $end_in ); // may be null/blank
        }

        if ( ! $start_at ) {
            wp_send_json_error( [ 'message' => 'Start date/time is required' ], 400 );
        }

        $raw_description = isset($_POST['description'])
            ? wp_unslash($_POST['description'])
            : '';

        $allowed_html = [
            'a' => [
                'href'   => true,
                'title'  => true,
                'target' => true,
                'rel'    => true,
            ],
            'br'     => [],
            'p'      => [],
            'strong' => [],
            'em'     => [],
            'b'      => [],
            'i'      => [],
            'ul'     => [],
            'ol'     => [],
            'li'     => [],
        ];

        $description = wp_kses( $raw_description, $allowed_html );

        // Default duration: 30 minutes if end missing or earlier than start
        $site_tz = wp_timezone();
        $start_dt = new DateTime( $start_at, $site_tz );
        $end_dt   = $end_at ? new DateTime( $end_at, $site_tz ) : null;

        if ( ! $end_dt || $end_dt < $start_dt ) {
            $end_dt = clone $start_dt;
            $end_dt->modify('+30 minutes');
        }
        $end_at = $end_dt->format('Y-m-d H:i:s');


        $location    = isset($_POST['location']) ? sanitize_text_field( wp_unslash($_POST['location']) ) : '';

        $current_user_id = get_current_user_id();
        $member_user_id  = $this->get_member_wp_user_id( $email );

        global $wpdb; $table = $this->table;

        $ok = $wpdb->insert( $table, [
            'member_email'   => $email,
            'member_user_id' => $member_user_id,
            'start_at'       => $start_at,
            'end_at'         => $end_at,
            'subject'        => $subject,
            'description'    => $description,
            'location'       => $location,
            'created_by'     => $current_user_id,
            'created_at'     => current_time( 'mysql' ),
            'updated_by'     => null,
            'updated_at'     => null,
            'is_deleted'     => 0,
        ], [ '%s','%d','%s','%s','%s','%s','%s','%d','%s','%d','%s','%d' ] );

        if ( ! $ok ) {
            wp_send_json_error( [ 'message' => 'DB insert failed' ], 500 );
        }

        $new_id = (int) $wpdb->insert_id;

        $appt = [
            'id'           => $new_id,
            'subject'      => $subject,
            'description'  => $description,
            'location'     => $location,
            'start_at'     => $start_at,
            'end_at'       => $end_at,
            'start_fmt'    => $this->fmt_dt( $start_at ),
            'end_fmt'      => $end_at ? $this->fmt_dt( $end_at ) : '',
            'created_by'   => $current_user_id,
            'created_at'   => $this->fmt_dt( current_time( 'mysql' ) ),
            'updated_by'   => null,
            'updated_at'   => '',
            'start_ts' => $start_dt->getTimestamp(),
            'end_ts'   => $end_dt->getTimestamp(),            
        ];

        wp_send_json_success( [ 'appt' => $appt ] );
    }

    /** AJAX: Soft delete */
    public function ajax_delete_member_appt() {
        check_ajax_referer( 'uls_member_appts', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ( $id <= 0 ) wp_send_json_error( [ 'message' => 'Invalid id' ], 400 );

        global $wpdb; $table = $this->table;
        $ok = $wpdb->update( $table, [
            'is_deleted' => 1,
            'updated_by' => get_current_user_id(),
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $id ], [ '%d','%d','%s' ], [ '%d' ] );

        if ( $ok === false ) {
            wp_send_json_error( [ 'message' => 'Delete failed' ], 500 );
        }

        wp_send_json_success( [ 'deleted' => 1 ] );
    }

    /** AJAX: Download ICS for an appointment id */
    public function ajax_download_appt_ics() {
        check_ajax_referer( 'uls_member_appts', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_die( 'Unauthorized', 'Unauthorized', [ 'response' => 401 ] );
        }

        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if ( $id <= 0 ) wp_die( 'Invalid id', 'Bad Request', [ 'response' => 400 ] );

        global $wpdb; $table = $this->table;
        $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM `{$table}` WHERE `id` = %d AND `is_deleted` = 0", $id ), ARRAY_A );
        if ( ! $row ) wp_die( 'Not found', 'Not Found', [ 'response' => 404 ] );

        $uid = sprintf( 'bm-appt-%d@%s', $id, parse_url( home_url(), PHP_URL_HOST ) ?: 'site' );
        $tzid = $this->tz();

        // Convert stored local times to UTC for ICS (safer across clients)
        $site_tz = wp_timezone();
        $fmt_ics = 'Ymd\THis\Z';

        $start_dt = date_create( $row['start_at'], $site_tz );
        $end_dt   = ! empty($row['end_at']) ? date_create( $row['end_at'], $site_tz ) : (clone $start_dt);
        $start_dt->setTimezone( new DateTimeZone('UTC') );
        $end_dt->setTimezone( new DateTimeZone('UTC') );

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Breathermae//ULS Appointments//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . gmdate( $fmt_ics ),
            'DTSTART:' . $start_dt->format( $fmt_ics ),
            'DTEND:'   . $end_dt->format( $fmt_ics ),
            'SUMMARY:' . $this->ics_escape( $row['subject'] ),
        ];

        if ( ! empty( $row['location'] ) ) {
            $lines[] = 'LOCATION:' . $this->ics_escape( $row['location'] );
        }
        if ( ! empty( $row['description'] ) ) {
            $lines[] = 'DESCRIPTION:' . $this->ics_escape( $row['description'] );
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        $ics = implode("\r\n", $lines) . "\r\n";

        nocache_headers();
        header( 'Content-Type: text/calendar; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="appointment-'.$id.'.ics"' );
        echo $ics;
        exit;
    }

    private function ics_escape( $s ) {
        // Escape per RFC 5545 basics: backslashes, commas, semicolons, and newlines
        $s = (string) $s;
        $s = str_replace('\\', '\\\\', $s);
        $s = str_replace([",",";"], ['\,','\;'], $s);
        $s = preg_replace("/\r\n|\r|\n/", "\\n", $s);
        return $s;
    }
}

endif;

ULS_Member_Appointments_Module::instance();