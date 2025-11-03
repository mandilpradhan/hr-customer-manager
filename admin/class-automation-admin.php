<?php
/**
 * Admin UI for automation rules.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('HR_CM_Automations_Admin')) {
    /**
     * Handles admin rendering for automation rules.
     */
    class HR_CM_Automations_Admin {
        const SCREEN_SLUG = 'hr-customer-manager-automation';
        const NONCE_ACTION = 'hrcm_automation_save';
        const TEST_NONCE_ACTION = 'hrcm_automation_test_send';
        const RUN_NONCE_ACTION = 'hrcm_automation_run_now';
        const DELETE_NONCE_ACTION = 'hrcm_automation_delete';

        /**
         * Singleton instance.
         *
         * @var HR_CM_Automations_Admin|null
         */
        private static $instance = null;

        /**
         * Cached admin page hook.
         *
         * @var string
         */
        private $hook_suffix = '';

        /**
         * Retrieve singleton instance.
         *
         * @return HR_CM_Automations_Admin
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
            add_action('admin_menu', [$this, 'intercept_menu_hook'], 99);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
            add_action('admin_post_hrcm_save_automation', [$this, 'handle_save']);
            add_action('admin_post_hrcm_delete_automation', [$this, 'handle_delete']);
            add_action('admin_post_hrcm_run_automation', [$this, 'handle_run_now']);
            add_action('wp_ajax_hrcm_automation_test_send', [$this, 'ajax_test_send']);
        }

        /**
         * Capture the hook suffix for enqueueing scripts.
         */
        public function intercept_menu_hook() {
            $this->hook_suffix = 'hr-customer-manager_page_' . self::SCREEN_SLUG;
        }

        /**
         * Enqueue assets for automation screens.
         *
         * @param string $hook_suffix Current screen hook.
         */
        public function enqueue_assets($hook_suffix) {
            if ($hook_suffix !== $this->hook_suffix) {
                return;
            }

            $automations = HR_CM_Automations::instance();

            wp_enqueue_style(
                'hrcm-automation-admin',
                HR_CM_PLUGIN_URL . 'admin/assets/css/automation.css',
                ['wp-components'],
                HR_CM_VERSION
            );

            $deps = ['jquery', 'wp-util'];
            if (wp_script_is('wp-api-fetch', 'registered')) {
                $deps[] = 'wp-api-fetch';
            }
            if (wp_script_is('wp-i18n', 'registered')) {
                $deps[] = 'wp-i18n';
            }

            wp_enqueue_script(
                'hrcm-automation-admin',
                HR_CM_PLUGIN_URL . 'admin/assets/js/automation.js',
                $deps,
                HR_CM_VERSION,
                true
            );

            $fields = $this->get_field_config();

            $localize = [
                'ajaxUrl'          => admin_url('admin-ajax.php'),
                'testNonce'        => wp_create_nonce(self::TEST_NONCE_ACTION),
                'fields'           => $fields,
                'operators'        => $this->get_operator_config(),
                'fieldOptions'     => [],
                'i18n'             => [
                    'addCondition' => __('Add condition', 'hr-customer-manager'),
                    'remove'       => __('Remove', 'hr-customer-manager'),
                    'andLabel'     => __('AND', 'hr-customer-manager'),
                    'orLabel'      => __('OR', 'hr-customer-manager'),
                    'headersLabel' => __('Headers', 'hr-customer-manager'),
                    'addHeader'    => __('Add header', 'hr-customer-manager'),
                    'testRunning'  => __('Sending test…', 'hr-customer-manager'),
                    'testFailed'   => __('Test request failed.', 'hr-customer-manager'),
                    'show'         => __('Show', 'hr-customer-manager'),
                    'hide'         => __('Hide', 'hr-customer-manager'),
                    'statusLabel'  => __('Status:', 'hr-customer-manager'),
                    'latencyLabel' => __('Latency:', 'hr-customer-manager'),
                ],
                'mergeTags' => [
                    '{booking_id}',
                    '{trip_name}',
                    '{departure}',
                    '{trip_departure_date}',
                    '{days_to_trip}',
                    '{payment_status}',
                    '{info_received}',
                    '{current_phase}',
                    '{last_email_sent}',
                    '{last_email_template}',
                    '{last_email_sent_age_days}',
                    '{traveler_name}',
                    '{traveler_first_name}',
                    '{traveler_last_name}',
                    '{traveler_email}',
                    '{lead_traveler_name}',
                    '{lead_traveler_first_name}',
                    '{lead_traveler_last_name}',
                    '{lead_traveler_email}',
                    '{first_traveler_name}',
                    '{first_traveler_first_name}',
                    '{first_traveler_last_name}',
                    '{first_traveler_email}',
                    '{travelers}',
                    '{travelers_count}',
                ],
            ];

            foreach ($fields as $key => $config) {
                if (isset($config['options']) && is_array($config['options'])) {
                    $localize['fieldOptions'][$key] = $config['options'];
                }
            }

            wp_localize_script('hrcm-automation-admin', 'hrcmAutomation', $localize);
        }

        /**
         * Build field configuration for the builder.
         *
         * @return array
         */
        private function get_field_config() {
            $automations = HR_CM_Automations::instance();

            return [
                'days_to_trip' => [
                    'label' => __('Days to Trip', 'hr-customer-manager'),
                    'type'  => 'number',
                ],
                'payment_status' => [
                    'label'   => __('Payment Status', 'hr-customer-manager'),
                    'type'    => 'enum',
                    'options' => $automations->get_payment_statuses(),
                ],
                'info_received' => [
                    'label' => __('Info Received', 'hr-customer-manager'),
                    'type'    => 'enum',
                    'options' => ['true', 'false'],
                ],
                'trip_name' => [
                    'label'   => __('Trip Name', 'hr-customer-manager'),
                    'type'    => 'enum',
                    'options' => $automations->get_trip_names(),
                ],
                'last_email_sent_age_days' => [
                    'label' => __('Days Since Last Email Sent', 'hr-customer-manager'),
                    'type'  => 'number',
                ],
            ];
        }

        /**
         * Operator configuration.
         *
         * @return array
         */
        private function get_operator_config() {
            return [
                'number' => [
                    ['value' => '=',  'label' => __('Equals', 'hr-customer-manager')],
                    ['value' => '!=', 'label' => __('Does not equal', 'hr-customer-manager')],
                    ['value' => '<',  'label' => __('Less than', 'hr-customer-manager')],
                    ['value' => '<=', 'label' => __('Less than or equal to', 'hr-customer-manager')],
                    ['value' => '>',  'label' => __('Greater than', 'hr-customer-manager')],
                    ['value' => '>=', 'label' => __('Greater than or equal to', 'hr-customer-manager')],
                    ['value' => 'between', 'label' => __('Between', 'hr-customer-manager')],
                ],
                'enum'   => [
                    ['value' => 'is',     'label' => __('Is', 'hr-customer-manager')],
                    ['value' => 'is not', 'label' => __('Is not', 'hr-customer-manager')],
                    ['value' => 'in',     'label' => __('Is one of', 'hr-customer-manager')],
                    ['value' => 'not in', 'label' => __('Is not one of', 'hr-customer-manager')],
                ],
                'string' => [
                    ['value' => 'contains',      'label' => __('Contains', 'hr-customer-manager')],
                    ['value' => 'not contains',  'label' => __('Does not contain', 'hr-customer-manager')],
                    ['value' => 'is',            'label' => __('Is', 'hr-customer-manager')],
                    ['value' => 'is not',        'label' => __('Is not', 'hr-customer-manager')],
                    ['value' => 'is empty',      'label' => __('Is empty', 'hr-customer-manager')],
                    ['value' => 'is not empty',  'label' => __('Is not empty', 'hr-customer-manager')],
                ],
            ];
        }

        /**
         * Render the list page.
         */
        public function render_list_page() {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to access this page.', 'hr-customer-manager'));
            }

            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
            require_once HR_CM_PLUGIN_DIR . 'admin/class-automation-list-table.php';

            $table = new HR_CM_Automations_List_Table();
            $table->prepare_items();

            $next_run = HR_CM_Automations::instance()->get_next_run_time();

            include HR_CM_PLUGIN_DIR . 'admin/views/automation-list.php';
        }

        /**
         * Render the edit form.
         *
         * @param int $post_id Optional post ID.
         */
        public function render_edit_page($post_id = 0) {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to access this page.', 'hr-customer-manager'));
            }

            $post     = null;
            $rule     = [];
            $status   = 'draft';
            $is_new   = true;
            $automations = HR_CM_Automations::instance();

            if ($post_id > 0) {
                $post = $automations->get_rule_post($post_id);
                if (!$post) {
                    wp_die(__('Automation rule not found.', 'hr-customer-manager'));
                }

                $rule   = $automations->get_rule($post_id);
                $status = $post->post_status;
                $is_new = false;
            } else {
                $rule = $automations->sanitize_rule([]);
            }

            $field_config = $this->get_field_config();
            $operator_config = $this->get_operator_config();

            include HR_CM_PLUGIN_DIR . 'admin/views/automation-edit.php';
        }

        /**
         * Handle rule persistence.
         */
        public function handle_save() {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to perform this action.', 'hr-customer-manager'));
            }

            check_admin_referer(self::NONCE_ACTION);

            $post_id = isset($_POST['rule_id']) ? (int) $_POST['rule_id'] : 0;
            $status  = isset($_POST['rule_status']) && 'publish' === $_POST['rule_status'] ? 'publish' : 'draft';
            $name    = isset($_POST['rule_name']) ? sanitize_text_field(wp_unslash($_POST['rule_name'])) : '';

            if ('' === $name) {
                $redirect = add_query_arg(
                    [
                        'page'    => self::SCREEN_SLUG,
                        'action'  => $post_id ? 'edit' : 'new',
                        'rule'    => $post_id,
                        'message' => 'missing-name',
                    ],
                    admin_url('admin.php')
                );
                wp_safe_redirect($redirect);
                exit;
            }

            $raw_rule = [];
            if (isset($_POST['form'])) {
                $form_data = wp_unslash($_POST['form']);
                $parsed = [];
                wp_parse_str($form_data, $parsed);
                if (isset($parsed['rule'])) {
                    $raw_rule = $parsed['rule'];
                }
            } elseif (isset($_POST['rule'])) {
                $raw_rule = wp_unslash($_POST['rule']);
            }

            $automations = HR_CM_Automations::instance();
            $rule = $automations->sanitize_rule($raw_rule);

            if ($post_id > 0) {
                $post = $automations->get_rule_post($post_id);
                if (!$post) {
                    wp_die(__('Automation rule not found.', 'hr-customer-manager'));
                }

                wp_update_post([
                    'ID'          => $post_id,
                    'post_title'  => $name,
                    'post_status' => $status,
                ]);
            } else {
                $post_id = wp_insert_post([
                    'post_type'   => HR_CM_Automations::POST_TYPE,
                    'post_title'  => $name,
                    'post_status' => $status,
                ]);

                if (is_wp_error($post_id)) {
                    wp_die($post_id);
                }
            }

            $automations->save_rule($post_id, $rule);

            $redirect = add_query_arg([
                'page'    => self::SCREEN_SLUG,
                'message' => 'saved',
            ], admin_url('admin.php'));

            wp_safe_redirect($redirect);
            exit;
        }

        /**
         * Handle deletion.
         */
        public function handle_delete() {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to perform this action.', 'hr-customer-manager'));
            }

            check_admin_referer(self::DELETE_NONCE_ACTION);

            $post_id = isset($_POST['rule_id']) ? (int) $_POST['rule_id'] : 0;
            if ($post_id > 0) {
                wp_delete_post($post_id, true);
            }

            $redirect = add_query_arg([
                'page'    => self::SCREEN_SLUG,
                'message' => 'deleted',
            ], admin_url('admin.php'));

            wp_safe_redirect($redirect);
            exit;
        }

        /**
         * Handle run-now requests.
         */
        public function handle_run_now() {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to perform this action.', 'hr-customer-manager'));
            }

            check_admin_referer(self::RUN_NONCE_ACTION);

            $post_id = isset($_POST['rule_id']) ? (int) $_POST['rule_id'] : 0;
            if ($post_id > 0) {
                wp_schedule_single_event(time() + 5, HR_CM_Automations::CRON_HOOK, [$post_id]);
                if (function_exists('spawn_cron')) {
                    spawn_cron(time());
                }
            }

            $redirect = add_query_arg([
                'page'    => self::SCREEN_SLUG,
                'message' => 'queued',
            ], admin_url('admin.php'));

            wp_safe_redirect($redirect);
            exit;
        }

        /**
         * AJAX test send handler.
         */
        public function ajax_test_send() {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Unauthorized', 'hr-customer-manager')], 403);
            }

            check_ajax_referer(self::TEST_NONCE_ACTION, 'nonce');

            $raw_rule = [];
            if (isset($_POST['form'])) {
                $form_data = wp_unslash($_POST['form']);
                $parsed = [];
                wp_parse_str($form_data, $parsed);
                if (isset($parsed['rule'])) {
                    $raw_rule = $parsed['rule'];
                }
            } elseif (isset($_POST['rule'])) {
                $raw_rule = wp_unslash($_POST['rule']);
            }
            $automations = HR_CM_Automations::instance();
            $rule = $automations->sanitize_rule($raw_rule);

            if (empty($rule['action']['url'])) {
                wp_send_json_error(['message' => __('Webhook URL is required.', 'hr-customer-manager')], 400);
            }

            $sample = $automations->find_sample_booking($rule);
            if (!$sample) {
                wp_send_json_error(['message' => __('No bookings available for testing.', 'hr-customer-manager')], 404);
            }

            $response = $automations->dispatch_webhook($rule, $sample);
            if (!$response) {
                wp_send_json_error(['message' => __('Webhook dispatch failed.', 'hr-customer-manager')], 500);
            }

            wp_send_json_success([
                'status'  => $response['status'],
                'body'    => $response['body'],
                'latency' => $response['latency'],
                'booking' => $sample,
            ]);
        }
    }
}
