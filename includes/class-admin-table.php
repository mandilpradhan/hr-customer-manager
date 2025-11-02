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

            $bookings      = $this->get_all_bookings();
            $trip_options  = $this->build_trip_options($bookings);
            $filtered_data = $this->apply_filters($bookings, $filters['values']);

            return [
                'bookings'       => $filtered_data,
                'filters'        => $filters['values'],
                'filter_options' => [
                    'trips'       => $trip_options,
                    'statuses'    => [
                        'paid' => __('Fully Paid', 'hr-customer-manager'),
                        'due'  => __('Balance Due', 'hr-customer-manager'),
                    ],
                    'days_ranges' => [
                        'lt30'    => __('Less than 30 days', 'hr-customer-manager'),
                        '30to60'  => __('30 to 60 days', 'hr-customer-manager'),
                        'gt60'    => __('More than 60 days', 'hr-customer-manager'),
                    ],
                ],
                'nonce_action'   => 'hr_cm_filters',
            ];
        }

        /**
         * Retrieve all booking posts and normalize them.
         *
         * @return array
         */
        private function get_all_bookings() {
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

            $items = [];

            if (!$query->have_posts()) {
                return $items;
            }

            $timezone = wp_timezone();
            $now      = new DateTimeImmutable('now', $timezone);

            foreach ($query->posts as $post) {
                $items[] = $this->normalize_booking($post, $timezone, $now);
            }

            return array_filter($items);
        }

        /**
         * Normalize a booking post into the structure required by the table.
         *
         * @param WP_Post          $post     Booking post instance.
         * @param DateTimeZone     $timezone WordPress timezone.
         * @param DateTimeImmutable $now     Current datetime in site timezone.
         *
         * @return array|null
         */
        private function normalize_booking($post, DateTimeZone $timezone, DateTimeImmutable $now) {
            $settings = get_post_meta($post->ID, 'wp_travel_engine_booking_setting', true);

            if (!is_array($settings)) {
                $settings = [];
            }

            $place_order = isset($settings['place_order']) && is_array($settings['place_order'])
                ? $settings['place_order']
                : [];

            $traveler = $this->extract_traveler($place_order);
            $lead     = $this->extract_lead_traveler($post->ID, $place_order, $traveler);

            $trip_name      = $this->extract_trip_name($post->ID, $place_order);
            $raw_departure  = $this->extract_departure_raw($post->ID, $place_order);
            $departure_data = $this->prepare_departure($raw_departure, $timezone, $now);

            $is_paid      = $this->is_booking_paid($post->ID);
            $status_label = $is_paid ? __('Fully Paid', 'hr-customer-manager') : __('Balance Due', 'hr-customer-manager');
            $phase_label  = HR_CM_Phase_Calculator::get_phase_label($departure_data['days_to_trip']);

            return [
                'booking_id'      => (int) $post->ID,
                'traveler_name'   => $traveler['name'],
                'traveler_email'  => $traveler['email'],
                'lead_name'       => $lead['name'],
                'lead_email'      => $lead['email'],
                'lead_differs'    => $lead['differs'],
                'trip_name'       => $trip_name,
                'departure_date'  => $departure_data['formatted'],
                'days_to_trip'    => $departure_data['days_to_trip'],
                'payment_status'  => $is_paid ? 'paid' : 'due',
                'payment_label'   => $status_label,
                'phase_label'     => $phase_label,
            ];
        }

        /**
         * Extract traveler information from meta.
         *
         * @param array $place_order Place order data.
         *
         * @return array
         */
        private function extract_traveler($place_order) {
            $booking = isset($place_order['booking']) && is_array($place_order['booking']) ? $place_order['booking'] : [];

            $first_name = isset($booking['fname']) ? sanitize_text_field($booking['fname']) : '';
            $last_name  = isset($booking['lname']) ? sanitize_text_field($booking['lname']) : '';
            $email      = isset($booking['email']) ? sanitize_email($booking['email']) : '';

            $name = trim($first_name . ' ' . $last_name);

            if ('' === $name) {
                $name = __('Unknown Traveler', 'hr-customer-manager');
            }

            return [
                'name'  => $name,
                'email' => $email,
            ];
        }

        /**
         * Extract lead traveler details, falling back to traveler when identical or missing.
         *
         * @param int   $post_id     Booking post ID.
         * @param array $place_order Place order data.
         * @param array $traveler    Traveler data.
         *
         * @return array
         */
        private function extract_lead_traveler($post_id, $place_order, $traveler) {
            $lead_data = isset($place_order['lead_traveler']) && is_array($place_order['lead_traveler'])
                ? $place_order['lead_traveler']
                : [];

            $first_name = isset($lead_data['fname']) ? sanitize_text_field($lead_data['fname']) : '';
            $last_name  = isset($lead_data['lname']) ? sanitize_text_field($lead_data['lname']) : '';
            $email      = isset($lead_data['email']) ? sanitize_email($lead_data['email']) : '';

            if ('' === $email) {
                $meta_email = get_post_meta($post_id, 'lead_email', true);
                $email      = sanitize_email($meta_email);
            }

            $name    = trim($first_name . ' ' . $last_name);
            $differs = false;

            if ('' === $name && '' === $email) {
                $name  = $traveler['name'];
                $email = $traveler['email'];
            } else {
                $differs = !self::strings_equal($traveler['name'], $name) || !self::strings_equal($traveler['email'], $email);
            }

            return [
                'name'    => $name,
                'email'   => $email,
                'differs' => $differs,
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
        private static function strings_equal($a, $b) {
            $normalized_a = strtolower(trim(wp_strip_all_tags((string) $a)));
            $normalized_b = strtolower(trim(wp_strip_all_tags((string) $b)));

            return $normalized_a === $normalized_b;
        }

        /**
         * Extract trip name with fallback to order trips meta.
         *
         * @param int   $post_id     Booking post ID.
         * @param array $place_order Place order data.
         *
         * @return string
         */
        private function extract_trip_name($post_id, $place_order) {
            if (isset($place_order['tname'])) {
                $name = sanitize_text_field($place_order['tname']);
                if ('' !== $name) {
                    return $name;
                }
            }

            $order_trips = get_post_meta($post_id, 'order_trips', true);
            if (is_array($order_trips)) {
                $first = reset($order_trips);
                if (is_array($first) && isset($first['title'])) {
                    $name = sanitize_text_field($first['title']);
                    if ('' !== $name) {
                        return $name;
                    }
                }
            } elseif (is_string($order_trips) && '' !== $order_trips) {
                return sanitize_text_field($order_trips);
            }

            return __('Unknown Trip', 'hr-customer-manager');
        }

        /**
         * Extract raw departure date value from meta.
         *
         * @param int   $post_id     Booking post ID.
         * @param array $place_order Place order data.
         *
         * @return string
         */
        private function extract_departure_raw($post_id, $place_order) {
            if (isset($place_order['datetime'])) {
                $raw = sanitize_text_field($place_order['datetime']);
                if ('' !== $raw) {
                    return $raw;
                }
            }

            $order_trips = get_post_meta($post_id, 'order_trips', true);
            if (is_array($order_trips)) {
                foreach ($order_trips as $trip) {
                    if (is_array($trip) && isset($trip['trip_start_date'])) {
                        $raw = sanitize_text_field($trip['trip_start_date']);
                        if ('' !== $raw) {
                            return $raw;
                        }
                    }
                }
            }

            $meta_departure = get_post_meta($post_id, 'trip_start_date', true);
            if ('' !== $meta_departure) {
                return sanitize_text_field($meta_departure);
            }

            return '';
        }

        /**
         * Prepare departure data including formatted date and days to trip.
         *
         * @param string            $raw_date Raw date string.
         * @param DateTimeZone      $timezone Site timezone.
         * @param DateTimeImmutable $now      Current time.
         *
         * @return array
         */
        private function prepare_departure($raw_date, DateTimeZone $timezone, DateTimeImmutable $now) {
            $raw_date = trim((string) $raw_date);

            if ('' === $raw_date) {
                return [
                    'formatted'    => '',
                    'days_to_trip' => null,
                ];
            }

            $date = date_create_immutable_from_format('Y-m-d', $raw_date, $timezone);
            if (!$date) {
                $date = date_create_immutable($raw_date, $timezone);
            }

            if (!$date) {
                return [
                    'formatted'    => '',
                    'days_to_trip' => null,
                ];
            }

            $days = (int) $now->diff($date)->format('%r%a');

            return [
                'formatted'    => $date->format('Y-m-d'),
                'days_to_trip' => $days,
            ];
        }

        /**
         * Determine if the booking is fully paid.
         *
         * @param int $post_id Booking post ID.
         *
         * @return bool
         */
        private function is_booking_paid($post_id) {
            $total_due = get_post_meta($post_id, 'total_due_amount', true);
            if ('' !== $total_due && is_numeric($total_due)) {
                if ((float) $total_due <= 0.0) {
                    return true;
                }
            }

            $status = get_post_meta($post_id, 'wp_travel_engine_booking_payment_status', true);
            $status = strtolower((string) $status);

            return in_array($status, ['paid', 'completed'], true);
        }

        /**
         * Build trip options map for filters.
         *
         * @param array $bookings Booking rows.
         *
         * @return array
         */
        private function build_trip_options($bookings) {
            $options = [];

            foreach ($bookings as $booking) {
                $trip_name = $booking['trip_name'];
                if (!isset($options[$trip_name])) {
                    $options[$trip_name] = [
                        'name'  => $trip_name,
                        'dates' => [],
                    ];
                }

                if ('' !== $booking['departure_date']) {
                    $options[$trip_name]['dates'][$booking['departure_date']] = $booking['departure_date'];
                }
            }

            ksort($options);

            foreach ($options as &$trip) {
                $dates = $trip['dates'];
                ksort($dates);
                $trip['dates'] = array_values($dates);
            }
            unset($trip);

            return $options;
        }

        /**
         * Apply filters to booking data.
         *
         * @param array $bookings Booking rows.
         * @param array $filters  Filter values.
         *
         * @return array
         */
        private function apply_filters($bookings, $filters) {
            $filtered = [];

            foreach ($bookings as $booking) {
                if (!$filters['nonce_valid']) {
                    // No nonce supplied, treat as default view.
                } else {
                    if ('' !== $filters['trip'] && $booking['trip_name'] !== $filters['trip']) {
                        continue;
                    }

                    if ('' !== $filters['departure'] && $booking['departure_date'] !== $filters['departure']) {
                        continue;
                    }

                    if ('' !== $filters['status'] && $booking['payment_status'] !== $filters['status']) {
                        continue;
                    }

                    if ('' !== $filters['days_range']) {
                        $days = $booking['days_to_trip'];
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
                            $booking['traveler_name'] . ' ' .
                            $booking['traveler_email'] . ' ' .
                            $booking['lead_name'] . ' ' .
                            $booking['lead_email'] . ' ' .
                            $booking['trip_name']
                        );

                        if (false === strpos($haystack, strtolower($filters['search']))) {
                            continue;
                        }
                    }
                }

                $filtered[] = $booking;
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
    }
}
