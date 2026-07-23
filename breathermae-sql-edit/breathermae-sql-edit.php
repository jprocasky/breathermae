<?php
/**
 * Plugin Name: Breathermae SQL Edit
 * Description: Developer-only SQL console with optional inline edit mode. Run queries, view results, history, and (optionally) edit cells to generate or run UPDATE statements.
 * Version: 1.1.7-beta
 * Author: Breathermae
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

// Separate enable flag for this plugin
if (!defined('BMSE_ENABLED')) { define('BMSE_ENABLED', false); }

// Constants
define('BMSE_VERSION', '1.1.7-beta');
define('BMSE_PATH', plugin_dir_path(__FILE__));
define('BMSE_URL',  plugin_dir_url(__FILE__));

require_once BMSE_PATH . 'includes/class-bmse-activator.php';
require_once BMSE_PATH . 'includes/class-bmse-admin.php';
require_once BMSE_PATH . 'includes/class-bmse-settings.php'; // Settings page (toolbar defaults)

register_activation_hook(__FILE__, ['BMSE_Activator', 'activate']);

add_action('plugins_loaded', function () {
    if (is_admin()) {
        new BMSE_Admin();
        BMSE_Settings::init();
    }
});

// Add "Settings" link in the Plugins list row
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links){
    $url = admin_url('tools.php?page=bmse-settings');
    $links[] = '<a href="'.esc_url($url).'">'.esc_html__('Settings','bmse').'</a>';
    return $links;
});
