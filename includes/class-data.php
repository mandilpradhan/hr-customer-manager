<?php
/**
 * Data normalization helpers for bookings.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('HR_CM_Data')) {
    /**
     * Provides normalized booking data helpers.
     */
    class HR_CM_Data {
        /**
         * Retrieve normalized traveler records for a booking.
         *
         * @param int $booking_id Booking post ID.
         *
         * @return array
         */
        public static function get_travelers($booking_id) {
            $travelers_meta = self::parse_meta_value(get_post_meta($booking_id, 'wptravelengine_travelers_details', true));

            $travelers = [];

            if (is_array($travelers_meta)) {
                foreach ($travelers_meta as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $first = isset($row['fname']) ? sanitize_text_field($row['fname']) : '';
                    $last  = isset($row['lname']) ? sanitize_text_field($row['lname']) : '';
                    $email = isset($row['email']) ? sanitize_email($row['email']) : '';

                    $name = trim($first . ' ' . $last);
                    if ('' === $name) {
                        $name = __('Unknown Traveler', 'hr-customer-manager');
                    }

                    $travelers[] = [
                        'name'  => $name,
                        'email' => $email,
                    ];
                }
            }

            if (empty($travelers)) {
                $place_order = self::get_place_order($booking_id);
                $booking     = isset($place_order['booking']) && is_array($place_order['booking']) ? $place_order['booking'] : [];

                $first = isset($booking['fname']) ? sanitize_text_field($booking['fname']) : '';
                $last  = isset($booking['lname']) ? sanitize_text_field($booking['lname']) : '';
                $email = isset($booking['email']) ? sanitize_email($booking['email']) : '';

                $name = trim($first . ' ' . $last);
                if ('' === $name) {
                    $name = __('Unknown Traveler', 'hr-customer-manager');
                }

                $travelers[] = [
                    'name'  => $name,
                    'email' => $email,
                ];
            }

            if (empty($travelers)) {
                $travelers[] = [
                    'name'  => __('Unknown Traveler', 'hr-customer-manager'),
                    'email' => '',
                ];
            }

            return array_map([__CLASS__, 'normalize_traveler'], $travelers);
        }

        /**
         * Retrieve normalized lead traveler information.
         *
         * @param int $booking_id Booking post ID.
         *
         * @return array
         */
        public static function get_lead($booking_id) {
            $place_order = self::get_place_order($booking_id);
            $lead_data   = isset($place_order['lead_traveler']) && is_array($place_order['lead_traveler'])
                ? $place_order['lead_traveler']
                : [];

            $first = isset($lead_data['fname']) ? sanitize_text_field($lead_data['fname']) : '';
            $last  = isset($lead_data['lname']) ? sanitize_text_field($lead_data['lname']) : '';
            $email = isset($lead_data['email']) ? sanitize_email($lead_data['email']) : '';

            if ('' === $first) {
                $meta_first = get_post_meta($booking_id, 'lead_fname', true);
                if ('' === $meta_first) {
                    $meta_first = get_post_meta($booking_id, 'lead_first_name', true);
                }
                $first = sanitize_text_field($meta_first);
            }

            if ('' === $last) {
                $meta_last = get_post_meta($booking_id, 'lead_lname', true);
                if ('' === $meta_last) {
                    $meta_last = get_post_meta($booking_id, 'lead_last_name', true);
                }
                $last = sanitize_text_field($meta_last);
            }

            if ('' === $email) {
                $meta_email = get_post_meta($booking_id, 'lead_email', true);
                $email      = sanitize_email($meta_email);
            }

            $name = trim($first . ' ' . $last);

            if ('' === $name && '' === $email) {
                $travelers = self::get_travelers($booking_id);
                $first_traveler = reset($travelers);
                if (is_array($first_traveler)) {
                    $name  = isset($first_traveler['name']) ? $first_traveler['name'] : '';
                    $email = isset($first_traveler['email']) ? $first_traveler['email'] : '';
                }
            }

            return [
                'name'  => $name,
                'email' => $email,
            ];
        }

        /**
         * Retrieve trip context for a booking.
         *
         * @param int $booking_id Booking post ID.
         *
         * @return array
         */
        public static function get_trip($booking_id) {
            $place_order = self::get_place_order($booking_id);

            $trip_id = 0;
            if (isset($place_order['tid'])) {
                $trip_id = (int) $place_order['tid'];
            }

            $trip_name = '';
            if (isset($place_order['tname'])) {
                $trip_name = sanitize_text_field($place_order['tname']);
            }

            if ('' === $trip_name) {
                $order_trips_meta = get_post_meta($booking_id, 'order_trips', true);
                $order_trips      = self::parse_meta_value($order_trips_meta);

                if (is_array($order_trips)) {
                    foreach ($order_trips as $trip) {
                        if (is_array($trip) && isset($trip['title'])) {
                            $trip_name = sanitize_text_field($trip['title']);
                            if ($trip_id <= 0 && isset($trip['id'])) {
                                $trip_id = (int) $trip['id'];
                            }
                            if ($trip_id <= 0 && isset($trip['trip_id'])) {
                                $trip_id = (int) $trip['trip_id'];
                            }
                            if ($trip_id <= 0 && isset($trip['tid'])) {
                                $trip_id = (int) $trip['tid'];
                            }
                            if ('' !== $trip_name) {
                                break;
                            }
                        } elseif (is_string($trip) && '' === $trip_name) {
                            $trip_name = sanitize_text_field($trip);
                        }
                    }
                } elseif (is_string($order_trips)) {
                    $trip_name = sanitize_text_field($order_trips);
                }
            }

            if ('' === $trip_name) {
                $trip_name = __('Unknown Trip', 'hr-customer-manager');
            }

            $date = '';
            if (isset($place_order['datetime'])) {
                $date = sanitize_text_field($place_order['datetime']);
            }

            if ('' === $date) {
                $order_trips_meta = get_post_meta($booking_id, 'order_trips', true);
                $order_trips      = self::parse_meta_value($order_trips_meta);
                if (is_array($order_trips)) {
                    foreach ($order_trips as $trip) {
                        if (!is_array($trip)) {
                            continue;
                        }

                        if (isset($trip['trip_start_date'])) {
                            $candidate = sanitize_text_field($trip['trip_start_date']);
                            if ('' !== $candidate) {
                                $date = $candidate;
                                break;
                            }
                        }
                    }
                }
            }

            if ('' === $date) {
                $meta_date = get_post_meta($booking_id, 'trip_start_date', true);
                if ('' !== $meta_date) {
                    $date = sanitize_text_field($meta_date);
                }
            }

            $date = self::normalize_date($date);

            return [
                'id'   => $trip_id,
                'name' => $trip_name,
                'date' => $date,
            ];
        }

        /**
         * Retrieve payment context for a booking.
         *
         * @param int $booking_id Booking post ID.
         *
         * @return array
         */
        public static function get_payment($booking_id) {
            $due  = self::to_float(get_post_meta($booking_id, 'total_due_amount', true));
            $paid = self::to_float(get_post_meta($booking_id, 'total_paid_amount', true));

            if (null === $paid) {
                $paid = self::to_float(get_post_meta($booking_id, 'paid_amount', true));
            }

            $payment_status = sanitize_text_field(get_post_meta($booking_id, 'wp_travel_engine_booking_payment_status', true));
            $booking_status = sanitize_text_field(get_post_meta($booking_id, 'wp_travel_engine_booking_status', true));

            return [
                'due'      => null === $due ? 0.0 : $due,
                'paid'     => null === $paid ? 0.0 : $paid,
                'p_status' => strtolower($payment_status),
                'b_status' => strtolower($booking_status),
            ];
        }

        /**
         * Retrieve all distinct trip names stored within booking settings.
         *
         * @return array
         */
        public static function get_all_booking_trip_names() {
            global $wpdb;

            $meta_key = 'wp_travel_engine_booking_setting';
            $table    = $wpdb->postmeta;

            $results = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$table} WHERE meta_key = %s",
                    $meta_key
                )
            );

            if (empty($results)) {
                return [];
            }

            $names = [];

            foreach ($results as $value) {
                $parsed = self::parse_meta_value($value);
                if (!is_array($parsed)) {
                    continue;
                }

                $place_order = isset($parsed['place_order']) && is_array($parsed['place_order'])
                    ? $parsed['place_order']
                    : [];

                if (empty($place_order['tname'])) {
                    continue;
                }

                $name = sanitize_text_field($place_order['tname']);
                if ('' === $name) {
                    continue;
                }

                $names[] = $name;
            }

            if (empty($names)) {
                return [];
            }

            $names = array_values(array_unique($names, SORT_STRING));

            usort(
                $names,
                static function ($a, $b) {
                    return strnatcasecmp($a, $b);
                }
            );

            return $names;
        }

        /**
         * Calculate the number of days until a trip date.
         *
         * @param string       $yyyy_mm_dd Trip date string.
         * @param DateTimeZone $tz         Site timezone.
         *
         * @return int|null
         */
        public static function days_to_trip($yyyy_mm_dd, DateTimeZone $tz) {
            $date_string = trim((string) $yyyy_mm_dd);
            if ('' === $date_string) {
                return null;
            }

            $date = DateTimeImmutable::createFromFormat('Y-m-d', $date_string, $tz);
            if (!$date) {
                $date = date_create_immutable($date_string, $tz);
            }

            if (!$date) {
                return null;
            }

            $date  = $date->setTime(0, 0, 0);
            $now   = new DateTimeImmutable('now', $tz);
            $today = $now->setTime(0, 0, 0);

            $diff = $today->diff($date);

            return (int) $diff->format('%r%a');
        }

        /**
         * Normalize a traveler array shape.
         *
         * @param array $traveler Traveler data.
         *
         * @return array
         */
        private static function normalize_traveler($traveler) {
            $name  = isset($traveler['name']) ? sanitize_text_field($traveler['name']) : '';
            $email = isset($traveler['email']) ? sanitize_email($traveler['email']) : '';

            if ('' === $name) {
                $name = __('Unknown Traveler', 'hr-customer-manager');
            }

            return [
                'name'  => $name,
                'email' => $email,
            ];
        }

        /**
         * Retrieve the place order array.
         *
         * @param int $booking_id Booking post ID.
         *
         * @return array
         */
        private static function get_place_order($booking_id) {
            $settings = self::parse_meta_value(get_post_meta($booking_id, 'wp_travel_engine_booking_setting', true));

            if (!is_array($settings)) {
                return [];
            }

            return isset($settings['place_order']) && is_array($settings['place_order'])
                ? $settings['place_order']
                : [];
        }

        /**
         * Attempt to normalize a date string to Y-m-d.
         *
         * @param string $date_string Raw date string.
         *
         * @return string
         */
        private static function normalize_date($date_string) {
            $date_string = trim((string) $date_string);

            if ('' === $date_string) {
                return '';
            }

            $timezone = wp_timezone();

            $date = DateTimeImmutable::createFromFormat('Y-m-d', $date_string, $timezone);
            if (!$date) {
                $date = date_create_immutable($date_string, $timezone);
            }

            if (!$date) {
                return '';
            }

            return $date->format('Y-m-d');
        }

        /**
         * Safely parse stored meta values that may be serialized or JSON.
         *
         * @param mixed $value Raw meta value.
         *
         * @return mixed
         */
        private static function parse_meta_value($value) {
            if (is_array($value)) {
                return $value;
            }

            if (is_object($value)) {
                return json_decode(wp_json_encode($value), true);
            }

            if (!is_string($value)) {
                return $value;
            }

            $maybe_unserialized = maybe_unserialize($value);
            if ($maybe_unserialized !== $value) {
                return self::parse_meta_value($maybe_unserialized);
            }

            $trimmed = trim($value);
            if ('' === $trimmed) {
                return $trimmed;
            }

            if (self::looks_like_json($trimmed)) {
                $decoded = json_decode($trimmed, true);
                if (JSON_ERROR_NONE === json_last_error()) {
                    return $decoded;
                }
            }

            return $value;
        }

        /**
         * Determine if a string appears to be JSON encoded.
         *
         * @param string $value String value.
         *
         * @return bool
         */
        private static function looks_like_json($value) {
            if (!is_string($value)) {
                return false;
            }

            $value = ltrim($value);

            if ('' === $value) {
                return false;
            }

            $first = substr($value, 0, 1);
            $last  = substr($value, -1);

            return ('{' === $first && '}' === $last)
                || ('[' === $first && ']' === $last);
        }

        /**
         * Cast mixed values to float.
         *
         * @param mixed $value Raw value.
         *
         * @return float|null
         */
        private static function to_float($value) {
            if (is_float($value)) {
                return $value;
            }

            if (is_int($value)) {
                return (float) $value;
            }

            if (is_string($value)) {
                $normalized = trim($value);
                if ('' === $normalized) {
                    return null;
                }

                if (is_numeric($normalized)) {
                    return (float) $normalized;
                }
            }

            return null;
        }
    }
}
