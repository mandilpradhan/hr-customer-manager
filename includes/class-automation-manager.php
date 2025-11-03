<?php
/**
 * Automation engine for HR Customer Manager.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('HR_CM_Automations')) {
    /**
     * Handles rule storage, scheduling, and execution.
     */
    class HR_CM_Automations {
        const POST_TYPE = 'hrcm_automation';
        const META_KEY = '_hrcm_rule';
        const DELIVERY_OPTION = 'hrcm_automation_deliveries';
        const CRON_HOOK = 'hrcm_automations_runner';
        const CRON_SCHEDULE = 'hrcm_15_minutes';
        const DEFAULT_BOOKING_WINDOW_MONTHS = 18;
        const DELIVERY_TTL_DAYS = 365;

        /**
         * Singleton instance.
         *
         * @var HR_CM_Automations|null
         */
        private static $instance = null;

        /**
         * Cached deliveries map.
         *
         * @var array|null
         */
        private $deliveries_cache = null;

        /**
         * Retrieve the singleton instance.
         *
         * @return HR_CM_Automations
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
            add_action('init', [$this, 'register_post_type']);
            add_filter('cron_schedules', [$this, 'register_cron_schedule']);
            add_action('admin_init', [$this, 'ensure_cron']);
            add_action(self::CRON_HOOK, [$this, 'run_automations'], 10, 1);
        }

        /**
         * Register the automation custom post type.
         */
        public function register_post_type() {
            if (post_type_exists(self::POST_TYPE)) {
                return;
            }

            $labels = [
                'name'                  => __('Automations', 'hr-customer-manager'),
                'singular_name'         => __('Automation', 'hr-customer-manager'),
                'add_new'               => __('Add New', 'hr-customer-manager'),
                'add_new_item'          => __('Add New Automation', 'hr-customer-manager'),
                'edit_item'             => __('Edit Automation', 'hr-customer-manager'),
                'new_item'              => __('New Automation', 'hr-customer-manager'),
                'view_item'             => __('View Automation', 'hr-customer-manager'),
                'search_items'          => __('Search Automations', 'hr-customer-manager'),
                'not_found'             => __('No automations found.', 'hr-customer-manager'),
                'not_found_in_trash'    => __('No automations found in Trash.', 'hr-customer-manager'),
                'all_items'             => __('Automations', 'hr-customer-manager'),
                'menu_name'             => __('Automations', 'hr-customer-manager'),
                'name_admin_bar'        => __('Automation', 'hr-customer-manager'),
            ];

            $args = [
                'labels'              => $labels,
                'public'              => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'exclude_from_search' => true,
                'rewrite'             => false,
                'supports'            => ['title'],
                'capability_type'     => 'post',
            ];

            register_post_type(self::POST_TYPE, $args);
        }

        /**
         * Register a 15 minute cron schedule.
         *
         * @param array $schedules Existing schedules.
         *
         * @return array
         */
        public function register_cron_schedule($schedules) {
            if (!is_array($schedules)) {
                $schedules = [];
            }

            if (!isset($schedules[self::CRON_SCHEDULE])) {
                $schedules[self::CRON_SCHEDULE] = [
                    'interval' => 15 * MINUTE_IN_SECONDS,
                    'display'  => __('Every 15 Minutes (HRCM Automations)', 'hr-customer-manager'),
                ];
            }

            return $schedules;
        }

        /**
         * Ensure the cron event is scheduled.
         */
        public function ensure_cron() {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK);
            }
        }

        /**
         * Retrieve a rule post.
         *
         * @param int $post_id Post ID.
         *
         * @return WP_Post|null
         */
        public function get_rule_post($post_id) {
            $post = get_post($post_id);
            if (!$post || self::POST_TYPE !== $post->post_type) {
                return null;
            }

            return $post;
        }

        /**
         * Retrieve rule data array for a post.
         *
         * @param int $post_id Post ID.
         *
         * @return array
         */
        public function get_rule($post_id) {
            $defaults = [
                'conditions' => [],
                'action'     => [
                    'type'    => 'webhook',
                    'url'     => '',
                    'method'  => 'POST',
                    'headers' => [],
                    'payload' => '{"booking_id":"{booking_id}"}',
                    'raw'     => false,
                ],
                'schedule'   => [
                    'cadence' => 'every_15_minutes',
                    'repeat'  => false,
                ],
                'last_run'    => 0,
                'last_result' => null,
            ];

            $stored = get_post_meta($post_id, self::META_KEY, true);
            if (!is_array($stored)) {
                $stored = [];
            }

            $rule = wp_parse_args($stored, $defaults);

            $rule['conditions'] = is_array($rule['conditions']) ? array_values(array_filter($rule['conditions'])) : [];
            $rule['action']     = is_array($rule['action']) ? $rule['action'] : $defaults['action'];
            $rule['schedule']   = is_array($rule['schedule']) ? wp_parse_args($rule['schedule'], $defaults['schedule']) : $defaults['schedule'];

            return $rule;
        }

        /**
         * Save a rule configuration.
         *
         * @param int   $post_id Post ID.
         * @param array $data    Raw data.
         */
        public function save_rule($post_id, $data) {
            $sanitized = $this->sanitize_rule($data);
            update_post_meta($post_id, self::META_KEY, $sanitized);
        }

        /**
         * Sanitize a rule array.
         *
         * @param array $data Raw data.
         *
         * @return array
         */
        public function sanitize_rule($data) {
            $rule = [
                'conditions' => [],
                'action'     => [
                    'type'    => 'webhook',
                    'url'     => '',
                    'method'  => 'POST',
                    'headers' => [],
                    'payload' => '',
                    'raw'     => false,
                ],
                'schedule' => [
                    'cadence' => 'every_15_minutes',
                    'repeat'  => false,
                ],
                'last_run'    => 0,
                'last_result' => null,
            ];

            if (isset($data['conditions']) && is_array($data['conditions'])) {
                foreach ($data['conditions'] as $condition) {
                    if (!is_array($condition)) {
                        continue;
                    }

                    $field = isset($condition['field']) ? sanitize_key($condition['field']) : '';
                    $op    = isset($condition['op']) ? sanitize_text_field($condition['op']) : '';
                    $join  = isset($condition['join']) ? strtoupper(sanitize_text_field($condition['join'])) : 'AND';

                    if ('' === $field || '' === $op) {
                        continue;
                    }

                    $value = isset($condition['value']) ? $condition['value'] : '';

                    if (is_array($value)) {
                        $value = $this->sanitize_condition_array_value($value);
                    } else {
                        $value = $this->sanitize_condition_scalar_value($value, $field);
                    }

                    $rule['conditions'][] = [
                        'field' => $field,
                        'op'    => $op,
                        'value' => $value,
                        'join'  => in_array($join, ['AND', 'OR'], true) ? $join : 'AND',
                    ];
                }
            }

            $action = isset($data['action']) && is_array($data['action']) ? $data['action'] : [];
            $rule['action']['type']   = 'webhook';
            $rule['action']['method'] = isset($action['method']) ? strtoupper(sanitize_text_field($action['method'])) : 'POST';
            if (!in_array($rule['action']['method'], ['GET', 'POST'], true)) {
                $rule['action']['method'] = 'POST';
            }

            $url = isset($action['url']) ? esc_url_raw(trim($action['url'])) : '';
            if ('' !== $url && !preg_match('#^https?://#i', $url)) {
                $url = '';
            }
            $rule['action']['url'] = $url;

            $rule['action']['headers'] = [];
            if (isset($action['headers']) && is_array($action['headers'])) {
                foreach ($action['headers'] as $header) {
                    if (!is_array($header)) {
                        continue;
                    }

                    $k = isset($header['k']) ? trim(sanitize_text_field($header['k'])) : '';
                    $v = isset($header['v']) ? trim(sanitize_text_field($header['v'])) : '';
                    if ('' === $k) {
                        continue;
                    }

                    $rule['action']['headers'][] = [
                        'k' => $k,
                        'v' => $v,
                    ];
                }
            }

            $payload = isset($action['payload']) ? wp_kses_post($action['payload']) : '';
            $rule['action']['payload'] = $payload;

            $rule['action']['raw'] = isset($action['raw']) ? (bool) $action['raw'] : false;

            $schedule = isset($data['schedule']) && is_array($data['schedule']) ? $data['schedule'] : [];
            $repeat   = isset($schedule['repeat']) ? (bool) $schedule['repeat'] : false;
            $rule['schedule']['repeat'] = $repeat;
            $rule['schedule']['cadence'] = 'every_15_minutes';

            if (isset($data['last_run'])) {
                $rule['last_run'] = (int) $data['last_run'];
            }

            if (isset($data['last_result'])) {
                $rule['last_result'] = sanitize_text_field($data['last_result']);
            }

            return $rule;
        }

        /**
         * Sanitize scalar condition values.
         *
         * @param mixed  $value Raw value.
         * @param string $field Field key.
         *
         * @return mixed
         */
        private function sanitize_condition_scalar_value($value, $field) {
            if (in_array($field, ['days_to_trip', 'last_email_sent_age_days'], true)) {
                return is_numeric($value) ? (int) $value : null;
            }

            return sanitize_text_field($value);
        }

        /**
         * Sanitize complex condition values.
         *
         * @param array  $value Array value.
         * @param string $field Field key.
         *
         * @return array
         */
        private function sanitize_condition_array_value($value, $field = '') {
            $clean = [];
            foreach ($value as $key => $v) {
                if (is_array($v)) {
                    $clean[$key] = $this->sanitize_condition_array_value($v, $field);
                    continue;
                }

                if (in_array($field, ['days_to_trip', 'last_email_sent_age_days'], true)) {
                    $clean[$key] = is_numeric($v) ? (int) $v : null;
                } else {
                    $clean[$key] = sanitize_text_field($v);
                }
            }

            return $clean;
        }

        /**
         * Retrieve paginated automation posts.
         *
         * @param array $args Query arguments.
         *
         * @return array
         */
        public function get_rules_query($args = []) {
            $defaults = [
                'post_type'      => self::POST_TYPE,
                'post_status'    => ['publish', 'draft'],
                'posts_per_page' => 20,
                'paged'          => 1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ];

            $query_args = wp_parse_args($args, $defaults);
            $query = new WP_Query($query_args);

            return $query;
        }

        /**
         * Run automations for all or a specific rule.
         *
         * @param int $rule_id Optional rule ID.
         */
        public function run_automations($rule_id = 0) {
            if (!class_exists('WP_Query')) {
                return;
            }

            $rule_ids = [];
            if ($rule_id > 0) {
                $post = $this->get_rule_post($rule_id);
                if ($post && 'publish' === $post->post_status) {
                    $rule_ids[] = $post->ID;
                }
            } else {
                $posts = get_posts([
                    'post_type'      => self::POST_TYPE,
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                    'fields'         => 'ids',
                ]);

                if (!empty($posts)) {
                    $rule_ids = array_map('intval', $posts);
                }
            }

            if (empty($rule_ids)) {
                return;
            }

            $bookings = $this->get_candidate_bookings();
            if (empty($bookings)) {
                foreach ($rule_ids as $rid) {
                    $this->touch_rule($rid, __('No bookings in scope', 'hr-customer-manager'));
                }

                return;
            }

            foreach ($rule_ids as $rid) {
                $rule = $this->get_rule($rid);
                $matches = $this->filter_bookings_for_rule($rule, $bookings);
                $results = [];

                foreach ($matches as $context) {
                    if (!$this->should_send_for_booking($rid, $context, $rule)) {
                        continue;
                    }

                    $response = $this->dispatch_webhook($rule, $context);
                    if ($response && isset($response['summary'])) {
                        $results[] = $response['summary'];
                    }
                }

                $summary = empty($results)
                    ? __('No matching bookings', 'hr-customer-manager')
                    : implode('; ', array_slice($results, 0, 5));

                $this->touch_rule($rid, $summary);
            }
        }

        /**
         * Update last run metadata.
         *
         * @param int    $rule_id Rule ID.
         * @param string $result  Result summary.
         */
        public function touch_rule($rule_id, $result) {
            $rule = $this->get_rule($rule_id);
            $rule['last_run']    = time();
            $rule['last_result'] = sanitize_text_field($result);
            update_post_meta($rule_id, self::META_KEY, $rule);
        }

        /**
         * Determine if a webhook should be sent for a booking.
         *
         * @param int   $rule_id Rule ID.
         * @param array $context Booking context.
         * @param array $rule    Rule.
         *
         * @return bool
         */
        private function should_send_for_booking($rule_id, $context, $rule) {
            $repeat = isset($rule['schedule']['repeat']) ? (bool) $rule['schedule']['repeat'] : false;
            if ($repeat) {
                return true;
            }

            $hash = md5($rule_id . '|' . $context['booking_id']);
            $deliveries = $this->get_deliveries();

            if (isset($deliveries[$hash])) {
                return false;
            }

            $deliveries[$hash] = time();
            $this->set_deliveries($deliveries);

            return true;
        }

        /**
         * Retrieve deliveries cache.
         *
         * @return array
         */
        private function get_deliveries() {
            if (null !== $this->deliveries_cache) {
                return $this->deliveries_cache;
            }

            $stored = get_option(self::DELIVERY_OPTION, []);
            if (!is_array($stored)) {
                $stored = [];
            }

            $ttl = time() - (self::DELIVERY_TTL_DAYS * DAY_IN_SECONDS);
            foreach ($stored as $hash => $timestamp) {
                if ((int) $timestamp < $ttl) {
                    unset($stored[$hash]);
                }
            }

            $this->deliveries_cache = $stored;

            return $this->deliveries_cache;
        }

        /**
         * Persist deliveries cache.
         *
         * @param array $deliveries Deliveries map.
         */
        private function set_deliveries($deliveries) {
            $this->deliveries_cache = $deliveries;
            update_option(self::DELIVERY_OPTION, $deliveries, false);
        }

        /**
         * Retrieve candidate bookings.
         *
         * @return array
         */
        public function get_candidate_bookings() {
            if (!class_exists('WP_Query')) {
                return [];
            }

            $months = (int) apply_filters('hrcm_automations_booking_window', self::DEFAULT_BOOKING_WINDOW_MONTHS);
            if ($months <= 0) {
                $months = self::DEFAULT_BOOKING_WINDOW_MONTHS;
            }

            $timezone = wp_timezone();
            $now      = new DateTimeImmutable('now', $timezone);
            $start    = $now->sub(new DateInterval('P' . max(1, $months) . 'M'));

            $query = new WP_Query([
                'post_type'      => 'booking',
                'post_status'    => ['publish', 'pending', 'confirmed', 'complete'],
                'posts_per_page' => -1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'date_query'     => [
                    [
                        'after'     => $start->format('Y-m-d H:i:s'),
                        'inclusive' => true,
                    ],
                ],
                'no_found_rows'  => true,
                'fields'         => 'ids',
            ]);

            if (!$query->have_posts()) {
                return [];
            }

            $contexts = [];
            foreach ($query->posts as $booking_id) {
                $context = $this->build_booking_context($booking_id, $timezone);
                if ($context) {
                    $contexts[] = $context;
                }
            }

            return $contexts;
        }

        /**
         * Build booking context for rule evaluation.
         *
         * @param int           $booking_id Booking post ID.
         * @param DateTimeZone  $timezone   Site timezone.
         *
         * @return array|null
         */
        private function build_booking_context($booking_id, DateTimeZone $timezone) {
            $travelers = HR_CM_Data::get_travelers($booking_id);
            $trip      = HR_CM_Data::get_trip($booking_id);
            $payment   = HR_CM_Data::get_payment($booking_id);
            $lead      = HR_CM_Data::get_lead($booking_id);

            $first_traveler = [];
            if (!empty($travelers)) {
                $first_traveler = $travelers[0];
            }

            $first_traveler = array_merge(
                [
                    'name'       => '',
                    'first_name' => '',
                    'last_name'  => '',
                    'email'      => '',
                ],
                is_array($first_traveler) ? $first_traveler : []
            );

            $departure = isset($trip['date']) ? $trip['date'] : '';
            $days_to_trip = HR_CM_Data::days_to_trip($departure, $timezone);

            $info_received_raw = $this->get_optional_meta($booking_id, ['info_received', 'hrcm_info_received']);
            $info_received     = $this->normalize_boolean_flag($info_received_raw);
            $current_phase = $this->get_optional_meta($booking_id, ['hrcm_current_phase', 'current_phase']);
            $last_email    = $this->get_optional_meta($booking_id, ['hrcm_last_email_sent', 'last_email_sent']);
            $last_template = $this->get_optional_meta($booking_id, ['hrcm_last_email_template', 'last_email_template']);

            $last_email_ts = $this->parse_datetime($last_email, $timezone);
            $last_email_age = null;
            if ($last_email_ts) {
                $diff = $last_email_ts->diff(new DateTimeImmutable('now', $timezone));
                $last_email_age = (int) $diff->format('%a');
            }

            return [
                'booking_id'             => (int) $booking_id,
                'trip_name'              => isset($trip['name']) ? $trip['name'] : '',
                'departure'              => $departure,
                'trip_departure_date'    => $departure,
                'days_to_trip'           => $days_to_trip,
                'payment_status'         => isset($payment['p_status']) ? $payment['p_status'] : '',
                'info_received'          => $info_received,
                'current_phase'          => $current_phase,
                'last_email_sent'        => $last_email_ts ? $last_email_ts->format('c') : null,
                'last_email_template'    => $last_template,
                'last_email_sent_age_days' => $last_email_age,
                'traveler_name'          => isset($lead['name']) ? $lead['name'] : '',
                'traveler_email'         => isset($lead['email']) ? $lead['email'] : '',
                'traveler_first_name'    => isset($lead['first_name']) ? $lead['first_name'] : '',
                'traveler_last_name'     => isset($lead['last_name']) ? $lead['last_name'] : '',
                'lead_traveler_name'     => isset($lead['name']) ? $lead['name'] : '',
                'lead_traveler_email'    => isset($lead['email']) ? $lead['email'] : '',
                'lead_traveler_first_name' => isset($lead['first_name']) ? $lead['first_name'] : '',
                'lead_traveler_last_name'  => isset($lead['last_name']) ? $lead['last_name'] : '',
                'first_traveler_name'    => isset($first_traveler['name']) ? $first_traveler['name'] : '',
                'first_traveler_email'   => isset($first_traveler['email']) ? $first_traveler['email'] : '',
                'first_traveler_first_name' => isset($first_traveler['first_name']) ? $first_traveler['first_name'] : '',
                'first_traveler_last_name'  => isset($first_traveler['last_name']) ? $first_traveler['last_name'] : '',
                'travelers'              => $travelers,
                'travelers_count'        => count($travelers),
            ];
        }

        /**
         * Retrieve an optional meta value using multiple keys.
         *
         * @param int   $booking_id Booking ID.
         * @param array $keys       Candidate keys.
         *
         * @return string
         */
        private function get_optional_meta($booking_id, $keys) {
            foreach ($keys as $key) {
                $value = get_post_meta($booking_id, $key, true);
                if ('' !== $value && null !== $value) {
                    return is_scalar($value) ? sanitize_text_field((string) $value) : '';
                }
            }

            return '';
        }

        /**
         * Attempt to parse a stored datetime value.
         *
         * @param string       $value Raw value.
         * @param DateTimeZone $timezone Site timezone.
         *
         * @return DateTimeImmutable|null
         */
        private function parse_datetime($value, DateTimeZone $timezone) {
            $value = trim((string) $value);
            if ('' === $value) {
                return null;
            }

            $formats = ['Y-m-d H:i:s', DateTime::ATOM, DateTime::RFC3339, 'Y-m-d'];
            foreach ($formats as $format) {
                $dt = DateTimeImmutable::createFromFormat($format, $value, $timezone);
                if ($dt instanceof DateTimeImmutable) {
                    return $dt;
                }
            }

            $dt = date_create_immutable($value, $timezone);
            if ($dt instanceof DateTimeImmutable) {
                return $dt;
            }

            return null;
        }

        /**
         * Normalize a truthy/falsey meta value to a string flag.
         *
         * @param mixed $value Raw meta value.
         *
         * @return string "true" or "false".
         */
        private function normalize_boolean_flag($value) {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            if (is_numeric($value)) {
                return ((int) $value) !== 0 ? 'true' : 'false';
            }

            $value = strtolower(trim((string) $value));

            if ('' === $value) {
                return 'false';
            }

            if (in_array($value, ['true', 'yes', 'y', 'on'], true)) {
                return 'true';
            }

            if (in_array($value, ['false', 'no', 'n', 'off'], true)) {
                return 'false';
            }

            return 'true';
        }

        /**
         * Filter bookings for a rule.
         *
         * @param array $rule     Rule configuration.
         * @param array $bookings Booking contexts.
         *
         * @return array
         */
        public function filter_bookings_for_rule($rule, $bookings) {
            if (empty($rule['conditions'])) {
                return $bookings;
            }

            $results = [];
            foreach ($bookings as $context) {
                if ($this->evaluate_conditions($rule['conditions'], $context)) {
                    $results[] = $context;
                }
            }

            return $results;
        }

        /**
         * Evaluate a list of conditions.
         *
         * @param array $conditions Conditions array.
         * @param array $context    Booking context.
         *
         * @return bool
         */
        public function evaluate_conditions($conditions, $context) {
            if (empty($conditions)) {
                return true;
            }

            $result = null;
            $pending_join = 'AND';

            foreach ($conditions as $index => $condition) {
                $evaluation = $this->evaluate_condition($condition, $context);

                if (null === $result) {
                    $result = $evaluation;
                } elseif ('AND' === $pending_join) {
                    $result = $result && $evaluation;
                } else {
                    $result = $result || $evaluation;
                }

                $join = isset($condition['join']) ? strtoupper($condition['join']) : 'AND';
                $pending_join = in_array($join, ['AND', 'OR'], true) ? $join : 'AND';
            }

            return (bool) $result;
        }

        /**
         * Evaluate a single condition.
         *
         * @param array $condition Condition array.
         * @param array $context   Booking context.
         *
         * @return bool
         */
        private function evaluate_condition($condition, $context) {
            $field = isset($condition['field']) ? $condition['field'] : '';
            $op    = isset($condition['op']) ? $condition['op'] : '';
            $value = isset($condition['value']) ? $condition['value'] : null;

            if ('' === $field || '' === $op) {
                return true;
            }

            $actual = isset($context[$field]) ? $context[$field] : null;

            switch ($op) {
                case '=':
                    return $actual == $value;
                case '!=':
                    return $actual != $value;
                case '<':
                    return is_numeric($actual) && is_numeric($value) ? $actual < $value : false;
                case '<=':
                    return is_numeric($actual) && is_numeric($value) ? $actual <= $value : false;
                case '>':
                    return is_numeric($actual) && is_numeric($value) ? $actual > $value : false;
                case '>=':
                    return is_numeric($actual) && is_numeric($value) ? $actual >= $value : false;
                case 'between':
                    if (!is_array($value)) {
                        return false;
                    }
                    $min = isset($value['min']) ? (int) $value['min'] : null;
                    $max = isset($value['max']) ? (int) $value['max'] : null;
                    if (!is_numeric($actual)) {
                        return false;
                    }
                    if (null !== $min && $actual < $min) {
                        return false;
                    }
                    if (null !== $max && $actual > $max) {
                        return false;
                    }
                    return true;
                case 'is':
                    return $this->compare_string($actual, $value, true);
                case 'is not':
                    return !$this->compare_string($actual, $value, true);
                case 'in':
                    $values = is_array($value) ? $value : explode(',', (string) $value);
                    $values = array_map('trim', $values);
                    return in_array($actual, $values, true);
                case 'not in':
                    $values = is_array($value) ? $value : explode(',', (string) $value);
                    $values = array_map('trim', $values);
                    return !in_array($actual, $values, true);
                case 'contains':
                    return $this->string_contains($actual, $value);
                case 'not contains':
                    return !$this->string_contains($actual, $value);
                case 'is empty':
                    return '' === $actual || null === $actual;
                case 'is not empty':
                    return '' !== $actual && null !== $actual;
                default:
                    return false;
            }
        }

        /**
         * Compare strings with strict option.
         *
         * @param mixed $actual Actual value.
         * @param mixed $expected Expected value.
         * @param bool  $strict Whether to use strict comparison.
         *
         * @return bool
         */
        private function compare_string($actual, $expected, $strict = false) {
            $actual   = is_scalar($actual) ? (string) $actual : '';
            $expected = is_scalar($expected) ? (string) $expected : '';

            if ($strict) {
                return $actual === $expected;
            }

            return strtolower($actual) === strtolower($expected);
        }

        /**
         * Determine whether a string contains a substring.
         *
         * @param mixed $actual Actual value.
         * @param mixed $expected Expected value.
         *
         * @return bool
         */
        private function string_contains($actual, $expected) {
            $actual   = is_scalar($actual) ? (string) $actual : '';
            $expected = is_scalar($expected) ? (string) $expected : '';

            if ('' === $expected) {
                return false;
            }

            return false !== stripos($actual, $expected);
        }

        /**
         * Dispatch the webhook action for a context.
         *
         * @param array $rule    Rule configuration.
         * @param array $context Booking context.
         *
         * @return array|null
         */
        public function dispatch_webhook($rule, $context) {
            $url = isset($rule['action']['url']) ? $rule['action']['url'] : '';
            if ('' === $url) {
                return null;
            }

            $method  = isset($rule['action']['method']) ? $rule['action']['method'] : 'POST';
            $raw     = isset($rule['action']['raw']) ? (bool) $rule['action']['raw'] : false;
            $payload = isset($rule['action']['payload']) ? $rule['action']['payload'] : '';
            $body    = $this->render_payload($payload, $context, $raw);

            $headers = [];
            if (isset($rule['action']['headers']) && is_array($rule['action']['headers'])) {
                foreach ($rule['action']['headers'] as $header) {
                    if (!is_array($header) || empty($header['k'])) {
                        continue;
                    }

                    $headers[$header['k']] = $this->render_merge_tags($header['v'], $context);
                }
            }

            if (!$raw && !isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/json; charset=utf-8';
            }

            $args = [
                'method'      => $method,
                'timeout'     => 20,
                'redirection' => 0,
                'headers'     => $headers,
                'sslverify'   => true,
                'body'        => $body,
            ];

            $start = microtime(true);
            $response = wp_remote_request($url, $args);
            $latency = microtime(true) - $start;

            if (is_wp_error($response)) {
                return [
                    'status'  => 0,
                    'body'    => $response->get_error_message(),
                    'summary' => sprintf(__('Error: %s', 'hr-customer-manager'), $response->get_error_message()),
                    'latency' => $latency,
                ];
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $snippet = mb_substr($body, 0, 1024);

            return [
                'status'  => $code,
                'body'    => $snippet,
                'summary' => sprintf(__('HTTP %1$d in %2$.2fs', 'hr-customer-manager'), $code, $latency),
                'latency' => $latency,
            ];
        }

        /**
         * Render payload with merge tags.
         *
         * @param string $template Payload template.
         * @param array  $context  Booking context.
         * @param bool   $raw      Whether to send raw payload.
         *
         * @return string
         */
        public function render_payload($template, $context, $raw = false) {
            $rendered = $this->render_merge_tags($template, $context);

            if ($raw) {
                return $rendered;
            }

            if ('' === trim($rendered)) {
                $rendered = wp_json_encode($context);
            }

            return $rendered;
        }

        /**
         * Replace merge tags with context values.
         *
         * @param string $value   Template value.
         * @param array  $context Booking context.
         *
         * @return string
         */
        public function render_merge_tags($value, $context) {
            $value = (string) $value;
            $tags = [
                'booking_id',
                'trip_name',
                'departure',
                'trip_departure_date',
                'days_to_trip',
                'payment_status',
                'info_received',
                'current_phase',
                'last_email_sent',
                'last_email_template',
                'last_email_sent_age_days',
                'traveler_name',
                'traveler_email',
                'traveler_first_name',
                'traveler_last_name',
                'lead_traveler_name',
                'lead_traveler_email',
                'lead_traveler_first_name',
                'lead_traveler_last_name',
            ];

            foreach ($tags as $tag) {
                $replacement = isset($context[$tag]) ? $context[$tag] : '';
                if (is_bool($replacement)) {
                    $replacement = $replacement ? '1' : '0';
                } elseif (is_array($replacement) || is_object($replacement)) {
                    $replacement = wp_json_encode($replacement);
                }

                $value = str_replace('{' . $tag . '}', (string) $replacement, $value);
            }

            return $value;
        }

        /**
         * Retrieve payment status options from recent bookings.
         *
         * @return array
         */
        public function get_payment_statuses() {
            global $wpdb;

            $meta_key = 'wp_travel_engine_booking_payment_status';
            $table    = $wpdb->postmeta;
            $results  = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT meta_value FROM {$table} WHERE meta_key = %s", $meta_key));

            if (empty($results)) {
                return [];
            }

            $statuses = [];
            foreach ($results as $value) {
                $value = sanitize_text_field($value);
                if ('' === $value) {
                    continue;
                }

                $statuses[] = strtolower($value);
            }

            $defaults = ['pending', 'balance due', 'fully paid'];
            $statuses = array_merge($defaults, $statuses);
            $statuses = array_map('strtolower', $statuses);
            $statuses = array_values(array_unique($statuses));
            sort($statuses, SORT_NATURAL | SORT_FLAG_CASE);

            return $statuses;
        }

        /**
         * Retrieve trip names from posts and bookings.
         *
         * @return array
         */
        public function get_trip_names() {
            $names = [];

            if (post_type_exists('trip')) {
                $trip_posts = get_posts([
                    'post_type'      => 'trip',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                    'fields'         => 'ids',
                ]);

                foreach ($trip_posts as $trip_id) {
                    $title = get_the_title($trip_id);
                    if ($title) {
                        $names[] = $title;
                    }
                }
            }

            $names = array_merge($names, HR_CM_Data::get_all_booking_trip_names());
            $names = array_filter(array_map('sanitize_text_field', $names));
            $names = array_values(array_unique($names));

            sort($names, SORT_NATURAL | SORT_FLAG_CASE);

            return $names;
        }

        /**
         * Retrieve the next scheduled timestamp for the cron hook.
         *
         * @return int|false
         */
        public function get_next_run_time() {
            return wp_next_scheduled(self::CRON_HOOK);
        }

        /**
         * Locate a booking that matches a rule for testing.
         *
         * @param array $rule Rule configuration.
         *
         * @return array|null
         */
        public function find_sample_booking($rule) {
            $bookings = $this->get_candidate_bookings();
            if (empty($bookings)) {
                return null;
            }

            if (empty($rule['conditions'])) {
                return $bookings[0];
            }

            foreach ($bookings as $context) {
                if ($this->evaluate_conditions($rule['conditions'], $context)) {
                    return $context;
                }
            }

            return $bookings[0];
        }
    }
}
