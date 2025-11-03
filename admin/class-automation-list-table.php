<?php
/**
 * List table for automation rules.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('HR_CM_Automations_List_Table')) {
    /**
     * Provides list table rendering for automation rules.
     */
    class HR_CM_Automations_List_Table extends WP_List_Table {
        const PER_PAGE_OPTION = 'hrcm_automations_per_page';

        /**
         * Constructor.
         */
        public function __construct() {
            parent::__construct([
                'singular' => 'hrcm-automation',
                'plural'   => 'hrcm-automations',
                'ajax'     => false,
            ]);
        }

        /**
         * Retrieve columns.
         *
         * @return array
         */
        public function get_columns() {
            return [
                'cb'         => '<input type="checkbox" />',
                'name'       => __('Name', 'hr-customer-manager'),
                'status'     => __('Status', 'hr-customer-manager'),
                'conditions' => __('Conditions', 'hr-customer-manager'),
                'action'     => __('Action', 'hr-customer-manager'),
                'last_run'   => __('Last Run', 'hr-customer-manager'),
                'next_run'   => __('Next Run', 'hr-customer-manager'),
            ];
        }

        /**
         * Bulk actions.
         *
         * @return array
         */
        protected function get_bulk_actions() {
            return [
                'enable'  => __('Enable', 'hr-customer-manager'),
                'disable' => __('Disable', 'hr-customer-manager'),
                'delete'  => __('Delete', 'hr-customer-manager'),
            ];
        }

        /**
         * Prepare items for rendering.
         */
        public function prepare_items() {
            $per_page     = $this->get_items_per_page(self::PER_PAGE_OPTION, 20);
            $current_page = $this->get_pagenum();

            $automations = HR_CM_Automations::instance();
            $this->process_bulk_action();

            $query = $automations->get_rules_query([
                'posts_per_page' => $per_page,
                'paged'          => $current_page,
            ]);

            $columns = $this->get_columns();
            $hidden  = [];
            $sortable = [];
            $this->_column_headers = [$columns, $hidden, $sortable];

            $items = [];
            if ($query->have_posts()) {
                foreach ($query->posts as $post) {
                    $rule = $automations->get_rule($post->ID);
                    $items[] = $this->format_item($post, $rule);
                }
            }

            $this->items = $items;

            $this->set_pagination_args([
                'total_items' => (int) $query->found_posts,
                'per_page'    => $per_page,
                'total_pages' => (int) ceil($query->found_posts / $per_page),
            ]);
        }

        /**
         * Format an individual item for display.
         *
         * @param WP_Post $post Post object.
         * @param array   $rule Rule array.
         *
         * @return array
         */
        private function format_item($post, $rule) {
            $url  = isset($rule['action']['url']) ? $rule['action']['url'] : '';
            $host = '';
            if ('' !== $url) {
                $parts = wp_parse_url($url);
                if (is_array($parts) && isset($parts['host'])) {
                    $host = $parts['host'];
                }
            }

            $conditions = $this->summarize_conditions($rule['conditions']);

            $last_run = isset($rule['last_run']) ? (int) $rule['last_run'] : 0;
            $last_run_display = $last_run > 0
                ? sprintf('%s<br /><small>%s</small>', esc_html(human_time_diff($last_run, time()) . ' ' . __('ago', 'hr-customer-manager')), esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_run)))
                : __('Never', 'hr-customer-manager');

            $next_run = HR_CM_Automations::instance()->get_next_run_time();
            $next_run_display = $next_run ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run) : __('Pending', 'hr-customer-manager');

            return [
                'ID'         => $post->ID,
                'name'       => $post->post_title,
                'status'     => $post->post_status,
                'conditions' => $conditions,
                'action'     => $host,
                'last_run'   => $last_run_display,
                'next_run'   => $next_run_display,
            ];
        }

        /**
         * Column checkbox.
         *
         * @param array $item Item.
         *
         * @return string
         */
        protected function column_cb($item) {
            return sprintf('<input type="checkbox" name="rule_ids[]" value="%d" />', (int) $item['ID']);
        }

        /**
         * Column name with row actions.
         *
         * @param array $item Item.
         *
         * @return string
         */
        protected function column_name($item) {
            $edit_url = add_query_arg([
                'page'   => HR_CM_Automations_Admin::SCREEN_SLUG,
                'action' => 'edit',
                'rule'   => $item['ID'],
            ], admin_url('admin.php'));

            $actions = [
                'edit' => sprintf('<a href="%s">%s</a>', esc_url($edit_url), esc_html__('Edit', 'hr-customer-manager')),
            ];

            $run_form = sprintf(
                '<form method="post" action="%1$s" style="display:inline">%2$s<input type="hidden" name="action" value="hrcm_run_automation" /><input type="hidden" name="rule_id" value="%3$d" /><button type="submit" class="button-link">%4$s</button></form>',
                esc_url(admin_url('admin-post.php')),
                wp_nonce_field(HR_CM_Automations_Admin::RUN_NONCE_ACTION, '_wpnonce', true, false),
                (int) $item['ID'],
                esc_html__('Run now', 'hr-customer-manager')
            );

            $delete_form = sprintf(
                "<form method=\"post\" action=\"%1\$s\" style=\"display:inline\" onsubmit=\"return confirm('%4\$s');\">%2\$s<input type=\"hidden\" name=\"action\" value=\"hrcm_delete_automation\" /><input type=\"hidden\" name=\"rule_id\" value=\"%3\$d\" /><button type=\"submit\" class=\"button-link delete\">%5\$s</button></form>",
                esc_url(admin_url('admin-post.php')),
                wp_nonce_field(HR_CM_Automations_Admin::DELETE_NONCE_ACTION, '_wpnonce', true, false),
                (int) $item['ID'],
                esc_js(__('Delete this automation?', 'hr-customer-manager')),
                esc_html__('Delete', 'hr-customer-manager')
            );

            $actions['run']    = $run_form;
            $actions['delete'] = $delete_form;

            $status_label = 'publish' === $item['status'] ? __('Enabled', 'hr-customer-manager') : __('Disabled', 'hr-customer-manager');

            return sprintf('<strong><a href="%s">%s</a></strong><br /><span class="hrcm-automation-status">%s</span>%s', esc_url($edit_url), esc_html($item['name']), esc_html($status_label), $this->row_actions($actions));
        }

        /**
         * Default column rendering.
         *
         * @param array  $item        Item.
         * @param string $column_name Column name.
         *
         * @return string
         */
        protected function column_default($item, $column_name) {
            if (isset($item[$column_name])) {
                if (in_array($column_name, ['conditions', 'action'], true)) {
                    return esc_html($item[$column_name]);
                }

                return $item[$column_name];
            }

            return '';
        }

        /**
         * Process bulk actions.
         */
        public function process_bulk_action() {
            if (empty($_POST['rule_ids']) || !is_array($_POST['rule_ids'])) {
                return;
            }

            if (!current_user_can('manage_options')) {
                return;
            }

            $nonce_action = 'bulk-' . $this->_args['plural'];
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), $nonce_action)) {
                return;
            }

            $ids = array_map('intval', (array) $_POST['rule_ids']);

            switch ($this->current_action()) {
                case 'enable':
                    foreach ($ids as $id) {
                        wp_update_post([
                            'ID'          => $id,
                            'post_status' => 'publish',
                        ]);
                    }
                    break;
                case 'disable':
                    foreach ($ids as $id) {
                        wp_update_post([
                            'ID'          => $id,
                            'post_status' => 'draft',
                        ]);
                    }
                    break;
                case 'delete':
                    foreach ($ids as $id) {
                        wp_delete_post($id, true);
                    }
                    break;
            }
        }

        /**
         * Summarize conditions.
         *
         * @param array $conditions Conditions.
         *
         * @return string
         */
        private function summarize_conditions($conditions) {
            if (empty($conditions)) {
                return __('Always', 'hr-customer-manager');
            }

            $parts = [];
            foreach ($conditions as $condition) {
                if (empty($condition['field']) || empty($condition['op'])) {
                    continue;
                }

                $field = $this->format_field_label($condition['field']);
                $op    = $condition['op'];
                $value = $condition['value'];

                if (is_array($value)) {
                    if (isset($value['min']) || isset($value['max'])) {
                        $min = isset($value['min']) ? $value['min'] : '*';
                        $max = isset($value['max']) ? $value['max'] : '*';
                        $value = sprintf('%s – %s', $min, $max);
                    } else {
                        $value = implode(', ', $value);
                    }
                }

                $parts[] = sprintf('%s %s %s', $field, $op, $value);

                if (!empty($condition['join'])) {
                    $parts[] = strtoupper($condition['join']);
                }
            }

            if (!empty($parts)) {
                $last = end($parts);
                if (in_array($last, ['AND', 'OR'], true)) {
                    array_pop($parts);
                }
            }

            return implode(' ', $parts);
        }

        /**
         * Map field key to label.
         *
         * @param string $field Field key.
         *
         * @return string
         */
        private function format_field_label($field) {
            $map = [
                'days_to_trip'             => __('Days to Trip', 'hr-customer-manager'),
                'payment_status'           => __('Payment Status', 'hr-customer-manager'),
                'info_received'            => __('Info Received', 'hr-customer-manager'),
                'trip_name'                => __('Trip Name', 'hr-customer-manager'),
                'last_email_sent_age_days' => __('Last Email Age', 'hr-customer-manager'),
            ];

            return isset($map[$field]) ? $map[$field] : $field;
        }
    }
}
