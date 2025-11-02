<?php
/**
 * Admin page router for HR Customer Manager.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

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

            $submenu_hook = add_submenu_page(
                $parent_slug,
                __('Customer Overview', 'hr-customer-manager'),
                __('Customer Overview', 'hr-customer-manager'),
                'manage_options',
                $parent_slug,
                [$this, 'render_customer_overview']
            );
            $this->screen_hooks[] = $submenu_hook;

            $automation_hook = add_submenu_page(
                $parent_slug,
                __('Automation', 'hr-customer-manager'),
                __('Automation', 'hr-customer-manager'),
                'manage_options',
                'hr-customer-manager-automation',
                function () {
                    $this->render_placeholder(__('Automation', 'hr-customer-manager'));
                }
            );
            $this->screen_hooks[] = $automation_hook;

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
                ]
            );
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
    }
}
