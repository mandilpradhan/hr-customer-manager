<?php
/**
 * Database helper for HR Customer Manager.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('HR_CM_Database')) {
    /**
     * Handles database table creation and lookups.
     */
    class HR_CM_Database {
        /**
         * Get the journey log table name.
         *
         * @return string
         */
        public static function get_table_name() {
            global $wpdb;

            return $wpdb->prefix . 'hr_cm_journey_log';
        }

        /**
         * Create or update the journey log table during activation.
         */
        public static function activate() {
            global $wpdb;

            $table_name      = self::get_table_name();
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$table_name} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id BIGINT(20) UNSIGNED NOT NULL,
                email_type VARCHAR(100) NOT NULL,
                sent_date DATETIME NOT NULL,
                sent_method VARCHAR(20) NOT NULL,
                current_phase VARCHAR(120) NOT NULL,
                PRIMARY KEY (id),
                KEY booking_id (booking_id)
            ) {$charset_collate};";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }
}
