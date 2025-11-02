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
        const PER_PAGE_OPTIONS = [25, 50, 100, 250];

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
                'per_page_options' => self::PER_PAGE_OPTIONS,
                'trip_departures'  => $this->build_trip_departure_map($trip_options),
                'query_args'       => $request['query_args'],
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

            $per_page = isset($_GET['per_page']) ? (int) wp_unslash($_GET['per_page']) : self::PER_PAGE_OPTIONS[0];
            if (!in_array($per_page, self::PER_PAGE_OPTIONS, true)) {
                $per_page = self::PER_PAGE_OPTIONS[0];
            }

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
                'query_args'=> $this->build_query_args($filters_data['values'], $sort, $dir, $per_page),
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
                'rank'   => 2,
                'days'   => $days_sort,
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
                ];
            }

            if ($days_to_trip > 60) {
                return [
                    'text'  => __('Not Received', 'hr-customer-manager'),
                    'class' => 'text-regular',
                    'rank'  => 0,
                ];
            }

            if ($days_to_trip >= 50) {
                return [
                    'text'  => __('Not Received', 'hr-customer-manager'),
                    'class' => 'text-warn',
                    'rank'  => 1,
                ];
            }

            return [
                'text'  => __('Not Received', 'hr-customer-manager'),
                'class' => 'text-danger',
                'rank'  => 2,
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
                ksort($dates, SORT_NATURAL);
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
                'traveler'  => 'traveler_name',
                'email'     => 'traveler_email',
                'booking'   => 'booking_id',
                'trip'      => 'trip_name',
                'departure' => 'departure_ts',
                'days'      => 'days_to_trip_sort',
                'payment'   => 'payment_status_rank',
                'info'      => 'info_rank',
                'phase'     => 'phase_rank',
                'last_email'=> 'last_email_sent_ts',
                'resend'    => 'resend_rank',
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
         * @param int    $per_page Items per page.
         *
         * @return array
         */
        private function build_query_args($filters, $sort, $dir, $per_page) {
            $args = [
                'page'     => 'hr-customer-manager',
                'sort'     => $sort,
                'dir'      => $dir,
                'per_page' => $per_page,
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
         * Create a simplified map of trips to departure dates.
         *
         * @param array $trip_options Trip options with date lists.
         *
         * @return array
         */
        private function build_trip_departure_map($trip_options) {
            $map = [];

            foreach ($trip_options as $trip_name => $trip) {
                $map[$trip_name] = isset($trip['dates']) ? $trip['dates'] : [];
            }

            return $map;
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

