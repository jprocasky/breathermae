<?php
/**
 * Plugin Name: Breathermae Logger
 * Plugin URI:  https://breathermae.com
 * Description: Global file-based logger for Breathermae WordPress plugins and JS.
 * Version:     1.0.0
 * Author:      Breathermae
 * Author URI:  https://breathermae.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ==========================================
 * CONFIGURATION
 * ==========================================
 */

/**
 * Enable or disable logging globally
 * You may override this in wp-config.php
 */
if ( ! defined( 'BREATHERMAE_LOGGER_ENABLED' ) ) {
    define( 'BREATHERMAE_LOGGER_ENABLED', true );
}

/**
 * Minimum log level to write
 * Allowed: debug, info, warning, error
 */
if ( ! defined( 'BREATHERMAE_LOGGER_MIN_LEVEL' ) ) {
    define( 'BREATHERMAE_LOGGER_MIN_LEVEL', 'debug' );
}

/**
 * ==========================================
 * LOGGER CLASS
 * ==========================================
 */

class Breathermae_Logger {

    protected static $levels = [
        'debug'   => 0,
        'info'    => 1,
        'warning' => 2,
        'error'   => 3,
    ];

    public static function log( $message, $context = [], $level = 'info', $source = '' ) {

        if ( ! defined( 'BREATHERMAE_LOGGER_ENABLED' ) || ! BREATHERMAE_LOGGER_ENABLED ) {
            return;
        }

        if ( ! isset( self::$levels[ $level ] ) ) {
            $level = 'info';
        }

        if (
            ! defined( 'BREATHERMAE_LOGGER_MIN_LEVEL' ) ||
            self::$levels[ $level ] < self::$levels[ BREATHERMAE_LOGGER_MIN_LEVEL ]
        ) {
            return;
        }

        // ---- Resolve log directory (unchanged) ----
        $upload_dir = wp_upload_dir();
        $log_dir = trailingslashit( $upload_dir['basedir'] ) . 'breathermae-logs';

        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }

        $log_file = trailingslashit( $log_dir ) . 'global.log';

        // ---- UTC timestamp (explicit, unambiguous) ----
        try {
            $dt = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
            $timestamp = $dt->format( 'Y-m-d H:i:s T' ); // e.g. 2026-04-25 22:54:23 UTC
        } catch ( Exception $e ) {
            // Fallback – extremely unlikely, but safe
            $timestamp = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
        }

        // ---- Build log entry ----
        $entry = sprintf(
            "[%s] [%s] [%s] %s %s\n",
            $timestamp,
            strtoupper( $level ),
            $source ?: 'global',
            $message,
            ! empty( $context )
                ? json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
                : ''
        );

        // ---- Write atomically ----
        file_put_contents( $log_file, $entry, FILE_APPEND | LOCK_EX );
    }
}

/**
 * ==========================================
 * GLOBAL HELPER FUNCTION
 * ==========================================
 */

function bm_log( $message, $context = [], $level = 'info', $source = '' ) {
    Breathermae_Logger::log( $message, $context, $level, $source );
}

/**
 * ==========================================
 * SHORTCODE: [breathermae_logger]
 * ==========================================
 */

add_shortcode( 'breathermae_logger', function() {

    $upload_dir = wp_upload_dir();
    $log_file = trailingslashit( $upload_dir['basedir'] ) . 'breathermae-logs/global.log';

    if ( ! file_exists( $log_file ) ) {
        return '<pre>No log file found.</pre>';
    }

    $contents = file_get_contents( $log_file );

    ob_start();
    ?>
    <div style="background:#111;color:#0f0;padding:20px;max-height:600px;overflow:auto;font-family:monospace;font-size:12px;white-space:pre-wrap;">
        <?php echo esc_html( $contents ); ?>
    </div>
    <?php

    return ob_get_clean();
});