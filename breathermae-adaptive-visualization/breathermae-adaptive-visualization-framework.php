<?php
/**
 * Plugin Name: Breathermae Adaptive Visualization Framework
 * Plugin URI: https://breathermae.com
 * Description: Eight Pillars of Wellness historical dashboard and adaptive visualization foundation for Breathermae.
 * Version: 1.1.1
 * Author: Breathermae
 * Text Domain: breathermae-adaptive-visualization
 * Requires at least: 6.4
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BMAE_AVF_VERSION', '1.1.1');
define('BMAE_AVF_FILE', __FILE__);
define('BMAE_AVF_DIR', plugin_dir_path(__FILE__));
define('BMAE_AVF_URL', plugin_dir_url(__FILE__));
define('BMAE_AVF_BASENAME', plugin_basename(__FILE__));

require_once BMAE_AVF_DIR . 'includes/class-bmae-avf-config.php';
require_once BMAE_AVF_DIR . 'includes/class-bmae-avf-security.php';
require_once BMAE_AVF_DIR . 'includes/class-bmae-avf-activator.php';
require_once BMAE_AVF_DIR . 'includes/class-bmae-avf-eight-pillars-registry.php';
require_once BMAE_AVF_DIR . 'includes/class-bmae-avf-data-validator.php';
require_once BMAE_AVF_DIR . 'includes/class-bmae-avf-pillars-results-adapter.php';
require_once BMAE_AVF_DIR . 'includes/class-bmae-avf-eight-pillars-provider.php';
require_once BMAE_AVF_DIR . 'includes/class-bmae-avf-rest-controller.php';
require_once BMAE_AVF_DIR . 'includes/class-bmae-avf-shortcodes.php';
require_once BMAE_AVF_DIR . 'includes/class-bmae-avf-plugin.php';

register_activation_hook(BMAE_AVF_FILE, ['BMAE_AVF_Activator', 'activate']);
register_deactivation_hook(BMAE_AVF_FILE, ['BMAE_AVF_Activator', 'deactivate']);

BMAE_AVF_Plugin::instance();
