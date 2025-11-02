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
        public static function get_phase_label($days_to_trip, $is_fully_paid = false) {
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
                $label = __('Phase 6 – Final Details', 'hr-customer-manager');

                return self::maybe_add_paid_suffix($label, $is_fully_paid);
            }

            if ($days_to_trip <= 14) {
                $label = __('Phase 5 – 14-Day Reminder', 'hr-customer-manager');

                return self::maybe_add_paid_suffix($label, $is_fully_paid);
            }

            if ($days_to_trip <= 30) {
                $label = __('Phase 4 – 30-Day Reminder', 'hr-customer-manager');

                return self::maybe_add_paid_suffix($label, $is_fully_paid);
            }

            if ($days_to_trip <= 60) {
                $label = __('Phase 3 – 60-Day Reminder', 'hr-customer-manager');

                return self::maybe_add_paid_suffix($label, $is_fully_paid);
            }

            if ($days_to_trip <= 120) {
                return __('Phase 2 – Insurance Window', 'hr-customer-manager');
            }

            return __('Phase 1 – Far Out', 'hr-customer-manager');
        }

        /**
         * Append a paid suffix to reminder phases when applicable.
         *
         * @param string $label         Base phase label.
         * @param bool   $is_fully_paid Whether the booking is fully paid.
         *
         * @return string
         */
        private static function maybe_add_paid_suffix($label, $is_fully_paid) {
            if ($is_fully_paid) {
                return $label . __(' / Prep (Paid)', 'hr-customer-manager');
            }

            return $label;
        }
    }
}
