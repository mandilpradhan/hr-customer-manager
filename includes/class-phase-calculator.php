<?php
/**
 * Phase calculator for bookings.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('HR_CM_Phase_Calculator')) {
    /**
     * Provides phase labels based on days to trip.
     */
    class HR_CM_Phase_Calculator {
        /**
         * Determine the phase label based on the days remaining until departure.
         *
         * @param int|null $days_to_trip Number of days until departure. Null when unknown.
         *
         * @return string
         */
        public static function get_phase_label($days_to_trip) {
            if ($days_to_trip === null) {
                return __('Phase Unknown', 'hr-customer-manager');
            }

            if ($days_to_trip < 0) {
                return __('Phase 9 – Post-Trip Follow-Up', 'hr-customer-manager');
            }

            if ($days_to_trip === 0) {
                return __('Phase 8 – Trip Day', 'hr-customer-manager');
            }

            if ($days_to_trip <= 3) {
                return __('Phase 7 – Pack & Depart', 'hr-customer-manager');
            }

            if ($days_to_trip <= 7) {
                return __('Phase 6 – Final Details', 'hr-customer-manager');
            }

            if ($days_to_trip <= 14) {
                return __('Phase 5 – 14-Day Reminder', 'hr-customer-manager');
            }

            if ($days_to_trip <= 30) {
                return __('Phase 4 – 30-Day Reminder', 'hr-customer-manager');
            }

            if ($days_to_trip <= 60) {
                return __('Phase 3 – 60-Day Reminder / Prep (Paid)', 'hr-customer-manager');
            }

            if ($days_to_trip <= 120) {
                return __('Phase 2 – Insurance Window', 'hr-customer-manager');
            }

            return __('Phase 1 – Far Out', 'hr-customer-manager');
        }
    }
}
