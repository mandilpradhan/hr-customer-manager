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
        const PER_PAGE_OPTION = 'hrcm_customers_per_page';
        const DEFAULT_PER_PAGE = 25;

        /**
         * Cached map of normalized trip names to published trip IDs.
         *
         * @var array|null
         */
        private static $trip_id_cache = null;

        /**
         * Current row index for stable sorting.
         *
         * @var int
         */
        private $row_index = 0;

        /**
         * Prepare data for the customer overview screen.
         *
         * @return array
         */
        public function prepare_data() {
            $request = $this->get_request_context();

            $rows         = $this->get_all_rows();
            $trip_options = $this->build_trip_options($rows);

            $filtered_rows = $this->apply_filters($rows, $request['filters']);
            $sorted_rows   = $this->sort_rows($filtered_rows, $request['sort'], $request['dir']);
            $pagination    = $this->paginate_rows($sorted_rows, $request['per_page'], $request['paged']);

            $selected_trip      = isset($request['filters']['trip']) ? $request['filters']['trip'] : '';
            $initial_departures = [];

            if ('' !== $selected_trip) {
                $initial_departures[$selected_trip] = $this->get_departures_for_trip($selected_trip, $rows);
            }

            return [
                'rows'             => $pagination['rows'],
                'filters'          => $request['filters'],
                'filter_options'   => [
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
                'nonce_action'     => 'hr_cm_filters',
                'sort'             => $request['sort'],
                'dir'              => $request['dir'],
                'pagination'       => $pagination,
                'trip_departures'  => $initial_departures,
                'query_args'       => $request['query_args'],
                'columns'          => self::get_column_config(),
            ];
        }

        /**
         * Build the request context for filters, sorting, and pagination.
         *
         * @return array
         */
        private function get_request_context() {
            $filters_data = $this->get_filters_from_request();

            $sort = isset($_GET['sort']) ? sanitize_key(wp_unslash($_GET['sort'])) : '';
            $sort = $this->sanitize_sort($sort);

            $dir = isset($_GET['dir']) ? strtolower(sanitize_text_field(wp_unslash($_GET['dir']))) : 'asc';
            if (!in_array($dir, ['asc', 'desc'], true)) {
                $dir = 'asc';
            }

            $per_page = $this->get_items_per_page();

            $paged = isset($_GET['paged']) ? (int) wp_unslash($_GET['paged']) : 1;
            if ($paged < 1) {
                $paged = 1;
            }

            return [
                'filters'   => $filters_data['values'],
                'sort'      => $sort,
                'dir'       => $dir,
                'per_page'  => $per_page,
                'paged'     => $paged,
                'query_args'=> $this->build_query_args($filters_data['values'], $sort, $dir),
            ];
        }

        /**
         * Determine the per-page value from screen options.
         *
         * @return int
         */
        private function get_items_per_page() {
            $default = self::DEFAULT_PER_PAGE;
            $option  = self::PER_PAGE_OPTION;

            $screen_option = '';
            if (function_exists('get_current_screen')) {
                $screen = get_current_screen();
                if ($screen && class_exists('WP_Screen') && $screen instanceof WP_Screen) {
                    $screen_option = $screen->get_option('per_page', 'option');
                }
            }

            if (!is_string($screen_option) || '' === $screen_option) {
                $screen_option = $option;
            }

            $value = get_user_option($screen_option);
            if (false === $value) {
                $value = $default;
            }

            $value = (int) $value;
            if ($value < 1) {
                $value = $default;
            }

            return $value;
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
                'posts_per_page' => -1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            ]);

            if (!$query->have_posts()) {
                return [];
            }

            $timezone    = wp_timezone();
            $rows        = [];
            $this->row_index = 0;

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

            $lead          = HR_CM_Data::get_lead($booking_id);
            $trip          = HR_CM_Data::get_trip($booking_id);
            $payment       = HR_CM_Data::get_payment($booking_id);
            $days          = null;
            $departure_ts  = PHP_INT_MAX;

            if ('' !== $trip['date']) {
                $days = HR_CM_Data::days_to_trip($trip['date'], $timezone);

                $departure = date_create_immutable($trip['date'], $timezone);
                if ($departure instanceof DateTimeImmutable) {
                    $departure_ts = (int) $departure->setTime(0, 0, 0)->format('U');
                }
            }

            $is_fully_paid = $this->is_fully_paid($payment);
            $is_cancelled  = $this->is_cancelled($payment);
            $phase_label   = HR_CM_Phase_Calculator::get_phase_label($days, $is_fully_paid);
            $phase_rank    = $this->get_phase_rank($days);

            return [
                'booking_id'     => (int) $booking_id,
                'travelers'      => $travelers,
                'lead'           => $lead,
                'trip'           => $trip,
                'payment'        => $payment,
                'days_to_trip'   => $days,
                'is_fully_paid'  => $is_fully_paid,
                'is_cancelled'   => $is_cancelled,
                'phase_label'    => $phase_label,
                'phase_rank'     => $phase_rank,
                'departure_ts'   => $departure_ts,
                'last_email_ts'  => $this->get_last_email_timestamp($booking_id),
            ];
        }

        /**
         * Attempt to retrieve the last email timestamp for a booking.
         *
         * @param int $booking_id Booking post ID.
         *
         * @return int
         */
        private function get_last_email_timestamp($booking_id) {
            $meta_keys = ['hr_cm_last_email_sent', '_hr_cm_last_email_sent', 'last_email_sent'];

            foreach ($meta_keys as $key) {
                $value = get_post_meta($booking_id, $key, true);
                if ('' === $value || false === $value) {
                    continue;
                }

                if (is_numeric($value)) {
                    $timestamp = (int) $value;
                    if ($timestamp > 0) {
                        return $timestamp;
                    }
                }
            }

            return 0;
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
            $lead          = isset($context['lead']) ? $context['lead'] : ['name' => '', 'email' => ''];
            $lead_name     = isset($lead['name']) ? trim((string) $lead['name']) : '';
            $lead_email    = isset($lead['email']) ? trim((string) $lead['email']) : '';
            $lead_exists   = ('' !== $lead_name) || ('' !== $lead_email);
            $lead_differs  = false;

            if ($lead_exists) {
                $lead_differs = !$this->strings_equal($traveler['name'], $lead_name)
                    || !$this->strings_equal($traveler['email'], $lead_email);
            }

            $payment_display = $this->format_payment_status(
                $context['payment'],
                $context['days_to_trip'],
                $context['is_fully_paid'],
                $context['is_cancelled']
            );

            $info_display = $this->format_info_received($context['days_to_trip']);
            $days_sort    = $context['days_to_trip'];
            $days_sort    = ($days_sort === null) ? PHP_INT_MAX : (int) $days_sort;

            $search_parts = [
                $traveler['name'],
                $traveler['email'],
                $lead_exists ? $lead_name : '',
                $lead_exists ? $lead_email : '',
                $context['trip']['name'],
            ];

            return [
                'index'               => $this->row_index++,
                'booking_id'          => $context['booking_id'],
                'traveler_name'       => $traveler['name'],
                'traveler_email'      => $traveler['email'],
                'lead_name'           => $lead_exists ? $lead_name : '',
                'lead_email'          => $lead_exists ? $lead_email : '',
                'show_lead'           => $lead_exists && $lead_differs,
                'trip_id'             => isset($context['trip']['id']) ? (int) $context['trip']['id'] : 0,
                'trip_name'           => $context['trip']['name'],
                'departure_date'      => $context['trip']['date'],
                'departure_ts'        => $context['departure_ts'],
                'days_to_trip'        => $context['days_to_trip'],
                'days_to_trip_sort'   => $days_sort,
                'payment'             => $payment_display,
                'info'                => $info_display,
                'phase_label'         => $context['phase_label'],
                'phase_rank'          => $context['phase_rank'],
                'resend_disabled'     => $context['is_cancelled'],
                'payment_filter'      => $payment_display['filter'],
                'payment_status_rank' => $payment_display['rank'],
                'payment_status_days' => $payment_display['days'],
                'info_rank'           => $info_display['rank'],
                'last_email_sent_ts'  => $context['last_email_ts'],
                'last_email_display'  => $this->format_last_email($context['last_email_ts']),
                'search_haystack'     => strtolower(trim(implode(' ', array_filter($search_parts, 'strlen')))),
                'resend_rank'         => $context['is_cancelled'] ? 1 : 0,
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
            $due    = isset($payment['due']) ? (float) $payment['due'] : 0.0;
            $status = isset($payment['p_status']) ? strtolower((string) $payment['p_status']) : '';

            if ($due <= 0.0) {
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
                    'rank'   => 0,
                    'days'   => PHP_INT_MAX,
                    'is_badge' => false,
                    'badge_class' => '',
                ];
            }

            $days_sort = ($days_to_trip === null) ? PHP_INT_MAX : (int) $days_to_trip;

            if ($is_paid) {
                return [
                    'text'   => __('Fully Paid', 'hr-customer-manager'),
                    'class'  => 'text-regular',
                    'filter' => 'paid',
                    'rank'   => 1,
                    'days'   => $days_sort,
                    'is_badge' => false,
                    'badge_class' => '',
                ];
            }

            $class       = 'text-regular';
            $text        = __('Balance Due', 'hr-customer-manager');
            $is_badge    = false;
            $badge_class = '';

            if ($days_to_trip !== null && $days_to_trip < 60) {
                $text        = __('Overdue', 'hr-customer-manager');
                $class       = '';
                $is_badge    = true;
                $badge_class = 'hrcm-badge hrcm-badge--danger';
            }

            return [
                'text'   => $text,
                'class'  => $class,
                'filter' => 'due',
                'rank'   => 2,
                'days'   => $days_sort,
                'is_badge' => $is_badge,
                'badge_class' => $badge_class,
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
            if ($days_to_trip === null) {
                return [
                    'text'  => __('Not Received', 'hr-customer-manager'),
                    'class' => 'text-muted',
                    'rank'  => 3,
                    'is_badge' => false,
                    'badge_class' => '',
                ];
            }

            if ($days_to_trip > 60) {
                return [
                    'text'  => __('Not Received', 'hr-customer-manager'),
                    'class' => 'text-regular',
                    'rank'  => 0,
                    'is_badge' => false,
                    'badge_class' => '',
                ];
            }

            return [
                'text'       => ($days_to_trip < 50)
                    ? __('Overdue', 'hr-customer-manager')
                    : __('Not Received', 'hr-customer-manager'),
                'class'      => ($days_to_trip < 50) ? '' : 'text-warn',
                'rank'       => ($days_to_trip < 50) ? 2 : 1,
                'is_badge'   => ($days_to_trip < 50),
                'badge_class'=> ($days_to_trip < 50) ? 'hrcm-badge hrcm-badge--warn' : '',
            ];
        }

        /**
         * Determine a sortable phase rank based on days to trip.
         *
         * @param int|null $days_to_trip Days remaining to trip.
         *
         * @return int
         */
        private function get_phase_rank($days_to_trip) {
            if ($days_to_trip === null) {
                return 100;
            }

            if ($days_to_trip < 0) {
                return 0;
            }

            if ($days_to_trip === 0) {
                return 1;
            }

            if ($days_to_trip <= 3) {
                return 2;
            }

            if ($days_to_trip <= 7) {
                return 3;
            }

            if ($days_to_trip <= 14) {
                return 4;
            }

            if ($days_to_trip <= 30) {
                return 5;
            }

            if ($days_to_trip <= 60) {
                return 6;
            }

            if ($days_to_trip <= 120) {
                return 7;
            }

            return 8;
        }

        /**
         * Format a timestamp for display.
         *
         * @param int $timestamp Unix timestamp.
         *
         * @return string
         */
        private function format_last_email($timestamp) {
            if ($timestamp <= 0) {
                return '—';
            }

            $format = trim(get_option('date_format') . ' ' . get_option('time_format'));
            if ('' === $format) {
                $format = 'Y-m-d H:i';
            }

            return date_i18n($format, $timestamp);
        }

        /**
         * Build trip options map for filters.
         *
         * @param array $rows Traveler rows.
         *
         * @return array
         */
        private function build_trip_options($rows) {
            $timezone = wp_timezone();
            $options  = [];

            $published = $this->get_trip_id_map();
            foreach ($published as $key => $data) {
                $options[$key] = [
                    'name'   => $data['name'],
                    'source' => 'cpt',
                    'dates'  => [],
                    'ids'    => isset($data['ids']) ? array_values(array_map('intval', (array) $data['ids'])) : [],
                ];
            }

            $booking_names = HR_CM_Data::get_all_booking_trip_names();
            foreach ($booking_names as $name) {
                $normalized = $this->normalize_trip_key($name);
                if ('' === $normalized) {
                    continue;
                }

                if (!isset($options[$normalized])) {
                    $options[$normalized] = [
                        'name'   => $name,
                        'source' => 'booking',
                        'dates'  => [],
                        'ids'    => [],
                    ];
                }
            }

            $booking_dates = $this->collect_booking_departure_dates($rows, $timezone);
            foreach ($booking_dates as $key => $data) {
                if (!isset($options[$key])) {
                    $options[$key] = [
                        'name'   => $data['name'],
                        'source' => 'bookings',
                        'dates'  => [],
                        'ids'    => [],
                    ];
                } elseif ('cpt' !== $options[$key]['source'] && '' !== $data['name'] && '' === $options[$key]['name']) {
                    $options[$key]['name'] = $data['name'];
                }

                foreach ($data['dates'] as $date) {
                    $options[$key]['dates'][$date] = $date;
                }

                if (isset($data['ids'])) {
                    foreach ((array) $data['ids'] as $trip_id) {
                        $options[$key]['ids'][(int) $trip_id] = (int) $trip_id;
                    }
                }
            }

            foreach ($options as &$option) {
                ksort($option['dates'], SORT_STRING);
                $option['dates'] = array_values($option['dates']);

                if (isset($option['ids']) && is_array($option['ids'])) {
                    $option['ids'] = array_values(array_unique(array_map('intval', $option['ids'])));
                } else {
                    $option['ids'] = [];
                }
            }
            unset($option);

            usort(
                $options,
                static function ($a, $b) {
                    $name_a = isset($a['name']) ? $a['name'] : '';
                    $name_b = isset($b['name']) ? $b['name'] : '';

                    return strnatcasecmp($name_a, $name_b);
                }
            );

            $final = [];
            foreach ($options as $option) {
                $label = isset($option['name']) ? $option['name'] : '';
                if ('' === $label) {
                    continue;
                }

                $final[$label] = [
                    'name'  => $label,
                    'dates' => isset($option['dates']) ? $option['dates'] : [],
                    'ids'   => isset($option['ids']) ? $option['ids'] : [],
                ];
            }

            return $final;
        }

        /**
         * Collect future departure dates from booking rows.
         *
         * @param array         $rows     Traveler rows.
         * @param DateTimeZone  $timezone Site timezone.
         *
         * @return array
         */
        private function collect_booking_departure_dates($rows, DateTimeZone $timezone) {
            $map = [];

            foreach ($rows as $row) {
                $trip_name = isset($row['trip_name']) ? (string) $row['trip_name'] : '';
                $key       = $this->normalize_trip_key($trip_name);

                if ('' === $key) {
                    continue;
                }

                if (!isset($map[$key])) {
                    $map[$key] = [
                        'name'  => $trip_name,
                        'dates' => [],
                        'ids'   => [],
                    ];
                } elseif ('' === $map[$key]['name'] && '' !== $trip_name) {
                    $map[$key]['name'] = $trip_name;
                }

                if (!isset($map[$key]['ids'])) {
                    $map[$key]['ids'] = [];
                }

                $trip_id = isset($row['trip_id']) ? (int) $row['trip_id'] : 0;
                if ($trip_id > 0) {
                    $map[$key]['ids'][$trip_id] = $trip_id;
                }

                $date = isset($row['departure_date']) ? $row['departure_date'] : '';
                $normalized = $this->parse_departure_value($date, $timezone);

                if ('' === $normalized) {
                    continue;
                }

                if (!$this->is_future_date($normalized, $timezone)) {
                    continue;
                }

                $map[$key]['dates'][$normalized] = $normalized;
            }

            foreach ($map as &$data) {
                ksort($data['dates'], SORT_STRING);
                $data['dates'] = array_values($data['dates']);

                if (isset($data['ids']) && is_array($data['ids'])) {
                    $data['ids'] = array_values(array_unique(array_map('intval', $data['ids'])));
                } else {
                    $data['ids'] = [];
                }
            }
            unset($data);

            return $map;
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
                        if (false === strpos($row['search_haystack'], strtolower($filters['search']))) {
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
            $nonce_value = isset($_GET['hr_cm_nonce']) ? sanitize_text_field(wp_unslash($_GET['hr_cm_nonce'])) : '';
            $nonce_valid = ('' !== $nonce_value) ? wp_verify_nonce($nonce_value, 'hr_cm_filters') : false;

            $filters = [
                'trip'        => '',
                'departure'   => '',
                'status'      => '',
                'days_range'  => '',
                'search'      => '',
                'nonce_valid' => (bool) $nonce_valid,
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
         * Ensure a valid sort key is used.
         *
         * @param string $sort Sort key from request.
         *
         * @return string
         */
        private function sanitize_sort($sort) {
            $map = $this->get_sortable_map();

            if ('' === $sort || !isset($map[$sort])) {
                return 'departure';
            }

            return $sort;
        }

        /**
         * Mapping of exposed sort keys to row fields.
         *
         * @return array
         */
        private function get_sortable_map() {
            return [
                'traveler'        => 'traveler_name',
                'booking_id'      => 'booking_id',
                'trip'            => 'trip_name',
                'departure'       => 'departure_ts',
                'days_to_trip'    => 'days_to_trip_sort',
                'payment_status'  => 'payment_status_rank',
                'info_received'   => 'info_rank',
                'current_phase'   => 'phase_rank',
                'last_email_sent' => 'last_email_sent_ts',
                'resend_email'    => 'resend_rank',
            ];
        }

        /**
         * Sort rows according to the requested key and direction.
         *
         * @param array  $rows Rows to sort.
         * @param string $sort Sort key.
         * @param string $dir  Direction (asc|desc).
         *
         * @return array
         */
        private function sort_rows($rows, $sort, $dir) {
            $map  = $this->get_sortable_map();
            $key  = isset($map[$sort]) ? $map[$sort] : $map['departure'];
            $mult = ('desc' === strtolower($dir)) ? -1 : 1;

            usort($rows, function ($a, $b) use ($key, $sort, $mult) {
                $result = $this->compare_rows($a, $b, $key, $sort);

                if (0 === $result) {
                    $result = $a['index'] <=> $b['index'];
                }

                return $result * $mult;
            });

            return $rows;
        }

        /**
         * Compare two rows for sorting.
         *
         * @param array  $a       First row.
         * @param array  $b       Second row.
         * @param string $field   Field name to compare.
         * @param string $sortKey Original sort key.
         *
         * @return int
         */
        private function compare_rows($a, $b, $field, $sortKey) {
            $a_val = isset($a[$field]) ? $a[$field] : null;
            $b_val = isset($b[$field]) ? $b[$field] : null;

            if ('traveler_name' === $field || 'traveler_email' === $field || 'trip_name' === $field) {
                return strnatcasecmp((string) $a_val, (string) $b_val);
            }

            if ('booking_id' === $field || 'departure_ts' === $field || 'days_to_trip_sort' === $field || 'last_email_sent_ts' === $field || 'resend_rank' === $field) {
                return (int) $a_val <=> (int) $b_val;
            }

            if ('payment_status_rank' === $field) {
                $result = (int) $a_val <=> (int) $b_val;
                if (0 === $result) {
                    $result = (int) $a['payment_status_days'] <=> (int) $b['payment_status_days'];
                }

                if (0 === $result) {
                    $result = strnatcasecmp($a['payment']['text'], $b['payment']['text']);
                }

                return $result;
            }

            if ('info_rank' === $field) {
                $result = (int) $a_val <=> (int) $b_val;
                if (0 === $result) {
                    $result = (int) $a['days_to_trip_sort'] <=> (int) $b['days_to_trip_sort'];
                }

                return $result;
            }

            if ('phase_rank' === $field) {
                $result = (int) $a_val <=> (int) $b_val;
                if (0 === $result) {
                    $result = (int) $a['days_to_trip_sort'] <=> (int) $b['days_to_trip_sort'];
                }

                return $result;
            }

            return strnatcasecmp((string) $a_val, (string) $b_val);
        }

        /**
         * Paginate the sorted rows.
         *
         * @param array $rows     Sorted rows.
         * @param int   $per_page Items per page.
         * @param int   $paged    Current page.
         *
         * @return array
         */
        private function paginate_rows($rows, $per_page, $paged) {
            $total_items = count($rows);
            $per_page    = max(1, (int) $per_page);
            $total_pages = max(1, (int) ceil($total_items / $per_page));

            if ($paged > $total_pages) {
                $paged = $total_pages;
            }

            $offset    = ($paged - 1) * $per_page;
            $page_rows = array_slice($rows, $offset, $per_page);

            return [
                'rows'         => $page_rows,
                'total_items'  => $total_items,
                'total_pages'  => $total_pages,
                'current_page' => $paged,
                'per_page'     => $per_page,
            ];
        }

        /**
         * Build query arguments for pagination links.
         *
         * @param array  $filters Filter values.
         * @param string $sort    Sort key.
         * @param string $dir     Sort direction.
         *
         * @return array
         */
        private function build_query_args($filters, $sort, $dir) {
            $args = [
                'page'     => 'hr-customer-manager',
                'sort'     => $sort,
                'dir'      => $dir,
            ];

            if (isset($_GET['hr_cm_nonce'])) {
                $args['hr_cm_nonce'] = sanitize_text_field(wp_unslash($_GET['hr_cm_nonce']));
            }

            $mapping = [
                'hr_cm_trip'      => $filters['trip'],
                'hr_cm_departure' => $filters['departure'],
                'hr_cm_status'    => $filters['status'],
                'hr_cm_days'      => $filters['days_range'],
                'hr_cm_search'    => $filters['search'],
            ];

            foreach ($mapping as $key => $value) {
                if ('' !== $value) {
                    $args[$key] = $value;
                }
            }

            return $args;
        }

        /**
         * Retrieve the column configuration used by the overview table.
         *
         * @return array
         */
        public static function get_column_config() {
            return [
                'traveler'        => [
                    'label' => __('Traveler(s)', 'hr-customer-manager'),
                    'sort'  => 'traveler',
                ],
                'booking_id'      => [
                    'label' => __('Booking ID', 'hr-customer-manager'),
                    'sort'  => 'booking_id',
                ],
                'trip'            => [
                    'label' => __('Trip', 'hr-customer-manager'),
                    'sort'  => 'trip',
                ],
                'departure'       => [
                    'label' => __('Departure', 'hr-customer-manager'),
                    'sort'  => 'departure',
                ],
                'days_to_trip'    => [
                    'label' => __('Days to Trip', 'hr-customer-manager'),
                    'sort'  => 'days_to_trip',
                ],
                'payment_status'  => [
                    'label' => __('Payment Status', 'hr-customer-manager'),
                    'sort'  => 'payment_status',
                ],
                'info_received'   => [
                    'label' => __('Info received', 'hr-customer-manager'),
                    'sort'  => 'info_received',
                ],
                'current_phase'   => [
                    'label' => __('Current Phase', 'hr-customer-manager'),
                    'sort'  => 'current_phase',
                ],
                'last_email_sent' => [
                    'label' => __('Last Email Sent', 'hr-customer-manager'),
                    'sort'  => 'last_email_sent',
                ],
                'resend_email'    => [
                    'label' => __('Resend Email', 'hr-customer-manager'),
                    'sort'  => 'resend_email',
                ],
            ];
        }

        /**
         * Retrieve the available departures for a given trip.
         *
         * @param string     $trip_name Trip display name.
         * @param array|null $rows      Optional pre-fetched rows.
         *
         * @return array
         */
        public function get_departures_for_trip($trip_name, $rows = null) {
            $trip_name = trim((string) $trip_name);
            if ('' === $trip_name) {
                return [];
            }

            $timezone = wp_timezone();

            $dates = $this->fetch_departures_from_bookings($trip_name, $timezone, $rows);
            if (!empty($dates)) {
                return $dates;
            }

            $trip_ids = $this->collect_trip_ids_for_name($trip_name, $rows);
            if (empty($trip_ids)) {
                $trip_ids = $this->get_trip_ids_by_name($trip_name);
            }

            foreach ($trip_ids as $trip_id) {
                $dates = $this->fetch_departures_via_rest($trip_id, $timezone);
                if (!empty($dates)) {
                    return $dates;
                }
            }

            foreach ($trip_ids as $trip_id) {
                $dates = $this->fetch_departures_from_meta($trip_id, $timezone);
                if (!empty($dates)) {
                    return $dates;
                }
            }

            return [];
        }

        /**
         * Collect unique trip IDs for the provided trip name from existing rows.
         *
         * @param string     $trip_name Trip display name.
         * @param array|null $rows      Optional pre-fetched rows.
         *
         * @return array
         */
        private function collect_trip_ids_for_name($trip_name, $rows = null) {
            $trip_name = trim((string) $trip_name);
            if ('' === $trip_name) {
                return [];
            }

            if (null === $rows) {
                $rows = $this->get_all_rows();
            }

            if (!is_array($rows) || empty($rows)) {
                return [];
            }

            $key = $this->normalize_trip_key($trip_name);
            if ('' === $key) {
                return [];
            }

            $ids = [];

            foreach ($rows as $row) {
                $row_name = isset($row['trip_name']) ? (string) $row['trip_name'] : '';
                $row_key  = $this->normalize_trip_key($row_name);

                if ('' === $row_key || $row_key !== $key) {
                    continue;
                }

                if (isset($row['trip_id'])) {
                    $trip_id = (int) $row['trip_id'];
                    if ($trip_id > 0) {
                        $ids[$trip_id] = $trip_id;
                    }
                }
            }

            if (empty($ids)) {
                return [];
            }

            return array_values($ids);
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

        /**
         * Get or build a map of normalized trip names to published trip IDs.
         *
         * @return array
         */
        private function get_trip_id_map() {
            if (null !== self::$trip_id_cache) {
                return self::$trip_id_cache;
            }

            $map   = [];
            $posts = get_posts([
                'post_type'      => 'trip',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
            ]);

            if (is_array($posts)) {
                foreach ($posts as $trip_id) {
                    $name = get_the_title($trip_id);
                    $name = trim((string) $name);

                    if ('' === $name) {
                        continue;
                    }

                    $key = $this->normalize_trip_key($name);
                    if ('' === $key) {
                        continue;
                    }

                    if (!isset($map[$key])) {
                        $map[$key] = [
                            'name' => $name,
                            'ids'  => [],
                        ];
                    }

                    $map[$key]['ids'][] = (int) $trip_id;
                }
            }

            self::$trip_id_cache = $map;

            return $map;
        }

        /**
         * Get trip IDs for a provided trip name.
         *
         * @param string $trip_name Trip display name.
         *
         * @return array
         */
        private function get_trip_ids_by_name($trip_name) {
            $key = $this->normalize_trip_key($trip_name);

            if ('' === $key) {
                return [];
            }

            $map = $this->get_trip_id_map();

            if (!isset($map[$key]['ids']) || !is_array($map[$key]['ids'])) {
                return [];
            }

            return array_map('intval', $map[$key]['ids']);
        }

        /**
         * Normalize a trip name for consistent lookups.
         *
         * @param string $value Raw trip name.
         *
         * @return string
         */
        private function normalize_trip_key($value) {
            $value = wp_strip_all_tags((string) $value);
            $value = trim($value);

            if ('' === $value) {
                return '';
            }

            $value = preg_replace('/\s+/', ' ', $value);
            $value = is_string($value) ? $value : '';

            return strtolower($value);
        }

        /**
         * Normalize a departure value into a Y-m-d string.
         *
         * @param mixed        $value    Raw value.
         * @param DateTimeZone $timezone Site timezone.
         *
         * @return string
         */
        private function parse_departure_value($value, DateTimeZone $timezone) {
            if (is_array($value)) {
                $value = reset($value);
            }

            $value = trim((string) $value);

            if ('' === $value) {
                return '';
            }

            $date = DateTimeImmutable::createFromFormat('Y-m-d', $value, $timezone);
            if (!$date) {
                $date = date_create_immutable($value, $timezone);
            }

            if (!$date) {
                return '';
            }

            return $date->format('Y-m-d');
        }

        /**
         * Determine if a given Y-m-d string represents today or a future date.
         *
         * @param string       $date     Normalized date string.
         * @param DateTimeZone $timezone Site timezone.
         *
         * @return bool
         */
        private function is_future_date($date, DateTimeZone $timezone) {
            $target = DateTimeImmutable::createFromFormat('Y-m-d', $date, $timezone);
            if (!$target) {
                return false;
            }

            $target = $target->setTime(0, 0, 0);
            $today  = new DateTimeImmutable('now', $timezone);
            $today  = $today->setTime(0, 0, 0);

            return $target >= $today;
        }

        /**
         * Retrieve departures from the WP Travel Engine REST endpoint.
         *
         * @param int          $trip_id  Trip post ID.
         * @param DateTimeZone $timezone Site timezone.
         *
         * @return array
         */
        private function fetch_departures_via_rest($trip_id, DateTimeZone $timezone) {
            $trip_id = (int) $trip_id;
            if ($trip_id <= 0) {
                return [];
            }

            $response = wp_remote_get(
                rest_url(sprintf('wptravelengine/v3/trips/%d/dates', $trip_id)),
                [
                    'timeout' => 10,
                    'headers' => [
                        'Accept' => 'application/json',
                    ],
                ]
            );

            if (is_wp_error($response)) {
                return [];
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                return [];
            }

            $body = wp_remote_retrieve_body($response);
            if ('' === $body) {
                return [];
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                return [];
            }

            $dates = [];

            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }

                foreach (['date', 'start', 'trip_date'] as $field) {
                    if (empty($row[$field])) {
                        continue;
                    }

                    $normalized = $this->parse_departure_value($row[$field], $timezone);
                    if ('' === $normalized) {
                        continue;
                    }

                    if (!$this->is_future_date($normalized, $timezone)) {
                        continue;
                    }

                    $dates[$normalized] = $normalized;
                }
            }

            ksort($dates, SORT_STRING);

            return array_values($dates);
        }

        /**
         * Retrieve departures from trip meta settings.
         *
         * @param int          $trip_id  Trip post ID.
         * @param DateTimeZone $timezone Site timezone.
         *
         * @return array
         */
        private function fetch_departures_from_meta($trip_id, DateTimeZone $timezone) {
            $settings = get_post_meta($trip_id, 'WTE_Fixed_Starting_Dates_setting', true);
            if (!is_array($settings) || empty($settings['departure_dates']) || !is_array($settings['departure_dates'])) {
                return [];
            }

            $dates = [];

            foreach ($settings['departure_dates'] as $key => $raw) {
                if (is_string($key) && preg_match('/^dates_\d+$/', $key)) {
                    continue;
                }

                $normalized = $this->parse_departure_value($raw, $timezone);
                if ('' === $normalized) {
                    continue;
                }

                if (!$this->is_future_date($normalized, $timezone)) {
                    continue;
                }

                $dates[$normalized] = $normalized;
            }

            ksort($dates, SORT_STRING);

            return array_values($dates);
        }

        /**
         * Retrieve departures from existing bookings as a fallback.
         *
         * @param string       $trip_name Trip display name.
         * @param DateTimeZone $timezone  Site timezone.
         * @param array|null   $rows      Optional pre-fetched rows.
         *
         * @return array
         */
        private function fetch_departures_from_bookings($trip_name, DateTimeZone $timezone, $rows = null) {
            if (null === $rows) {
                $rows = $this->get_all_rows();
            }

            $map = $this->collect_booking_departure_dates($rows, $timezone);
            $key = $this->normalize_trip_key($trip_name);

            if ('' === $key || !isset($map[$key]['dates'])) {
                return [];
            }

            return $map[$key]['dates'];
        }
    }
}

