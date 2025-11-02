<?php
/**
 * Plugin Name: HR Customer Manager
 * Description: Admin-only management dashboard for customer journeys powered by WP Travel Engine bookings.
 * Version: 0.2.0
 * Author: Himalayan Rides
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('HR_CM_VERSION')) {
    define('HR_CM_VERSION', '0.2.0');
}

if (!defined('HR_CM_PLUGIN_FILE')) {
    define('HR_CM_PLUGIN_FILE', __FILE__);
}

if (!defined('HR_CM_PLUGIN_DIR')) {
    define('HR_CM_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('HR_CM_PLUGIN_URL')) {
    define('HR_CM_PLUGIN_URL', plugin_dir_url(__FILE__));
}

require_once HR_CM_PLUGIN_DIR . 'includes/class-database.php';
require_once HR_CM_PLUGIN_DIR . 'includes/class-data.php';
require_once HR_CM_PLUGIN_DIR . 'includes/class-phase-calculator.php';
require_once HR_CM_PLUGIN_DIR . 'includes/class-admin-table.php';
require_once HR_CM_PLUGIN_DIR . 'admin/class-admin-page.php';

if (!class_exists('HR_CM_Plugin')) {
    /**
     * Bootstrap class for the plugin.
     */
    final class HR_CM_Plugin {
        /**
         * Singleton instance.
         *
         * @var HR_CM_Plugin|null
         */
        private static $instance = null;

        /**
         * Retrieve the singleton instance.
         *
         * @return HR_CM_Plugin
         */
        public static function instance() {
            if (null === self::$instance) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Constructor.
         */
        private function __construct() {
            add_action('plugins_loaded', [$this, 'init']);
        }

        /**
         * Initialize plugin components.
         */
        public function init() {
            load_plugin_textdomain('hr-customer-manager', false, dirname(plugin_basename(HR_CM_PLUGIN_FILE)) . '/languages/');

            if (is_admin()) {
                HR_CM_Admin_Page::instance();
            }
        }
    }
}

HR_CM_Plugin::instance();

register_activation_hook(HR_CM_PLUGIN_FILE, ['HR_CM_Database', 'activate']);
