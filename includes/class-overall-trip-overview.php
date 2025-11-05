<?php
/**
 * Data provider for the Overall Trip Overview admin screen.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('HR_CM_Overall_Trip_Overview')) {
    /**
     * Prepares summary data for trip departures and bookings.
     */
    class HR_CM_Overall_Trip_Overview {
        /**
         * Option storing the indexed departures data.
         */
        const OPTION_KEY = 'wptravelengine_indexed_trips_by_dates';

        /**
         * Retrieve table data for the Overall view.
         *
         * @return array{
         *     columns: string[],
         *     rows: array<int, array{0:int,1:string,2:string,3:int,4:string,5:int|string,6:int,7:int}>
         * }
         */
        public static function get_overall_table_data() {
            $timezone = wp_timezone();
            $today    = new DateTimeImmutable('today', $timezone);
            $today_string = $today->format('Y-m-d');

            $trips       = self::get_trip_index();
            $departures  = self::build_departure_index($today_string, $timezone);
            $booking_pax = self::aggregate_booking_pax($today_string, $timezone);

            $columns = [
                __('Trip Code', 'hr-customer-manager'),
                __('Trip', 'hr-customer-manager'),
                __('Country', 'hr-customer-manager'),
                __('Departures', 'hr-customer-manager'),
                __('Next Departure', 'hr-customer-manager'),
                __('Days to Next', 'hr-customer-manager'),
                __('Total Pax', 'hr-customer-manager'),
                __('Pax on Next Departure', 'hr-customer-manager'),
            ];

            $rows = [];

            foreach ($trips as $trip_id => $trip) {
                $departure_info = isset($departures[$trip_id]) ? $departures[$trip_id] : [
                    'dates' => [],
                    'next'  => '',
                ];

                $dates = isset($departure_info['dates']) && is_array($departure_info['dates'])
                    ? $departure_info['dates']
                    : [];

                $next_iso = isset($departure_info['next']) ? (string) $departure_info['next'] : '';

                $departures_count = count($dates);

                $next_display = '—';
                $days_to_next = '—';

                if ('' !== $next_iso) {
                    $next_date = date_create_immutable($next_iso, $timezone);
                    if (!$next_date) {
                        $next_date = DateTimeImmutable::createFromFormat('Y-m-d', $next_iso, $timezone);
                    }

                    if ($next_date) {
                        $timestamp   = $next_date->getTimestamp();
                        $next_display = date_i18n('F j, Y', $timestamp);
                        if ($next_date >= $today) {
                            $interval     = $today->diff($next_date);
                            $days_to_next = (int) $interval->format('%a');
                        } else {
                            $days_to_next = 0;
                        }
                    }
                }

                $pax_totals = isset($booking_pax[$trip_id]) ? $booking_pax[$trip_id] : [
                    'total' => 0,
                    'dates' => [],
                ];

                $total_pax = isset($pax_totals['total']) ? (int) $pax_totals['total'] : 0;
                $pax_dates = isset($pax_totals['dates']) && is_array($pax_totals['dates']) ? $pax_totals['dates'] : [];
                $pax_next  = 0;
                if ('' !== $next_iso && isset($pax_dates[$next_iso])) {
                    $pax_next = (int) $pax_dates[$next_iso];
                }

                $rows[] = [
                    (int) $trip_id,
                    isset($trip['title']) ? $trip['title'] : '',
                    isset($trip['country']) ? $trip['country'] : '',
                    $departures_count,
                    $next_display,
                    $days_to_next,
                    $total_pax,
                    $pax_next,
                ];
            }

            return [
                'columns' => $columns,
                'rows'    => $rows,
            ];
        }

        /**
         * Retrieve an index of published trips.
         *
         * @return array<int, array{title:string, country:string}>
         */
        private static function get_trip_index() {
            if (!class_exists('WP_Query')) {
                return [];
            }

            $trips = [];
            $paged = 1;
            $per_page = 100;

            do {
                $query = new WP_Query([
                    'post_type'      => 'trip',
                    'post_status'    => 'publish',
                    'posts_per_page' => $per_page,
                    'paged'          => $paged,
                    'fields'         => 'ids',
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                    'no_found_rows'  => true,
                ]);

                if (!$query->have_posts()) {
                    break;
                }

                foreach ($query->posts as $trip_id) {
                    $trip_id = (int) $trip_id;
                    if ($trip_id <= 0) {
                        continue;
                    }

                    $trips[$trip_id] = [
                        'title'   => get_the_title($trip_id),
                        'country' => self::get_trip_country($trip_id),
                    ];
                }

                $paged++;
            } while ($query->post_count === $per_page);

            return $trips;
        }

        /**
         * Retrieve the country label for a trip.
         *
         * @param int $trip_id Trip post ID.
         *
         * @return string
         */
        private static function get_trip_country($trip_id) {
            $taxonomies = ['country', 'destination'];

            foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($trip_id, $taxonomy);
                if (is_wp_error($terms) || empty($terms)) {
                    continue;
                }

                $term = reset($terms);
                if ($term && isset($term->name)) {
                    return sanitize_text_field($term->name);
                }
            }

            return '';
        }

        /**
         * Build an index of future departures keyed by trip ID.
         *
         * @param string        $today_string Y-m-d for today in site TZ.
         * @param DateTimeZone  $timezone     Site timezone.
         *
         * @return array<int, array{dates: string[], next: string}>
         */
        private static function build_departure_index($today_string, DateTimeZone $timezone) {
            $raw_option = get_option(self::OPTION_KEY, []);
            $data       = self::parse_mixed_value($raw_option);

            if (!is_array($data)) {
                return [];
            }

            $index = [];

            foreach ($data as $entries) {
                $entries = self::parse_mixed_value($entries);
                if (!is_array($entries)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $trip_id = self::extract_trip_id($entry);
                    if ($trip_id <= 0) {
                        continue;
                    }

                    $date_raw = self::extract_trip_date($entry);
                    $date_iso = self::normalize_date($date_raw, $timezone);

                    if ('' === $date_iso || $date_iso < $today_string) {
                        continue;
                    }

                    if (!isset($index[$trip_id])) {
                        $index[$trip_id] = [
                            'dates' => [],
                            'next'  => '',
                        ];
                    }

                    $index[$trip_id]['dates'][] = $date_iso;
                }
            }

            foreach ($index as $trip_id => $info) {
                $dates = array_values(array_filter(array_map('strval', $info['dates'])));
                $dates = array_unique($dates);
                sort($dates);

                $index[$trip_id] = [
                    'dates' => $dates,
                    'next'  => isset($dates[0]) ? $dates[0] : '',
                ];
            }

            return $index;
        }

        /**
         * Aggregate passenger totals for future bookings.
         *
         * @param string        $today_string Y-m-d for today in site TZ.
         * @param DateTimeZone  $timezone     Site timezone.
         *
         * @return array<int, array{total:int, dates: array<string,int>}>
         */
        private static function aggregate_booking_pax($today_string, DateTimeZone $timezone) {
            if (!class_exists('WP_Query')) {
                return [];
            }

            $booking_ids = self::get_booking_ids();
            if (empty($booking_ids)) {
                return [];
            }

            update_meta_cache('post', $booking_ids);

            $totals = [];
            $excluded_statuses = ['cancelled', 'refunded'];

            foreach ($booking_ids as $booking_id) {
                $booking_id = (int) $booking_id;
                if ($booking_id <= 0) {
                    continue;
                }

                $status = get_post_meta($booking_id, 'wp_travel_engine_booking_status', true);
                if (is_string($status)) {
                    $status = sanitize_key($status);
                } else {
                    $status = '';
                }

                if (in_array($status, $excluded_statuses, true)) {
                    continue;
                }

                $trip_context = self::extract_booking_trip($booking_id, $timezone);
                if (empty($trip_context)) {
                    continue;
                }

                $trip_id = (int) $trip_context['trip_id'];
                $date_iso = (string) $trip_context['date'];
                $pax      = (int) $trip_context['pax'];

                if ($trip_id <= 0 || '' === $date_iso || $date_iso < $today_string || $pax <= 0) {
                    continue;
                }

                if (!isset($totals[$trip_id])) {
                    $totals[$trip_id] = [
                        'total' => 0,
                        'dates' => [],
                    ];
                }

                $totals[$trip_id]['total'] += $pax;

                if (!isset($totals[$trip_id]['dates'][$date_iso])) {
                    $totals[$trip_id]['dates'][$date_iso] = 0;
                }

                $totals[$trip_id]['dates'][$date_iso] += $pax;
            }

            return $totals;
        }

        /**
         * Retrieve all booking IDs for aggregation.
         *
         * @return int[]
         */
        private static function get_booking_ids() {
            $ids      = [];
            $paged    = 1;
            $per_page = 200;

            do {
                $query = new WP_Query([
                    'post_type'      => 'booking',
                    'post_status'    => 'any',
                    'posts_per_page' => $per_page,
                    'paged'          => $paged,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                ]);

                if (!$query->have_posts()) {
                    break;
                }

                foreach ($query->posts as $booking_id) {
                    $ids[] = (int) $booking_id;
                }

                $paged++;
            } while ($query->post_count === $per_page);

            return $ids;
        }

        /**
         * Extract normalized trip info for a booking.
         *
         * @param int           $booking_id Booking post ID.
         * @param DateTimeZone  $timezone   Site timezone.
         *
         * @return array{trip_id:int,date:string,pax:int}|null
         */
        private static function extract_booking_trip($booking_id, DateTimeZone $timezone) {
            $order_trips_meta = get_post_meta($booking_id, 'order_trips', true);

            if (empty($order_trips_meta)) {
                error_log(sprintf('HR_CM overall trip overview: Missing order_trips for booking %d', $booking_id));
                return null;
            }

            $order_trips = self::parse_mixed_value($order_trips_meta);

            if (!is_array($order_trips) || empty($order_trips)) {
                error_log(sprintf('HR_CM overall trip overview: Malformed order_trips for booking %d', $booking_id));
                return null;
            }

            $first_trip = null;
            foreach ($order_trips as $item) {
                if (is_array($item)) {
                    $first_trip = $item;
                    break;
                }
            }

            if (!is_array($first_trip)) {
                error_log(sprintf('HR_CM overall trip overview: No trip entry found in order_trips for booking %d', $booking_id));
                return null;
            }

            $trip_id = self::extract_trip_id($first_trip);
            if ($trip_id <= 0 && isset($first_trip['_cart_item_object']) && is_array($first_trip['_cart_item_object'])) {
                $trip_id = self::extract_trip_id($first_trip['_cart_item_object']);
            }

            if ($trip_id <= 0) {
                error_log(sprintf('HR_CM overall trip overview: Unable to resolve trip_id for booking %d', $booking_id));
                return null;
            }

            $date_raw = self::extract_trip_date($first_trip);
            if ('' === $date_raw && isset($first_trip['_cart_item_object']) && is_array($first_trip['_cart_item_object'])) {
                $date_raw = self::extract_trip_date($first_trip['_cart_item_object']);
            }

            $date_iso = self::normalize_date($date_raw, $timezone);
            if ('' === $date_iso) {
                error_log(sprintf('HR_CM overall trip overview: Unable to parse trip date for booking %d', $booking_id));
                return null;
            }

            $pax = self::sum_pax($first_trip);
            if (0 === $pax && isset($first_trip['_cart_item_object']) && is_array($first_trip['_cart_item_object'])) {
                $pax = self::sum_pax($first_trip['_cart_item_object']);
            }

            return [
                'trip_id' => (int) $trip_id,
                'date'    => $date_iso,
                'pax'     => (int) $pax,
            ];
        }

        /**
         * Extract a trip identifier from a data entry.
         *
         * @param array $entry Trip entry data.
         *
         * @return int
         */
        private static function extract_trip_id(array $entry) {
            $keys = ['trip_id', 'ID', 'id', 'tid', 'tripId'];
            foreach ($keys as $key) {
                if (isset($entry[$key])) {
                    return (int) $entry[$key];
                }
            }

            return 0;
        }

        /**
         * Extract the raw trip date string.
         *
         * @param array $entry Trip entry data.
         *
         * @return string
         */
        private static function extract_trip_date(array $entry) {
            $keys = ['datetime', 'trip_date', 'date', 'departure_date'];
            foreach ($keys as $key) {
                if (isset($entry[$key])) {
                    return (string) $entry[$key];
                }
            }

            return '';
        }

        /**
         * Normalize a date string to ISO Y-m-d.
         *
         * @param string       $value    Raw date string.
         * @param DateTimeZone $timezone Site timezone.
         *
         * @return string
         */
        private static function normalize_date($value, DateTimeZone $timezone) {
            $value = is_string($value) ? trim($value) : '';
            if ('' === $value) {
                return '';
            }

            $date = date_create_immutable($value, $timezone);
            if (!$date) {
                $patterns = ['Y-m-d H:i:s', 'Y-m-d'];
                foreach ($patterns as $pattern) {
                    $date = DateTimeImmutable::createFromFormat($pattern, $value, $timezone);
                    if ($date instanceof DateTimeImmutable) {
                        break;
                    }
                }
            }

            if (!$date) {
                if (preg_match('/(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
                    $date = date_create_immutable($matches[1], $timezone);
                }
            }

            if (!$date) {
                return '';
            }

            return $date->format('Y-m-d');
        }

        /**
         * Sum passenger counts from an entry.
         *
         * @param array $entry Trip entry data.
         *
         * @return int
         */
        private static function sum_pax(array $entry) {
            if (!isset($entry['pax'])) {
                return 0;
            }

            $pax = self::parse_mixed_value($entry['pax']);
            if (!is_array($pax)) {
                return 0;
            }

            $total = 0;
            foreach ($pax as $value) {
                if (is_numeric($value)) {
                    $total += (int) $value;
                }
            }

            return $total;
        }

        /**
         * Parse mixed values that may be serialized or JSON.
         *
         * @param mixed $value Raw value.
         *
         * @return mixed
         */
        private static function parse_mixed_value($value) {
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
                return self::parse_mixed_value($maybe_unserialized);
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
         * Determine if a string appears to contain JSON.
         *
         * @param string $value Value to inspect.
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

            return ('{' === $first && '}' === $last) || ('[' === $first && ']' === $last);
        }
    }
}
