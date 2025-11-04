<?php
/**
 * Overall trip view data provider.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('HR_CM_Overall_Trip_View')) {
    /**
     * Provides aggregated trip data for the overall trip view screen.
     */
    class HR_CM_Overall_Trip_View {
        /**
         * Singleton instance.
         *
         * @var HR_CM_Overall_Trip_View|null
         */
        private static $instance = null;

        /**
         * Screen option key for per-page preference.
         */
        const PER_PAGE_OPTION = 'hrcm_overall_trip_view_per_page';

        /**
         * Default rows per page.
         */
        const DEFAULT_PER_PAGE = 25;

        /**
         * Screen option key for enabling debug JSON links.
         */
        const DEBUG_OPTION = 'hrcm_overall_trip_view_debug';

        /**
         * Transient prefix for departure data.
         */
        const DATES_TRANSIENT_PREFIX = 'hrcm_trip_dates_';

        /**
         * Transient prefix for booking aggregation data.
         */
        const BOOKING_TRANSIENT_PREFIX = 'hrcm_trip_booking_';

        /**
         * Transient prefix for logging throttling.
         */
        const ERROR_TRANSIENT_PREFIX = 'hrcm_trip_dates_error_';

        /**
         * Retrieve singleton instance.
         *
         * @return HR_CM_Overall_Trip_View
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
            add_action('wp_ajax_hrcm_overall_trip_view', [$this, 'handle_request']);
            add_action('save_post_booking', [$this, 'invalidate_booking_cache'], 10, 3);
        }

        /**
         * Handle AJAX requests for aggregated trip data.
         */
        public function handle_request() {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Unauthorized', 'hr-customer-manager')], 403);
            }

            check_ajax_referer('hrcm_overall_trip_view', 'nonce');

            $per_page = isset($_POST['per_page']) ? (int) wp_unslash($_POST['per_page']) : self::get_user_per_page();
            $per_page = max(1, min(100, $per_page));

            $page = isset($_POST['page']) ? (int) wp_unslash($_POST['page']) : 1;
            $page = max(1, $page);

            $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';

            $orderby_input = isset($_POST['orderby']) ? sanitize_key(wp_unslash($_POST['orderby'])) : 'title';
            $order_input   = isset($_POST['order']) ? strtolower(sanitize_key(wp_unslash($_POST['order']))) : 'asc';

            $orderby_map = [
                'id'           => 'ID',
                'trip_id'      => 'ID',
                'code'         => 'ID',
                'title'        => 'title',
                'trip'         => 'title',
                'country'      => 'country',
                'departures'   => 'departures',
                'total_pax'    => 'total_pax',
                'next_date'    => 'next_date',
                'days_to_next' => 'days_to_next',
                'pax_on_next'  => 'pax_on_next',
            ];

            $orderby_key = strtolower($orderby_input);
            if (!isset($orderby_map[$orderby_key])) {
                $orderby_key = 'title';
            }
            $orderby = $orderby_map[$orderby_key];

            $order = ('desc' === $order_input) ? 'desc' : 'asc';

            $trip_ids = $this->query_trip_ids($search);

            $rows = [];

            $date_format = get_option('date_format');
            if (empty($date_format)) {
                $date_format = 'F j, Y';
            }

            $timezone = wp_timezone();
            $today    = current_time('Y-m-d');
            $today_midnight = $this->get_local_timestamp($today, $timezone);
            if (null === $today_midnight) {
                $today_midnight = (int) current_time('timestamp');
            }

            foreach ($trip_ids as $trip_id) {
                $rows[] = $this->build_row($trip_id, $today, $today_midnight, $date_format, $timezone);
            }

            $rows = $this->sort_rows($rows, $orderby, $order);

            $total = count($rows);
            $offset = ($page - 1) * $per_page;
            if ($offset >= $total) {
                $page   = max(1, (int) ceil($total / $per_page));
                $offset = ($page - 1) * $per_page;
            }

            $paged_rows = array_slice($rows, $offset, $per_page);

            $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;
            if ($total_pages < 1) {
                $total_pages = 1;
            }

            $range_start = ($total > 0 && !empty($paged_rows)) ? $offset + 1 : 0;
            $range_end   = ($total > 0 && !empty($paged_rows)) ? $range_start + count($paged_rows) - 1 : 0;

            wp_send_json_success(
                [
                    'rows'       => $paged_rows,
                    'pagination' => [
                        'total'       => $total,
                        'per_page'    => $per_page,
                        'page'        => $page,
                        'total_pages' => $total_pages,
                        'range'       => [
                            'start' => $range_start,
                            'end'   => $range_end,
                        ],
                    ],
                    'sort'       => [
                        'orderby' => $orderby,
                        'order'   => $order,
                    ],
                    'search'     => $search,
                ]
            );
        }

        /**
         * Retrieve the per-page preference for the current user.
         *
         * @param int $user_id Optional user ID.
         *
         * @return int
         */
        public static function get_user_per_page($user_id = 0) {
            if (!$user_id) {
                $user_id = get_current_user_id();
            }

            $value = (int) get_user_meta($user_id, self::PER_PAGE_OPTION, true);
            if ($value < 1) {
                $value = self::DEFAULT_PER_PAGE;
            }

            return $value;
        }

        /**
         * Determine whether debug JSON links are enabled for the current user.
         *
         * @param int $user_id Optional user ID.
         *
         * @return bool
         */
        public static function is_debug_enabled($user_id = 0) {
            if (!$user_id) {
                $user_id = get_current_user_id();
            }

            $value = get_user_meta($user_id, self::DEBUG_OPTION, true);

            return !empty($value) && 'no' !== $value && '0' !== $value;
        }

        /**
         * Persist the debug preference for the given user.
         *
         * @param bool $enabled Whether the debug view is enabled.
         * @param int  $user_id Optional user ID.
         */
        public static function set_debug_enabled($enabled, $user_id = 0) {
            if (!$user_id) {
                $user_id = get_current_user_id();
            }

            if ($enabled) {
                update_user_meta($user_id, self::DEBUG_OPTION, '1');
            } else {
                update_user_meta($user_id, self::DEBUG_OPTION, '');
            }
        }

        /**
         * Query trip IDs that match the provided search criteria.
         *
         * @param string $search Search term.
         *
         * @return int[]
         */
        private function query_trip_ids($search) {
            $args = [
                'post_type'      => 'trip',
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'posts_per_page' => -1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ];

            if ('' !== $search) {
                $args['s'] = $search;
            }

            $trip_ids = get_posts($args);
            if (!is_array($trip_ids)) {
                $trip_ids = [];
            }

            if ('' !== $search) {
                $search_id = (int) $search;
                if ($search_id > 0) {
                    $trip = get_post($search_id);
                    if ($trip && 'trip' === $trip->post_type && 'publish' === $trip->post_status) {
                        $trip_ids[] = $trip->ID;
                    }
                }
            }

            $trip_ids = array_map('intval', $trip_ids);
            $trip_ids = array_unique($trip_ids);
            sort($trip_ids, SORT_NUMERIC);

            return $trip_ids;
        }

        /**
         * Build a single row of trip data.
         *
         * @param int           $trip_id        Trip ID.
         * @param string        $today          Current date (Y-m-d).
         * @param int           $today_midnight Timestamp for today's midnight in site TZ.
         * @param string        $date_format    Date display format.
         * @param DateTimeZone  $timezone       Site timezone.
         *
         * @return array
         */
        private function build_row($trip_id, $today, $today_midnight, $date_format, $timezone) {
            $trip_id    = (int) $trip_id;
            $title      = get_the_title($trip_id);
            $title      = is_string($title) ? wp_strip_all_tags($title) : '';
            $countries  = get_the_terms($trip_id, 'country');
            $country_list = [];
            if (!is_wp_error($countries) && is_array($countries)) {
                foreach ($countries as $country) {
                    if (!empty($country->name)) {
                        $country_list[] = wp_strip_all_tags($country->name);
                    }
                }
            }

            $dates_data = $this->get_trip_dates($trip_id);
            $dates      = isset($dates_data['dates']) && is_array($dates_data['dates']) ? $dates_data['dates'] : [];
            $next_info  = $this->find_next_departure($dates, $timezone, $today_midnight);
            $next_departure = $next_info['date'];
            $next_timestamp = $next_info['timestamp'];

            $booking_stats = $this->get_booking_stats($trip_id);
            $total_pax     = isset($booking_stats['total_pax']) ? (int) $booking_stats['total_pax'] : 0;
            $pax_by_date   = isset($booking_stats['pax_by_date']) && is_array($booking_stats['pax_by_date']) ? $booking_stats['pax_by_date'] : [];
            $pax_on_next   = 0;
            if ($next_departure && isset($pax_by_date[$next_departure])) {
                $pax_on_next = (int) $pax_by_date[$next_departure];
            }

            $days_to_next = $this->calculate_days_until($next_timestamp, $today_midnight);

            $row = [
                'trip_id'                 => $trip_id,
                'trip_code'               => (string) $trip_id,
                'trip_title'              => $title,
                'countries'               => $country_list,
                'countries_label'         => implode(', ', $country_list),
                'number_of_departures'    => count($dates),
                'next_departure'          => $next_departure ? $next_departure : null,
                'next_departure_display'  => $next_timestamp ? wp_date($date_format, $next_timestamp, $timezone) : '',
                'total_pax'               => $total_pax,
                'pax_on_next_departure'   => $pax_on_next,
                'days_to_next'            => $days_to_next,
                'debug'                   => [
                    'dates_source' => isset($dates_data['source']) ? $dates_data['source'] : '',
                    'dates_raw'    => isset($dates_data['raw_dates']) ? $dates_data['raw_dates'] : [],
                    'booking_ids'  => isset($booking_stats['booking_ids']) ? $booking_stats['booking_ids'] : [],
                ],
            ];

            return $row;
        }

        /**
         * Sort rows according to the requested column and direction.
         *
         * @param array  $rows    Rows to sort.
         * @param string $orderby Column key.
         * @param string $order   asc|desc
         *
         * @return array
         */
        private function sort_rows(array $rows, $orderby, $order) {
            $order = ('desc' === $order) ? 'desc' : 'asc';

            $compare = function ($a, $b) use ($orderby, $order) {
                $direction = ('desc' === $order) ? -1 : 1;

                if ('next_date' === $orderby) {
                    $a_val = isset($a['next_departure']) ? $a['next_departure'] : null;
                    $b_val = isset($b['next_departure']) ? $b['next_departure'] : null;

                    if ($a_val === $b_val) {
                        return $a['trip_id'] <=> $b['trip_id'];
                    }
                    if (null === $a_val) {
                        return 1;
                    }
                    if (null === $b_val) {
                        return -1;
                    }

                    $result = strcmp($a_val, $b_val);
                    if ('desc' === $order) {
                        $result *= -1;
                    }

                    return (0 === $result) ? ($a['trip_id'] <=> $b['trip_id']) : $result;
                }

                if ('days_to_next' === $orderby) {
                    $a_val = isset($a['days_to_next']) ? $a['days_to_next'] : null;
                    $b_val = isset($b['days_to_next']) ? $b['days_to_next'] : null;

                    if ($a_val === $b_val) {
                        return $a['trip_id'] <=> $b['trip_id'];
                    }
                    if (null === $a_val) {
                        return 1;
                    }
                    if (null === $b_val) {
                        return -1;
                    }

                    $result = (int) $a_val <=> (int) $b_val;
                    if ('desc' === $order) {
                        $result *= -1;
                    }

                    return (0 === $result) ? ($a['trip_id'] <=> $b['trip_id']) : $result;
                }

                switch ($orderby) {
                    case 'ID':
                        $result = $a['trip_id'] <=> $b['trip_id'];
                        break;
                    case 'country':
                        $a_val = isset($a['countries_label']) ? strtolower($a['countries_label']) : '';
                        $b_val = isset($b['countries_label']) ? strtolower($b['countries_label']) : '';
                        $result = strcmp($a_val, $b_val);
                        break;
                    case 'departures':
                        $result = $a['number_of_departures'] <=> $b['number_of_departures'];
                        break;
                    case 'total_pax':
                        $result = $a['total_pax'] <=> $b['total_pax'];
                        break;
                    case 'pax_on_next':
                        $result = $a['pax_on_next_departure'] <=> $b['pax_on_next_departure'];
                        break;
                    case 'title':
                    default:
                        $a_val = isset($a['trip_title']) ? strtolower($a['trip_title']) : '';
                        $b_val = isset($b['trip_title']) ? strtolower($b['trip_title']) : '';
                        $result = strcmp($a_val, $b_val);
                        break;
                }

                if (0 === $result) {
                    $result = $a['trip_id'] <=> $b['trip_id'];
                }

                return $result * $direction;
            };

            usort($rows, $compare);

            return $rows;
        }

        /**
         * Determine the next departure date that is on or after today.
         *
         * @param array        $dates          Date list.
         * @param DateTimeZone $timezone       Site timezone.
         * @param int|null     $today_midnight Today's midnight timestamp in site TZ.
         *
         * @return array
         */
        private function find_next_departure(array $dates, $timezone, $today_midnight) {
            $next_date      = null;
            $next_timestamp = null;

            foreach ($dates as $date) {
                $date = (string) $date;
                if ('' === $date) {
                    continue;
                }

                $timestamp = $this->get_local_timestamp($date, $timezone);
                if (null === $timestamp) {
                    continue;
                }

                if (null !== $today_midnight && $timestamp < $today_midnight) {
                    continue;
                }

                if (null === $next_timestamp || $timestamp < $next_timestamp) {
                    $next_timestamp = $timestamp;
                    $next_date      = $date;
                }
            }

            return [
                'date'      => $next_date,
                'timestamp' => $next_timestamp,
            ];
        }

        /**
         * Calculate the number of days until a timestamp.
         *
         * @param int|null $target_timestamp  Target timestamp.
         * @param int|null $reference_timestamp Reference timestamp representing today.
         *
         * @return int|null
         */
        private function calculate_days_until($target_timestamp, $reference_timestamp) {
            if (!$target_timestamp) {
                return null;
            }

            if (null === $reference_timestamp) {
                return null;
            }

            $diff = (int) $target_timestamp - (int) $reference_timestamp;
            if ($diff < 0) {
                return null;
            }

            return (int) floor($diff / DAY_IN_SECONDS);
        }

        /**
         * Retrieve departure data for a trip, using a transient cache.
         *
         * @param int $trip_id Trip ID.
         *
         * @return array
         */
        private function get_trip_dates($trip_id) {
            $cache_key = self::DATES_TRANSIENT_PREFIX . $trip_id;
            $cached    = get_transient($cache_key);

            if (false !== $cached && is_array($cached)) {
                return $cached;
            }

            $data = $this->fetch_trip_dates($trip_id);

            set_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);

            return $data;
        }

        /**
         * Perform REST requests to retrieve trip departure dates.
         *
         * @param int $trip_id Trip ID.
         *
         * @return array
         */
        private function fetch_trip_dates($trip_id) {
            $versions = [
                'v3' => '/wptravelengine/v3/trips/' . $trip_id . '/dates',
                'v2' => '/wptravelengine/v2/trips/' . $trip_id . '/dates',
            ];

            $errors = [];

            foreach ($versions as $version => $route) {
                $request  = new \WP_REST_Request('GET', $route);
                $response = rest_do_request($request);

                if (is_wp_error($response)) {
                    $errors[$version] = $response->get_error_message();
                    continue;
                }

                $status = $response->get_status();
                if ($status < 200 || $status >= 300) {
                    $errors[$version] = 'HTTP ' . $status;
                    continue;
                }

                $data = $response->get_data();
                $normalized = $this->normalize_dates_from_response($data);

                if (!empty($normalized['dates'])) {
                    $normalized['source'] = $version;
                    return $normalized;
                }

                $errors[$version] = __('Empty dates payload', 'hr-customer-manager');
            }

            $this->log_dates_failure($trip_id, $errors);

            return [
                'dates'      => [],
                'raw_dates'  => [],
                'source'     => '',
            ];
        }

        /**
         * Normalize dates from REST API responses.
         *
         * @param mixed $data Response payload.
         *
         * @return array
         */
        private function normalize_dates_from_response($data) {
            $raw = $data;

            if (is_array($raw) && isset($raw['data']) && is_array($raw['data'])) {
                $raw = $raw['data'];
            }

            $raw_dates = [];

            if (is_array($raw)) {
                if (!empty($raw) && isset($raw[0]) && is_array($raw[0]) && isset($raw[0]['dates']) && is_array($raw[0]['dates'])) {
                    $raw_dates = $raw[0]['dates'];
                } elseif (isset($raw['dates']) && is_array($raw['dates'])) {
                    $raw_dates = $raw['dates'];
                } else {
                    $raw_dates = $raw;
                }
            }

            $dates = [];

            if (is_array($raw_dates)) {
                foreach ($raw_dates as $key => $value) {
                    if (is_string($key)) {
                        $normalized = $this->normalize_date_key($key);
                        if ('' !== $normalized) {
                            $dates[$normalized] = true;
                            continue;
                        }
                    }

                    if (is_array($value)) {
                        foreach (['date', 'datetime', 'start_date'] as $date_key) {
                            if (!empty($value[$date_key]) && is_string($value[$date_key])) {
                                $normalized = $this->normalize_date_key($value[$date_key]);
                                if ('' !== $normalized) {
                                    $dates[$normalized] = true;
                                }
                                break;
                            }
                        }
                    } elseif (is_string($value)) {
                        $normalized = $this->normalize_date_key($value);
                        if ('' !== $normalized) {
                            $dates[$normalized] = true;
                        }
                    }
                }
            }

            $date_keys = array_keys($dates);
            sort($date_keys, SORT_STRING);

            return [
                'dates'     => $date_keys,
                'raw_dates' => $raw_dates,
            ];
        }

        /**
         * Normalize an arbitrary value to a Y-m-d date string.
         *
         * @param string $value Raw date value.
         *
         * @return string
         */
        private function normalize_date_key($value) {
            if (!is_string($value)) {
                return '';
            }

            $value = trim($value);
            if ('' === $value) {
                return '';
            }

            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
                return $matches[1];
            }

            return '';
        }

        /**
         * Retrieve booking aggregation data for a trip.
         *
         * @param int $trip_id Trip ID.
         *
         * @return array
         */
        private function get_booking_stats($trip_id) {
            $cache_key = self::BOOKING_TRANSIENT_PREFIX . $trip_id;
            $cached    = get_transient($cache_key);

            if (false !== $cached && is_array($cached)) {
                return $cached;
            }

            $args = [
                'post_type'      => 'booking',
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'meta_query'     => [
                    'relation' => 'AND',
                    [
                        'key'     => 'order_trips',
                        'compare' => 'EXISTS',
                    ],
                    [
                        'key'     => 'order_trips',
                        'value'   => '"' . $trip_id . '"',
                        'compare' => 'LIKE',
                    ],
                    [
                        'key'   => 'wp_travel_engine_booking_status',
                        'value' => 'booked',
                    ],
                ],
            ];

            $booking_ids = get_posts($args);
            if (!is_array($booking_ids)) {
                $booking_ids = [];
            }

            $total_pax   = 0;
            $pax_by_date = [];
            $matched_bookings = [];

            foreach ($booking_ids as $booking_id) {
                $booking_id = (int) $booking_id;
                $cart_items = $this->parse_order_trips_meta($booking_id);
                if (empty($cart_items)) {
                    continue;
                }

                $matched = false;
                $travelers_cache = null;

                foreach ($cart_items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    if (!$this->item_matches_trip($item, $trip_id)) {
                        continue;
                    }

                    $matched = true;

                    $item_pax = $this->calculate_item_pax($item, $booking_id, $travelers_cache);
                    if ($item_pax < 0) {
                        $item_pax = 0;
                    }

                    $total_pax += $item_pax;

                    $item_date = $this->extract_item_date($item);
                    if ($item_date) {
                        if (!isset($pax_by_date[$item_date])) {
                            $pax_by_date[$item_date] = 0;
                        }
                        $pax_by_date[$item_date] += $item_pax;
                    }
                }

                if ($matched) {
                    $matched_bookings[] = $booking_id;
                }
            }

            ksort($pax_by_date, SORT_STRING);

            $stats = [
                'total_pax'   => $total_pax,
                'pax_by_date' => $pax_by_date,
                'booking_ids' => $matched_bookings,
            ];

            set_transient($cache_key, $stats, 5 * MINUTE_IN_SECONDS);

            return $stats;
        }

        /**
         * Parse the order_trips meta value into an array.
         *
         * @param int $booking_id Booking ID.
         *
         * @return array
         */
        private function parse_order_trips_meta($booking_id) {
            $raw = get_post_meta($booking_id, 'order_trips', true);
            if (empty($raw)) {
                return [];
            }

            if (is_string($raw)) {
                $maybe_json = json_decode($raw, true);
                if (JSON_ERROR_NONE === json_last_error()) {
                    $raw = $maybe_json;
                }
            }

            $value = maybe_unserialize($raw);

            if ($value instanceof \stdClass) {
                $value = (array) $value;
            }

            if (!is_array($value)) {
                return [];
            }

            return $value;
        }

        /**
         * Determine whether a cart item matches the target trip.
         *
         * @param array $item    Cart item data.
         * @param int   $trip_id Trip ID.
         *
         * @return bool
         */
        private function item_matches_trip(array $item, $trip_id) {
            $keys = ['id', 'trip_id', 'tid', 'ID'];
            foreach ($keys as $key) {
                if (isset($item[$key]) && (int) $item[$key] === (int) $trip_id) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Calculate passenger count for a cart item.
         *
         * @param array $item       Cart item data.
         * @param int   $booking_id Booking ID.
         *
         * @return int
         */
        private function calculate_item_pax(array $item, $booking_id, &$travelers_cache = null) {
            $pax = 0;

            if (isset($item['pax'])) {
                if (is_array($item['pax'])) {
                    foreach ($item['pax'] as $value) {
                        $pax += (int) $value;
                    }
                } elseif (is_numeric($item['pax'])) {
                    $pax += (int) $item['pax'];
                }
            }

            if ($pax <= 0) {
                if (isset($item['travellers_details']) && is_array($item['travellers_details'])) {
                    $pax = count($item['travellers_details']);
                } else {
                    if (null === $travelers_cache) {
                        $travelers_meta  = get_post_meta($booking_id, 'wptravelengine_travelers_details', true);
                        $travelers_cache = $this->parse_meta_value($travelers_meta);
                    }
                    if (is_array($travelers_cache)) {
                        $pax = count($travelers_cache);
                    }
                }
            }

            return (int) max(0, $pax);
        }

        /**
         * Parse arbitrary meta values.
         *
         * @param mixed $value Raw meta value.
         *
         * @return mixed
         */
        private function parse_meta_value($value) {
            if (empty($value)) {
                return [];
            }

            if (is_string($value)) {
                $maybe_json = json_decode($value, true);
                if (JSON_ERROR_NONE === json_last_error()) {
                    return $maybe_json;
                }
            }

            $unserialized = maybe_unserialize($value);

            if ($unserialized instanceof \stdClass) {
                $unserialized = (array) $unserialized;
            }

            return $unserialized;
        }

        /**
         * Extract a normalized departure date from a cart item.
         *
         * @param array $item Cart item data.
         *
         * @return string
         */
        private function extract_item_date(array $item) {
            foreach (['datetime', 'date', 'start_date'] as $key) {
                if (!empty($item[$key]) && is_string($item[$key])) {
                    $normalized = $this->normalize_date_key($item[$key]);
                    if ('' !== $normalized) {
                        return $normalized;
                    }
                }
            }

            return '';
        }

        /**
         * Convert a date string to a local timestamp.
         *
         * @param string|null  $date     Date in Y-m-d format.
         * @param DateTimeZone $timezone Site timezone.
         *
         * @return int|null
         */
        private function get_local_timestamp($date, $timezone) {
            if (empty($date)) {
                return null;
            }

            try {
                $datetime = \DateTimeImmutable::createFromFormat('Y-m-d', $date, $timezone);
                if (!$datetime) {
                    return null;
                }

                return $datetime->getTimestamp();
            } catch (\Exception $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                return null;
            }
        }

        /**
         * Invalidate cached booking statistics when a booking is saved.
         *
         * @param int     $post_id Post ID.
         * @param WP_Post $post    Post object.
         * @param bool    $update  Whether this is an update.
         */
        public function invalidate_booking_cache($post_id, $post, $update) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
            if ('booking' !== get_post_type($post_id)) {
                return;
            }

            $cart_items = $this->parse_order_trips_meta($post_id);
            if (empty($cart_items)) {
                return;
            }

            $trip_ids = [];
            foreach ($cart_items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach (['id', 'trip_id', 'tid', 'ID'] as $key) {
                    if (isset($item[$key])) {
                        $trip_ids[] = (int) $item[$key];
                    }
                }
            }

            $trip_ids = array_filter(array_map('intval', $trip_ids));
            $trip_ids = array_unique($trip_ids);

            foreach ($trip_ids as $trip_id) {
                delete_transient(self::BOOKING_TRANSIENT_PREFIX . $trip_id);
            }
        }

        /**
         * Log a failure to retrieve trip dates, throttled to once per hour per trip.
         *
         * @param int   $trip_id Trip ID.
         * @param array $errors  Error messages keyed by version.
         */
        private function log_dates_failure($trip_id, array $errors) {
            $transient = self::ERROR_TRANSIENT_PREFIX . $trip_id;
            if (false !== get_transient($transient)) {
                return;
            }

            $messages = [];
            foreach ($errors as $version => $message) {
                $messages[] = sprintf('%s: %s', strtoupper($version), $message);
            }

            if (empty($messages)) {
                $messages[] = __('Unknown error', 'hr-customer-manager');
            }

            error_log(sprintf('[HR_CM] Failed to load trip dates for trip %d (%s)', $trip_id, implode('; ', $messages))); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

            set_transient($transient, 1, HOUR_IN_SECONDS);
        }
    }
}
