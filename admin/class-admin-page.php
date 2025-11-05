<?php
/**
 * Admin page router for HR Customer Manager.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once HR_CM_PLUGIN_DIR . 'admin/class-automation-admin.php';

if (!class_exists('HR_CM_Admin_Page')) {
    /**
     * Handles admin menu registration and rendering.
     */
    class HR_CM_Admin_Page {
        /**
         * Singleton instance.
         *
         * @var HR_CM_Admin_Page|null
         */
        private static $instance = null;

        /**
         * List of hooks for which assets should load.
         *
         * @var array
         */
        private $screen_hooks = [];

        /**
         * Cached column labels for the overview screen.
         *
         * @var array
         */
        private $column_labels = [];

        /**
         * Get singleton instance.
         *
         * @return HR_CM_Admin_Page
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
            add_action('admin_menu', [$this, 'register_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
            add_action('wp_ajax_hrcm_get_departures', [$this, 'ajax_get_departures']);
            add_action('wp_ajax_hrcm_overall_trip_overview', [$this, 'ajax_overall_trip_overview']);
            add_filter('set-screen-option', [$this, 'set_screen_option'], 10, 3);

            HR_CM_Automations_Admin::instance();
        }

        /**
         * Register the admin menu and submenu pages.
         */
        public function register_menu() {
            if (!current_user_can('manage_options')) {
                return;
            }

            $parent_slug = 'hr-customer-manager';

            $overview_hook = add_menu_page(
                __('HR Customer Manager', 'hr-customer-manager'),
                __('HR Customer Manager', 'hr-customer-manager'),
                'manage_options',
                $parent_slug,
                [$this, 'render_customer_overview'],
                'dashicons-admin-users',
                26
            );

            $this->screen_hooks[] = $overview_hook;
            add_action('load-' . $overview_hook, [$this, 'configure_screen_options']);

            $submenu_hook = add_submenu_page(
                $parent_slug,
                __('Customer Overview', 'hr-customer-manager'),
                __('Customer Overview', 'hr-customer-manager'),
                'manage_options',
                $parent_slug,
                [$this, 'render_customer_overview']
            );
            $this->screen_hooks[] = $submenu_hook;
            add_action('load-' . $submenu_hook, [$this, 'configure_screen_options']);

            $automation_hook = add_submenu_page(
                $parent_slug,
                __('Automation', 'hr-customer-manager'),
                __('Automation', 'hr-customer-manager'),
                'manage_options',
                'hr-customer-manager-automation',
                [$this, 'render_automation']
            );
            $this->screen_hooks[] = $automation_hook;
            add_action('load-' . $automation_hook, [$this, 'configure_automation_screen']);

            $overall_trip_hook = add_submenu_page(
                $parent_slug,
                __('Overall Trip Overview', 'hr-customer-manager'),
                __('Overall Trip Overview', 'hr-customer-manager'),
                'manage_options',
                'hrcm_overall_trip_overview',
                [$this, 'render_overall_trip_overview']
            );
            $this->screen_hooks[] = $overall_trip_hook;

            $templates_hook = add_submenu_page(
                $parent_slug,
                __('Templates', 'hr-customer-manager'),
                __('Templates', 'hr-customer-manager'),
                'manage_options',
                'hr-customer-manager-templates',
                function () {
                    $this->render_placeholder(__('Templates', 'hr-customer-manager'));
                }
            );
            $this->screen_hooks[] = $templates_hook;

            $settings_hook = add_submenu_page(
                $parent_slug,
                __('Settings', 'hr-customer-manager'),
                __('Settings', 'hr-customer-manager'),
                'manage_options',
                'hr-customer-manager-settings',
                function () {
                    $this->render_placeholder(__('Settings', 'hr-customer-manager'));
                }
            );
            $this->screen_hooks[] = $settings_hook;
        }

        /**
         * Enqueue admin assets when viewing plugin screens.
         *
         * @param string $hook_suffix Current screen hook.
         */
        public function enqueue_assets($hook_suffix) {
            if (!in_array($hook_suffix, $this->screen_hooks, true)) {
                return;
            }

            wp_enqueue_style(
                'hr-cm-admin',
                HR_CM_PLUGIN_URL . 'admin/assets/css/admin.css',
                [],
                HR_CM_VERSION
            );

            $table_screens = [
                'toplevel_page_hr-customer-manager',
                'hr-customer-manager_page_hr-customer-manager',
                'hrcm_page_customers',
            ];

            if (in_array($hook_suffix, $table_screens, true)) {
                wp_enqueue_style(
                    'hrcm-admin-table',
                    plugins_url('admin/css/hrcm-admin.css', HR_CM_PLUGIN_FILE),
                    ['hr-cm-admin'],
                    HR_CM_VERSION
                );
            }

            wp_enqueue_style('dashicons');

            wp_enqueue_script(
                'hr-cm-admin',
                HR_CM_PLUGIN_URL . 'admin/assets/js/admin.js',
                ['jquery'],
                HR_CM_VERSION,
                true
            );

            wp_localize_script(
                'hr-cm-admin',
                'hrCmAdmin',
                [
                    'toastQueued' => __('Queued (placeholder)', 'hr-customer-manager'),
                    'noticeQueued' => __('Queued (placeholder)', 'hr-customer-manager'),
                    'dismissText'  => __('Dismiss this notice.', 'hr-customer-manager'),
                    'ajaxUrl'      => admin_url('admin-ajax.php'),
                    'departuresNonce' => wp_create_nonce('hrcm_get_departures'),
                ]
            );

            if ('hr-customer-manager_page_hrcm_overall_trip_overview' === $hook_suffix) {
                wp_enqueue_script(
                    'hrcm-overall-trip',
                    HR_CM_PLUGIN_URL . 'admin/assets/js/hrcm-overall-trip.js',
                    ['jquery'],
                    HR_CM_VERSION,
                    true
                );

                wp_localize_script(
                    'hrcm-overall-trip',
                    'hrCmOverallTrip',
                    [
                        'ajaxUrl'  => admin_url('admin-ajax.php'),
                        'nonce'    => wp_create_nonce('hrcm_overall_trip_overview'),
                        'viewMap'  => [
                            'overall' => 'hrcm_overall_trip_overview',
                        ],
                        'strings'  => [
                            'loading' => __('Loading…', 'hr-customer-manager'),
                            'error'   => __('Unable to load data. Please try again.', 'hr-customer-manager'),
                            'empty'   => __('No trips found.', 'hr-customer-manager'),
                        ],
                    ]
                );
            }
        }

        /**
         * Configure screen options for the overview screen.
         */
        public function configure_screen_options() {
            if (!current_user_can('manage_options')) {
                return;
            }

            $screen = get_current_screen();
            if (!$screen) {
                return;
            }

            add_screen_option(
                'per_page',
                [
                    'label'   => __('Number of items per page', 'hr-customer-manager'),
                    'default' => HR_CM_Admin_Table::DEFAULT_PER_PAGE,
                    'option'  => HR_CM_Admin_Table::PER_PAGE_OPTION,
                ]
            );

            $columns = HR_CM_Admin_Table::get_column_config();
            $labels  = [];

            foreach ($columns as $key => $config) {
                $labels[$key] = isset($config['label']) ? $config['label'] : $key;
            }

            $this->column_labels = $labels;

            add_screen_option(
                'columns',
                [
                    'columns' => $labels,
                ]
            );

            add_filter('manage_' . $screen->id . '_columns', [$this, 'filter_manage_columns']);
        }

        /**
         * Persist the custom per-page screen option.
         *
         * @param mixed  $status Previous status.
         * @param string $option Option name.
         * @param mixed  $value  Submitted value.
         *
         * @return mixed
         */
        public function set_screen_option($status, $option, $value) {
            $automation_per_page_option = defined('HR_CM_Automations_List_Table::PER_PAGE_OPTION') ? HR_CM_Automations_List_Table::PER_PAGE_OPTION : 'hrcm_automations_per_page';

            if (in_array($option, [HR_CM_Admin_Table::PER_PAGE_OPTION, $automation_per_page_option], true)) {
                $default = HR_CM_Admin_Table::DEFAULT_PER_PAGE;
                if ($automation_per_page_option === $option) {
                    $default = 20;
                }

                $value = (int) $value;
                if ($value < 1) {
                    $value = $default;
                }

                return $value;
            }

            return $status;
        }

        /**
         * Provide column labels to WordPress for Screen Options.
         *
         * @param array $columns Existing columns.
         *
         * @return array
         */
        public function filter_manage_columns($columns) {
            if (empty($this->column_labels)) {
                return $columns;
            }

            return $this->column_labels;
        }

        /**
         * AJAX handler for retrieving departures for a trip.
         */
        public function ajax_get_departures() {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Unauthorized', 'hr-customer-manager')], 403);
            }

            check_ajax_referer('hrcm_get_departures');

            $trip_name = isset($_POST['trip']) ? sanitize_text_field(wp_unslash($_POST['trip'])) : '';
            $trip_name = trim($trip_name);

            if ('' === $trip_name) {
                wp_send_json_success([]);
            }

            $table = new HR_CM_Admin_Table();
            $dates = $table->get_departures_for_trip($trip_name);

            wp_send_json_success($dates);
        }

        /**
         * Handle AJAX requests for the Overall Trip Overview table.
         *
         * Data sources:
         * - Option `wptravelengine_indexed_trips_by_dates`
         * - Published `trip` posts (taxonomy: country, destination)
         * - `booking` posts and meta keys: `order_trips`, `wp_travel_engine_booking_status`
         */
        public function ajax_overall_trip_overview() {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Unauthorized', 'hr-customer-manager')], 403);
            }

            check_ajax_referer('hrcm_overall_trip_overview', 'nonce');

            $data = HR_CM_Overall_Trip_Overview::get_overall_table_data();

            wp_send_json_success($data);
        }

        /**
         * Render the customer overview page.
         */
        public function render_customer_overview() {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to access this page.', 'hr-customer-manager'));
            }

            $table = new HR_CM_Admin_Table();
            $data  = $table->prepare_data();

            include HR_CM_PLUGIN_DIR . 'admin/views/customer-overview.php';
        }

        /**
         * Render the Overall Trip Overview shell.
         */
        public function render_overall_trip_overview() {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to access this page.', 'hr-customer-manager'));
            }

            include HR_CM_PLUGIN_DIR . 'admin/views/overall-trip-overview.php';
        }

        /**
         * Render placeholder pages for future sections.
         *
         * @param string $title Page title.
         */
        private function render_placeholder($title) {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to access this page.', 'hr-customer-manager'));
            }

            echo '<div class="wrap hr-cm-admin">';
            echo '<h1>' . esc_html($title) . '</h1>';
            echo '<p>' . esc_html__('This section is coming soon.', 'hr-customer-manager') . '</p>';
            echo '</div>';
        }

        /**
         * Render the automations UI.
         */
        public function render_automation() {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to access this page.', 'hr-customer-manager'));
            }

            $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
            $rule_id = isset($_GET['rule']) ? (int) $_GET['rule'] : 0;

            $admin = HR_CM_Automations_Admin::instance();

            if ('new' === $action) {
                $admin->render_edit_page(0);
                return;
            }

            if ('edit' === $action && $rule_id > 0) {
                $admin->render_edit_page($rule_id);
                return;
            }

            $admin->render_list_page();
        }

        /**
         * Configure screen options for the automation list table.
         */
        public function configure_automation_screen() {
            if (!current_user_can('manage_options')) {
                return;
            }

            $screen = get_current_screen();
            if (!$screen) {
                return;
            }

            $per_page_option = defined('HR_CM_Automations_List_Table::PER_PAGE_OPTION') ? HR_CM_Automations_List_Table::PER_PAGE_OPTION : 'hrcm_automations_per_page';

            add_screen_option(
                'per_page',
                [
                    'label'   => __('Number of automations per page', 'hr-customer-manager'),
                    'default' => 20,
                    'option'  => $per_page_option,
                ]
            );
        }
    }
}
