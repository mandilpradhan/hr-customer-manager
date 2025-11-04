<?php
/**
 * Data provider for the Debug Viewer screen.
 *
 * Enumerates the following WP Travel Engine keys:
 * - wp_travel_engine_booking_status
 * - wp_travel_engine_booking_payment_status
 * - wp_travel_engine_booking_payment_method
 * - wp_travel_engine_booking_payment_gateway
 * - total_paid_amount / paid_amount
 * - total_due_amount / due_amount
 * - cart_info (currency, totals.total, payment_type, payment_link, etc.)
 * - order_trips (ID, title, datetime, _cart_item_object.trip_id, _cart_item_object.trip_date, pax, line_items.pricing_category, line_items.extra_service, package_name)
 * - billing_info (all fields)
 * - wptravelengine_travelers_details (fname, lname, email, phone, country)
 * - user_history
 * - payments (payment_gateway, payment_status, is_due_payment, payable amount/currency, gateway payload)
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('HR_CM_Debug_Viewer_Data')) {
    /**
     * Provides structured booking data for the Debug Viewer.
     */
    class HR_CM_Debug_Viewer_Data {
        /**
         * Maximum number of list rows per array before truncation.
         */
        private const ARRAY_ROW_LIMIT = 50;

        /**
         * Retrieve the 100 most recent booking posts.
         *
         * @param int $limit Number of records to fetch.
         * @return array[] Array of [ 'id' => int, 'label' => string ].
         */
        public static function get_recent_bookings($limit = 100) {
            $query = new WP_Query([
                'post_type'      => 'booking',
                'posts_per_page' => $limit,
                'orderby'        => 'ID',
                'order'          => 'DESC',
                'post_status'    => 'any',
                'no_found_rows'  => true,
                'fields'         => 'ids',
            ]);

            $bookings = [];

            foreach ($query->posts as $booking_id) {
                $post = get_post($booking_id);
                if (!$post) {
                    continue;
                }

                $bookings[] = [
                    'id'    => $booking_id,
                    'label' => self::format_booking_label($booking_id, $post),
                ];
            }

            return $bookings;
        }

        /**
         * Build the structured payload for a booking.
         *
         * @param int $booking_id Booking post ID.
         * @return array|WP_Error Structured payload or error.
         */
        public static function get_booking_payload($booking_id) {
            try {
                $booking_id = (int) $booking_id;
                if ($booking_id <= 0) {
                    return new WP_Error('hrcm_debug_invalid_booking', __('Invalid booking ID.', 'hr-customer-manager'));
                }

                $post = get_post($booking_id);
                if (!$post || 'booking' !== $post->post_type) {
                    return new WP_Error('hrcm_debug_missing_booking', __('Booking not found.', 'hr-customer-manager'));
                }

                $meta = get_post_meta($booking_id);

                $scalar_meta_keys = [
                    'wp_travel_engine_booking_status',
                    'wp_travel_engine_booking_payment_status',
                    'wp_travel_engine_booking_payment_method',
                    'wp_travel_engine_booking_payment_gateway',
                    'total_paid_amount',
                    'paid_amount',
                    'total_due_amount',
                    'due_amount',
                ];

                $scalar_meta = [];
                foreach ($scalar_meta_keys as $key) {
                    $scalar_meta[$key] = self::get_meta_value($meta, $key);
                }

                $cart_info = self::parse_meta_blob(self::get_meta_value($meta, 'cart_info'));
                $order_trips = self::parse_meta_blob(self::get_meta_value($meta, 'order_trips'));
                $billing_info = self::parse_meta_blob(self::get_meta_value($meta, 'billing_info'));
                $travelers = self::parse_meta_blob(self::get_meta_value($meta, 'wptravelengine_travelers_details'));
                $user_history = self::parse_meta_blob(self::get_meta_value($meta, 'user_history'));
                $payments_meta = self::parse_meta_blob(self::get_meta_value($meta, 'payments'));

                $first_trip = self::extract_first_trip($order_trips);
                $payments = self::prepare_payments($payments_meta);

                $computed = self::prepare_computed_data(
                    $cart_info,
                    $first_trip,
                    $travelers,
                    $scalar_meta
                );

                $booking_section = [
                    'post' => [
                        'ID'            => $post->ID,
                        'post_date_gmt' => $post->post_date_gmt ?: $post->post_date,
                    ],
                    'meta' => [
                        'wp_travel_engine_booking_status'        => $scalar_meta['wp_travel_engine_booking_status'],
                        'wp_travel_engine_booking_payment_status' => $scalar_meta['wp_travel_engine_booking_payment_status'],
                        'wp_travel_engine_booking_payment_method' => $scalar_meta['wp_travel_engine_booking_payment_method'],
                        'wp_travel_engine_booking_payment_gateway' => $scalar_meta['wp_travel_engine_booking_payment_gateway'],
                    ],
                ];

                $cart_section = [];
                if (is_array($cart_info)) {
                    $cart_section['cart_info'] = $cart_info;
                }

                if (!self::is_value_empty($computed['currency']) || !self::is_value_empty($computed['total']) || !self::is_value_empty($computed['payment_type'])) {
                    $cart_section['computed'] = array_filter([
                        'currency'     => $computed['currency'],
                        'total'        => $computed['total'],
                        'payment_type' => $computed['payment_type'],
                    ], [self::class, 'filter_empty_values']);
                }

                $order_section = [];
                if ($first_trip) {
                    $order_section['order_trips'] = [$first_trip];
                }

                if (!self::is_value_empty($computed['pax_total']) || !self::is_value_empty($computed['pax_breakdown'])) {
                    $order_section['computed'] = array_filter([
                        'pax_total'     => $computed['pax_total'],
                        'pax_breakdown' => $computed['pax_breakdown'],
                    ], [self::class, 'filter_empty_values']);
                }

                $billing_section = [];
                if (is_array($billing_info)) {
                    $billing_section['billing_info'] = $billing_info;
                }

                $travelers_section = [];
                if (is_array($travelers)) {
                    $travelers_section['wptravelengine_travelers_details'] = $travelers;
                }

                $extras_section = [];
                if ($first_trip && isset($first_trip['line_items']['extra_service'])) {
                    $extras_section['order_trips'] = [
                        0 => [
                            'line_items' => [
                                'extra_service' => $first_trip['line_items']['extra_service'],
                            ],
                        ],
                    ];
                }

                if (!self::is_value_empty($computed['extras_total'])) {
                    $extras_section['computed'] = [
                        'extras_total' => $computed['extras_total'],
                    ];
                }

                $payments_section = [
                    'payments'                => $payments,
                    'generated_payment_link'  => '(generated at send-time via {payment_link} placeholder)',
                ];

                if (!self::is_value_empty($computed['paid']) || !self::is_value_empty($computed['due'])) {
                    $payments_section['computed'] = array_filter([
                        'paid' => $computed['paid'],
                        'due'  => $computed['due'],
                    ], [self::class, 'filter_empty_values']);
                }

                $raw_section = [
                    'cart_info'                        => $cart_info,
                    'order_trips'                      => $order_trips,
                    'billing_info'                     => $billing_info,
                    'wptravelengine_travelers_details' => $travelers,
                    'user_history'                     => $user_history,
                    'payments'                         => $payments_meta,
                ];

                $sections = [
                    'booking'    => self::prepare_section_output($booking_section),
                    'payments'   => self::prepare_section_output($payments_section),
                    'cart'       => self::prepare_section_output($cart_section, 'cart_info'),
                    'order'      => self::prepare_section_output($order_section, 'order_trips'),
                    'billing'    => self::prepare_section_output($billing_section, 'billing_info'),
                    'travelers'  => self::prepare_section_output($travelers_section, 'wptravelengine_travelers_details'),
                    'extras'     => self::prepare_section_output($extras_section, 'order_trips'),
                    'raw'        => self::prepare_section_output($raw_section, '', true, true),
                ];

                $warnings = [];
                if (empty($cart_info)) {
                    $warnings[] = __('Cart information is empty or unavailable.', 'hr-customer-manager');
                }

                return [
                    'sections' => $sections,
                    'warnings' => $warnings,
                ];
            } catch (Exception $exception) {
                $message = WP_DEBUG ? 'HR_CM Debug: ' . $exception->getMessage() : __('Unable to load booking data.', 'hr-customer-manager');
                return new WP_Error('hrcm_debug_exception', $message);
            }
        }

        /**
         * Prepare structured output for a section.
         *
         * @param array $data Section data.
         * @param string $primary_key Optional base key for truncation messaging.
         * @param bool $force_raw_when_empty Whether to preserve raw output even when empty.
         * @param bool $use_json_rows Whether to render root values as JSON strings.
         * @return array
         */
        private static function prepare_section_output($data, $primary_key = '', $force_raw_when_empty = false, $use_json_rows = false) {
            $pretty_rows = [];

            foreach ($data as $root_key => $value) {
                $prefix = '' !== $root_key ? $root_key : $primary_key;

                if ($use_json_rows) {
                    if (self::is_value_empty($value)) {
                        $pretty_rows[] = self::build_row($prefix, null);
                        continue;
                    }

                    $pretty_rows[] = self::build_row(
                        $prefix,
                        wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                    continue;
                }

                if (null === $value || '' === $value || [] === $value) {
                    $pretty_rows[] = self::build_row($prefix, null);
                    continue;
                }

                self::flatten_value($value, $prefix, $pretty_rows);
            }

            if (empty($pretty_rows)) {
                $target_key = '' !== $primary_key ? $primary_key : __('value', 'hr-customer-manager');
                $pretty_rows[] = self::build_row($target_key, null);
            }

            $raw = $force_raw_when_empty || !empty($data)
                ? wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : '';

            return [
                'pretty' => $pretty_rows,
                'raw'    => $raw,
            ];
        }

        /**
         * Convert a value into flattened key paths.
         *
         * @param mixed  $value Value to flatten.
         * @param string $path  Current path.
         * @param array  $rows  Accumulated rows.
         */
        private static function flatten_value($value, $path, array &$rows) {
            if (is_object($value)) {
                $value = (array) $value;
            }

            if (is_array($value)) {
                if (empty($value)) {
                    $rows[] = self::build_row($path, null);
                    return;
                }

                $is_associative = self::is_associative_array($value);
                $index = 0;
                $total = count($value);

                foreach ($value as $key => $child) {
                    if ($index >= self::ARRAY_ROW_LIMIT) {
                        $rows[] = [
                            'key'   => $path,
                            'value' => sprintf('… +%d more (hidden)', $total - self::ARRAY_ROW_LIMIT),
                            'title' => '',
                        ];
                        break;
                    }

                    $index++;
                    $child_path = $is_associative
                        ? ('' === $path ? (string) $key : $path . '.' . $key)
                        : sprintf('%s[%d]', $path, (int) $key);

                    self::flatten_value($child, $child_path, $rows);
                }

                return;
            }

            $rows[] = self::build_row($path, $value);
        }

        /**
         * Build a flattened row.
         *
         * @param string     $key   Key path.
         * @param string|int $value Value.
         * @return array
         */
        private static function build_row($key, $value) {
            $display = self::stringify_value($value);

            return [
                'key'   => $key,
                'value' => $display,
                'title' => self::maybe_format_date_title($key, $display),
            ];
        }

        /**
         * Detect whether an array is associative.
         *
         * @param array $array Array to test.
         * @return bool
         */
        private static function is_associative_array(array $array) {
            if ([] === $array) {
                return false;
            }

            return array_keys($array) !== range(0, count($array) - 1);
        }

        /**
         * Convert a scalar value to a string, respecting ∅ rules.
         *
         * @param mixed $value Value to stringify.
         * @return string
         */
        private static function stringify_value($value) {
            if (null === $value) {
                return '∅';
            }

            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                return '' === $trimmed ? '∅' : $value;
            }

            if ('' === $value) {
                return '∅';
            }

            if (is_scalar($value)) {
                return (string) $value;
            }

            return wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        /**
         * Determine whether a value should be considered empty for output purposes.
         *
         * @param mixed $value Value to evaluate.
         * @return bool
         */
        private static function is_value_empty($value) {
            if (null === $value) {
                return true;
            }

            if (is_string($value) && '' === trim($value)) {
                return true;
            }

            if (is_array($value)) {
                return empty($value);
            }

            return false;
        }

        /**
         * Callback for array_filter to remove empty values while keeping numeric zeroes.
         *
         * @param mixed $value Value to check.
         * @return bool
         */
        private static function filter_empty_values($value) {
            return !self::is_value_empty($value);
        }

        /**
         * Provide ISO-8601 title text for date-like fields.
         *
         * @param string $key   Key path.
         * @param string $value Display value.
         * @return string
         */
        private static function maybe_format_date_title($key, $value) {
            if ('∅' === $value || '' === $value) {
                return '';
            }

            if (!preg_match('/date|time/i', $key)) {
                return '';
            }

            $timestamp = strtotime($value);
            if (false === $timestamp) {
                return '';
            }

            return wp_date(DATE_ATOM, $timestamp);
        }

        /**
         * Retrieve a normalized meta value.
         *
         * @param array  $meta All meta.
         * @param string $key  Meta key.
         * @return mixed
         */
        private static function get_meta_value(array $meta, $key) {
            if (!isset($meta[$key])) {
                return null;
            }

            $value = $meta[$key];
            if (is_array($value) && count($value) === 1) {
                return $value[0];
            }

            return $value;
        }

        /**
         * Safely parse a meta blob value.
         *
         * @param mixed $raw Raw meta value.
         * @return mixed
         */
        private static function parse_meta_blob($raw) {
            if (null === $raw || '' === $raw) {
                return null;
            }

            if (is_array($raw) || is_object($raw)) {
                return $raw;
            }

            $maybe_unserialized = maybe_unserialize($raw);

            if ($maybe_unserialized !== $raw) {
                return $maybe_unserialized;
            }

            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (JSON_ERROR_NONE === json_last_error()) {
                    return $decoded;
                }
            }

            return $raw;
        }

        /**
         * Extract the first trip entry from order_trips meta.
         *
         * @param mixed $order_trips Order trips meta.
         * @return array|null
         */
        private static function extract_first_trip($order_trips) {
            if (!is_array($order_trips) || empty($order_trips)) {
                return null;
            }

            $first_trip = reset($order_trips);
            if (is_array($order_trips) && self::is_associative_array($order_trips) && isset($order_trips['0'])) {
                $first_trip = $order_trips['0'];
            }

            if (!is_array($first_trip)) {
                return null;
            }

            return $first_trip;
        }

        /**
         * Prepare payment entries for output.
         *
         * @param mixed $payments_meta Payments meta.
         * @return array
         */
        private static function prepare_payments($payments_meta) {
            if (is_string($payments_meta) && is_numeric($payments_meta)) {
                $payments_meta = [(int) $payments_meta];
            }

            if (!is_array($payments_meta)) {
                return [];
            }

            $payments = [];
            foreach ($payments_meta as $index => $payment_id) {
                $payment_id = (int) $payment_id;
                if ($payment_id <= 0) {
                    $payments[] = ['missing' => true];
                    continue;
                }

                $payment_post = get_post($payment_id);
                if (!$payment_post) {
                    $payments[] = ['missing' => true];
                    continue;
                }

                $meta = get_post_meta($payment_id);

                $entry = [
                    'post_type'   => $payment_post->post_type,
                    'post_status' => $payment_post->post_status,
                ];

                $gateway_meta_keys = ['payment_gateway', 'payment_status', 'is_due_payment'];
                foreach ($gateway_meta_keys as $meta_key) {
                    $entry[$meta_key] = self::get_meta_value($meta, $meta_key);
                }

                $entry['payable'] = self::parse_meta_blob(self::get_meta_value($meta, 'payable'));

                $extra_meta = [];
                foreach ($meta as $meta_key => $values) {
                    if (in_array($meta_key, array_merge($gateway_meta_keys, ['payable']), true)) {
                        continue;
                    }

                    $parsed_value = self::parse_meta_blob(self::get_meta_value([$meta_key => $values], $meta_key));
                    $extra_meta[$meta_key] = $parsed_value;
                }

                if (!empty($extra_meta)) {
                    $entry['meta'] = $extra_meta;
                }

                $payments[] = $entry;
            }

            return $payments;
        }

        /**
         * Prepare computed diagnostic fields.
         *
         * @param mixed $cart_info  Cart information.
         * @param mixed $first_trip First trip information.
         * @param mixed $travelers  Traveler information.
         * @param array $scalar_meta Scalar meta values.
         * @return array
         */
        private static function prepare_computed_data($cart_info, $first_trip, $travelers, array $scalar_meta) {
            $cart_array = is_array($cart_info) ? $cart_info : [];
            $trip_array = is_array($first_trip) ? $first_trip : [];
            $travelers_array = is_array($travelers) ? $travelers : [];

            $total_paid = $scalar_meta['total_paid_amount'];
            if (null === $total_paid || '' === $total_paid) {
                $total_paid = $scalar_meta['paid_amount'];
            }

            $total_due = $scalar_meta['total_due_amount'];
            if (null === $total_due || '' === $total_due) {
                $total_due = $scalar_meta['due_amount'];
            }

            $pax_total = 0;
            if (isset($trip_array['pax']) && is_array($trip_array['pax'])) {
                foreach ($trip_array['pax'] as $pax_value) {
                    if (is_numeric($pax_value)) {
                        $pax_total += (int) $pax_value;
                    }
                }
            }

            if (0 === $pax_total && is_array($travelers_array)) {
                $pax_total = count($travelers_array);
            }

            $pax_breakdown = [];
            if (isset($trip_array['line_items']['pricing_category']) && is_array($trip_array['line_items']['pricing_category'])) {
                foreach ($trip_array['line_items']['pricing_category'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $pax_breakdown[] = [
                        'label'    => isset($item['label']) ? $item['label'] : null,
                        'quantity' => isset($item['quantity']) ? $item['quantity'] : null,
                    ];
                }
            }

            $extras_total = 0;
            if (isset($trip_array['line_items']['extra_service']) && is_array($trip_array['line_items']['extra_service'])) {
                foreach ($trip_array['line_items']['extra_service'] as $extra) {
                    if (!is_array($extra) || !isset($extra['total'])) {
                        continue;
                    }

                    if (is_numeric($extra['total'])) {
                        $extras_total += $extra['total'];
                    }
                }
            }

            return [
                'currency'       => isset($cart_array['currency']) ? $cart_array['currency'] : null,
                'total'          => isset($cart_array['totals']['total']) ? $cart_array['totals']['total'] : null,
                'paid'           => $total_paid,
                'due'            => $total_due,
                'payment_type'   => isset($cart_array['payment_type']) ? $cart_array['payment_type'] : null,
                'pax_total'      => $pax_total,
                'pax_breakdown'  => $pax_breakdown,
                'extras_total'   => $extras_total,
            ];
        }

        /**
         * Format the booking selector label.
         *
         * @param int     $booking_id Booking ID.
         * @param WP_Post $post       Post object.
         * @return string
         */
        private static function format_booking_label($booking_id, $post) {
            $order_trips = self::parse_meta_blob(get_post_meta($booking_id, 'order_trips', true));
            $first_trip = self::extract_first_trip($order_trips);

            $trip_title = '';
            $start_date = '';

            if (is_array($first_trip)) {
                if (isset($first_trip['trip_title']) && '' !== $first_trip['trip_title']) {
                    $trip_title = $first_trip['trip_title'];
                } elseif (isset($first_trip['title']) && '' !== $first_trip['title']) {
                    $trip_title = $first_trip['title'];
                }

                if (isset($first_trip['datetime']) && '' !== $first_trip['datetime']) {
                    $start_date = $first_trip['datetime'];
                } elseif (isset($first_trip['_cart_item_object']['trip_date'])) {
                    $start_date = $first_trip['_cart_item_object']['trip_date'];
                }
            }

            if ('' === $trip_title) {
                $trip_title = $post->post_title;
            }

            if ('' === $start_date) {
                $start_date = $post->post_date;
            }

            return sprintf('#%1$d · %2$s · %3$s', $booking_id, $trip_title, $start_date);
        }
    }
}

