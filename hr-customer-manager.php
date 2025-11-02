<?php
/**
 * Plugin Name: HR Customer Manager
 * Description: Bootstrap plugin skeleton for Himalayan Rides customer management.
 * Version: 0.1.0
 * Author: Himalayan Rides
 * License: GPL-2.0+
 */
if (!defined('ABSPATH')) { exit; }

if (!class_exists('HR_Customer_Manager')) {
    final class HR_Customer_Manager {
        private static $instance = null;

        public static function instance() {
            if (null === self::$instance) { self::$instance = new self(); }
            return self::$instance;
        }

        private function __construct() {
            add_action('plugins_loaded', [$this, 'init']);
        }

        public function init() {
            // Fail-soft: only run if core WP functions exist
            if (!function_exists('add_action')) { return; }
            // Ready for future includes; do not hard-code tokens/domains
            // e.g. if (file_exists(__DIR__ . '/includes/hooks.php')) require __DIR__ . '/includes/hooks.php';
        }
    }
    HR_Customer_Manager::instance();
}
