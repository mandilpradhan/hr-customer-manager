<?php
/**
 * Booking normalization and filtering for the admin table.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('HR_CM_Admin_Table')) {
    /**
     * Prepares booking data for the admin table view.
     */
    class HR_CM_Admin_Table {
        /**
         * Prepare data for the customer overview screen.
         *
         * @return array
         */
        public function prepare_data() {
            $filters = $this->get_filters_from_request();

            $rows          = $this->get_all_rows();
            $trip_options  = $this->build_trip_options($rows);
            $filtered_rows = $this->apply_filters($rows, $filters['values']);

            return [
                'rows'           => $filtered_rows,
                'filters'        => $filters['values'],
                'filter_options' => [
                    'trips'       => $trip_options,
                    'statuses'    => [
                        'paid'      => __('Fully Paid', 'hr-customer-manager'),
                        'due'       => __('Balance Due', 'hr-customer-manager'),
                        'cancelled' => __('Cancelled', 'hr-customer-manager'),
                    ],
                    'days_ranges' => [
                        'lt30'   => __('Less than 30 days', 'hr-customer-manager'),
                        '30to60' => __('30 to 60 days', 'hr-customer-manager'),
                        'gt60'   => __('More than 60 days', 'hr-customer-manager'),
                    ],
                ],
                'nonce_action'   => 'hr_cm_filters',
            ];
        }

        /**
         * Retrieve all booking posts and normalize them into traveler rows.
         *
         * @return array
         */
        private function get_all_rows() {
            if (!class_exists('WP_Query')) {
                return [];
            }

            $query = new WP_Query([
                'post_type'      => 'booking',
                'post_status'    => ['publish', 'confirmed', 'complete', 'pending'],
                'posts_per_page' => 200,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            ]);

            if (!$query->have_posts()) {
                return [];
            }

            $timezone = wp_timezone();
            $rows     = [];

            foreach ($query->posts as $post) {
                $context = $this->build_booking_context($post->ID, $timezone);
                if (!$context) {
                    continue;
                }

                foreach ($context['travelers'] as $traveler) {
                    $rows[] = $this->build_traveler_row($traveler, $context);
                }
            }

            return $rows;
        }

        /**
         * Build the shared booking context for traveler rows.
         *
         * @param int          $booking_id Booking post ID.
         * @param DateTimeZone $timezone   Site timezone.
         *
         * @return array|null
         */
        private function build_booking_context($booking_id, DateTimeZone $timezone) {
            $travelers = HR_CM_Data::get_travelers($booking_id);
            if (empty($travelers)) {
                return null;
            }

            $lead     = HR_CM_Data::get_lead($booking_id);
            $trip     = HR_CM_Data::get_trip($booking_id);
            $payment  = HR_CM_Data::get_payment($booking_id);
            $days     = null;

            if ('' !== $trip['date']) {
                $days = HR_CM_Data::days_to_trip($trip['date'], $timezone);
            }

            $is_fully_paid = $this->is_fully_paid($payment);
            $is_cancelled  = $this->is_cancelled($payment);
            $phase_label   = HR_CM_Phase_Calculator::get_phase_label($days, $is_fully_paid);

            return [
                'booking_id'    => (int) $booking_id,
                'travelers'     => $travelers,
                'lead'          => $lead,
                'trip'          => $trip,
                'payment'       => $payment,
                'days_to_trip'  => $days,
                'is_fully_paid' => $is_fully_paid,
                'is_cancelled'  => $is_cancelled,
                'phase_label'   => $phase_label,
            ];
        }

        /**
         * Build a row for a specific traveler.
         *
         * @param array $traveler Traveler data.
         * @param array $context  Booking context.
         *
         * @return array
         */
        private function build_traveler_row($traveler, $context) {
            $lead        = isset($context['lead']) ? $context['lead'] : ['name' => '', 'email' => ''];
            $lead_exists = ('' !== trim((string) $lead['name'])) || ('' !== trim((string) $lead['email']));
            $lead_differs = false;

            if ($lead_exists) {
                $lead_differs = !$this->strings_equal($traveler['name'], $lead['name'])
                    || !$this->strings_equal($traveler['email'], $lead['email']);
            }

            $payment_display = $this->format_payment_status(
                $context['payment'],
                $context['days_to_trip'],
                $context['is_fully_paid'],
                $context['is_cancelled']
            );

            $info_display = $this->format_info_received($context['days_to_trip']);

            return [
                'booking_id'      => $context['booking_id'],
                'traveler_name'   => $traveler['name'],
                'traveler_email'  => $traveler['email'],
                'lead_name'       => $lead_exists ? $lead['name'] : '',
                'lead_email'      => $lead_exists ? $lead['email'] : '',
                'show_lead'       => $lead_exists && $lead_differs,
                'trip_name'       => $context['trip']['name'],
                'departure_date'  => $context['trip']['date'],
                'days_to_trip'    => $context['days_to_trip'],
                'payment'         => $payment_display,
                'info'            => $info_display,
                'phase_label'     => $context['phase_label'],
                'resend_disabled' => $context['is_cancelled'],
                'payment_filter'  => $payment_display['filter'],
            ];
        }

        /**
         * Determine if a booking is considered fully paid.
         *
         * @param array $payment Payment context.
         *
         * @return bool
         */
        private function is_fully_paid($payment) {
            $due     = isset($payment['due']) ? (float) $payment['due'] : null;
            $status  = isset($payment['p_status']) ? strtolower((string) $payment['p_status']) : '';

            if (null !== $due && $due <= 0.0) {
                return true;
            }

            return in_array($status, ['paid', 'completed'], true);
        }

        /**
         * Determine if a booking has been cancelled or refunded.
         *
         * @param array $payment Payment context.
         *
         * @return bool
         */
        private function is_cancelled($payment) {
            $status = isset($payment['b_status']) ? strtolower((string) $payment['b_status']) : '';

            return in_array($status, ['cancelled', 'canceled', 'refunded'], true);
        }

        /**
         * Prepare payment status display data.
         *
         * @param array    $payment      Payment context.
         * @param int|null $days_to_trip Days remaining to trip.
         * @param bool     $is_paid      Whether fully paid.
         * @param bool     $is_cancelled Whether cancelled/refunded.
         *
         * @return array
         */
        private function format_payment_status($payment, $days_to_trip, $is_paid, $is_cancelled) {
            if ($is_cancelled) {
                return [
                    'text'   => __('Cancelled', 'hr-customer-manager'),
                    'class'  => 'text-muted',
                    'filter' => 'cancelled',
                ];
            }

            if ($is_paid) {
                return [
                    'text'   => __('Fully Paid', 'hr-customer-manager'),
                    'class'  => 'text-regular',
                    'filter' => 'paid',
                ];
            }

            $class = 'text-regular';

            if ($days_to_trip !== null) {
                if ($days_to_trip < 60) {
                    $class = 'text-danger';
                } elseif ($days_to_trip <= 75) {
                    $class = 'text-warn';
                }
            }

            return [
                'text'   => __('Balance Due', 'hr-customer-manager'),
                'class'  => $class,
                'filter' => 'due',
            ];
        }

        /**
         * Prepare info received display data.
         *
         * @param int|null $days_to_trip Days remaining to trip.
         *
         * @return array
         */
        private function format_info_received($days_to_trip) {
            $class = 'text-muted';

            if ($days_to_trip !== null) {
                if ($days_to_trip > 60) {
                    $class = 'text-regular';
                } elseif ($days_to_trip >= 50) {
                    $class = 'text-warn';
                } else {
                    $class = 'text-danger';
                }
            }

            return [
                'text'  => __('Not Received', 'hr-customer-manager'),
                'class' => $class,
            ];
        }

        /**
         * Build trip options map for filters.
         *
         * @param array $rows Traveler rows.
         *
         * @return array
         */
        private function build_trip_options($rows) {
            $options = [];

            foreach ($rows as $row) {
                $trip_name = $row['trip_name'];
                if (!isset($options[$trip_name])) {
                    $options[$trip_name] = [
                        'name'  => $trip_name,
                        'dates' => [],
                    ];
                }

                if ('' !== $row['departure_date']) {
                    $options[$trip_name]['dates'][$row['departure_date']] = $row['departure_date'];
                }
            }

            ksort($options, SORT_NATURAL | SORT_FLAG_CASE);

            foreach ($options as &$trip) {
                $dates = $trip['dates'];
                ksort($dates);
                $trip['dates'] = array_values($dates);
            }
            unset($trip);

            return $options;
        }

        /**
         * Apply filters to traveler rows.
         *
         * @param array $rows    Traveler rows.
         * @param array $filters Filter values.
         *
         * @return array
         */
        private function apply_filters($rows, $filters) {
            $filtered = [];

            foreach ($rows as $row) {
                if ($filters['nonce_valid']) {
                    if ('' !== $filters['trip'] && $row['trip_name'] !== $filters['trip']) {
                        continue;
                    }

                    if ('' !== $filters['departure'] && $row['departure_date'] !== $filters['departure']) {
                        continue;
                    }

                    if ('' !== $filters['status'] && $row['payment_filter'] !== $filters['status']) {
                        continue;
                    }

                    if ('' !== $filters['days_range']) {
                        $days = $row['days_to_trip'];
                        if ($days === null) {
                            continue;
                        }

                        if ('lt30' === $filters['days_range'] && !($days < 30)) {
                            continue;
                        }

                        if ('30to60' === $filters['days_range'] && !($days >= 30 && $days <= 60)) {
                            continue;
                        }

                        if ('gt60' === $filters['days_range'] && !($days > 60)) {
                            continue;
                        }
                    }

                    if ('' !== $filters['search']) {
                        $haystack = strtolower(
                            $row['traveler_name'] . ' ' .
                            $row['traveler_email'] . ' ' .
                            $row['lead_name'] . ' ' .
                            $row['lead_email'] . ' ' .
                            $row['trip_name']
                        );

                        if (false === strpos($haystack, strtolower($filters['search']))) {
                            continue;
                        }
                    }
                }

                $filtered[] = $row;
            }

            return $filtered;
        }

        /**
         * Sanitize and collect filters from the current request.
         *
         * @return array
         */
        private function get_filters_from_request() {
            $nonce_valid = false;
            if (isset($_GET['hr_cm_nonce'])) {
                $nonce_valid = wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['hr_cm_nonce'])), 'hr_cm_filters');
            }

            $filters = [
                'trip'        => '',
                'departure'   => '',
                'status'      => '',
                'days_range'  => '',
                'search'      => '',
                'nonce_valid' => $nonce_valid,
            ];

            if ($nonce_valid) {
                if (isset($_GET['hr_cm_trip'])) {
                    $filters['trip'] = sanitize_text_field(wp_unslash($_GET['hr_cm_trip']));
                }

                if (isset($_GET['hr_cm_departure'])) {
                    $filters['departure'] = sanitize_text_field(wp_unslash($_GET['hr_cm_departure']));
                }

                if (isset($_GET['hr_cm_status'])) {
                    $filters['status'] = sanitize_text_field(wp_unslash($_GET['hr_cm_status']));
                }

                if (isset($_GET['hr_cm_days'])) {
                    $filters['days_range'] = sanitize_text_field(wp_unslash($_GET['hr_cm_days']));
                }

                if (isset($_GET['hr_cm_search'])) {
                    $filters['search'] = sanitize_text_field(wp_unslash($_GET['hr_cm_search']));
                }
            }

            return [
                'values' => $filters,
            ];
        }

        /**
         * Determine if two strings are equal, ignoring case and whitespace.
         *
         * @param string $a First string.
         * @param string $b Second string.
         *
         * @return bool
         */
        private function strings_equal($a, $b) {
            $normalized_a = strtolower(trim(wp_strip_all_tags((string) $a)));
            $normalized_b = strtolower(trim(wp_strip_all_tags((string) $b)));

            return $normalized_a === $normalized_b;
        }
    }
}
