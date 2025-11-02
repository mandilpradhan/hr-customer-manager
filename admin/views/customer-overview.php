<?php
/**
 * Customer overview screen markup.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

$filters = isset($data['filters']) ? $data['filters'] : [];
$filters = wp_parse_args(
    $filters,
    [
        'trip'        => '',
        'departure'   => '',
        'status'      => '',
        'days_range'  => '',
        'search'      => '',
        'nonce_valid' => false,
    ]
);

$options        = isset($data['filter_options']) ? $data['filter_options'] : [];
$nonce_action   = isset($data['nonce_action']) ? $data['nonce_action'] : 'hr_cm_filters';
$selected_trip  = isset($filters['trip']) ? $filters['trip'] : '';
$trip_options   = isset($options['trips']) ? $options['trips'] : [];
$status_options = isset($options['statuses']) ? $options['statuses'] : [];
$days_options   = isset($options['days_ranges']) ? $options['days_ranges'] : [];
$rows           = isset($data['rows']) ? $data['rows'] : [];
$sort           = isset($data['sort']) ? $data['sort'] : 'departure';
$dir            = isset($data['dir']) ? strtolower($data['dir']) : 'asc';
$dir            = in_array($dir, ['asc', 'desc'], true) ? $dir : 'asc';
$pagination     = isset($data['pagination']) && is_array($data['pagination']) ? $data['pagination'] : [];
$pagination     = wp_parse_args(
    $pagination,
    [
        'total_items'  => count($rows),
        'total_pages'  => 1,
        'current_page' => 1,
        'per_page'     => 25,
    ]
);
$trip_departures  = isset($data['trip_departures']) ? $data['trip_departures'] : [];
$query_args       = isset($data['query_args']) ? $data['query_args'] : [];
$columns_config   = isset($data['columns']) ? (array) $data['columns'] : [];

$dates_for_trip = [];
if ('' !== $selected_trip && isset($trip_departures[$selected_trip])) {
    $dates_for_trip = $trip_departures[$selected_trip];
}

$total_items  = (int) $pagination['total_items'];
$current_page = max(1, (int) $pagination['current_page']);
$per_page     = max(1, (int) $pagination['per_page']);
$page_start   = $total_items > 0 ? (($current_page - 1) * $per_page) + 1 : 0;
$page_end     = $total_items > 0 ? min($page_start + count($rows) - 1, $total_items) : 0;
$base_url     = add_query_arg($query_args, menu_page_url('hr-customer-manager', false));

$pagination_links = paginate_links(
    [
        'base'      => $base_url . '%_%',
        'format'    => '&paged=%#%',
        'current'   => $current_page,
        'total'     => max(1, (int) $pagination['total_pages']),
        'prev_text' => '&laquo;',
        'next_text' => '&raquo;',
    ]
);

$screen         = function_exists('get_current_screen') ? get_current_screen() : null;
$hidden_columns = ($screen && function_exists('get_hidden_columns')) ? get_hidden_columns($screen) : [];
if (!is_array($hidden_columns)) {
    $hidden_columns = [];
}

$all_columns     = array_keys($columns_config);
$visible_columns = array_diff($all_columns, $hidden_columns);
$visible_colspan = count($visible_columns);
if ($visible_colspan < 1) {
    $visible_colspan = count($all_columns);
    if ($visible_colspan < 1) {
        $visible_colspan = 1;
    }
}

$column_state = static function ($column_key) use ($columns_config, $sort, $dir) {
    $config    = isset($columns_config[$column_key]) ? $columns_config[$column_key] : [];
    $sort_key  = isset($config['sort']) ? $config['sort'] : $column_key;
    $is_active = ($sort_key === $sort);
    $aria      = 'none';
    $direction = 'asc';

    if ($is_active) {
        $aria      = ('asc' === $dir) ? 'ascending' : 'descending';
        $direction = $dir;
    }

    $next_direction = 'asc';
    if ($is_active && 'asc' === $dir) {
        $next_direction = 'desc';
    } elseif ($is_active && 'desc' === $dir) {
        $next_direction = 'asc';
    }

    return [
        'aria'           => $aria,
        'sort'           => $sort_key,
        'is_active'      => $is_active,
        'direction'      => $direction,
        'next_direction' => $next_direction,
    ];
};
?>
<div class="wrap hr-cm-admin">
    <h1><?php esc_html_e('Customer Overview', 'hr-customer-manager'); ?></h1>

    <form method="get" class="hr-cm-filters">
        <input type="hidden" name="page" value="hr-customer-manager" />
        <input type="hidden" name="sort" value="<?php echo esc_attr($sort); ?>" />
        <input type="hidden" name="dir" value="<?php echo esc_attr($dir); ?>" />
        <input type="hidden" name="paged" value="1" />
        <?php wp_nonce_field($nonce_action, 'hr_cm_nonce'); ?>
        <div class="hr-cm-filters__row">
            <div class="hr-cm-filter">
                <label for="hr-cm-trip"><?php esc_html_e('Trip', 'hr-customer-manager'); ?></label>
                <select id="hr-cm-trip" name="hr_cm_trip">
                    <option value=""><?php esc_html_e('All Trips', 'hr-customer-manager'); ?></option>
                    <?php foreach ($trip_options as $trip_name => $trip) : ?>
                        <option value="<?php echo esc_attr($trip_name); ?>" <?php selected($selected_trip, $trip_name); ?>><?php echo esc_html($trip_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="hr-cm-filter">
                <label for="hr-cm-departure"><?php esc_html_e('Departure', 'hr-customer-manager'); ?></label>
                <select id="hr-cm-departure" name="hr_cm_departure" data-selected="<?php echo esc_attr($filters['departure']); ?>" data-all-label="<?php esc_attr_e('All Departures', 'hr-customer-manager'); ?>">
                    <option value=""><?php esc_html_e('All Departures', 'hr-customer-manager'); ?></option>
                    <?php foreach ($dates_for_trip as $date) : ?>
                        <option value="<?php echo esc_attr($date); ?>" <?php selected($filters['departure'], $date); ?>><?php echo esc_html($date); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="hr-cm-filter">
                <label for="hr-cm-status"><?php esc_html_e('Payment Status', 'hr-customer-manager'); ?></label>
                <select id="hr-cm-status" name="hr_cm_status">
                    <option value=""><?php esc_html_e('All Statuses', 'hr-customer-manager'); ?></option>
                    <?php foreach ($status_options as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['status'], $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="hr-cm-filter">
                <label for="hr-cm-days"><?php esc_html_e('Days Range', 'hr-customer-manager'); ?></label>
                <select id="hr-cm-days" name="hr_cm_days">
                    <option value=""><?php esc_html_e('All Ranges', 'hr-customer-manager'); ?></option>
                    <?php foreach ($days_options as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['days_range'], $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="hr-cm-filter hr-cm-filter--search">
                <label for="hr-cm-search" class="screen-reader-text"><?php esc_html_e('Search', 'hr-customer-manager'); ?></label>
                <input type="search" id="hr-cm-search" name="hr_cm_search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('Search travelers, emails, trips…', 'hr-customer-manager'); ?>" />
            </div>
            <div class="hr-cm-filter hr-cm-filter--actions">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Apply Filters', 'hr-customer-manager'); ?>
                </button>
                <button type="button" class="button hr-cm-sync" disabled="disabled" aria-disabled="true">
                    <?php esc_html_e('Sync Now', 'hr-customer-manager'); ?>
                </button>
            </div>
        </div>
    </form>

    <div class="hr-cm-table-wrapper hrcm-table-wrap">
        <table class="wp-list-table widefat fixed striped hrcm-table">
            <thead>
                <tr>
                    <?php foreach ($columns_config as $column_key => $column_settings) : ?>
                        <?php if (in_array($column_key, $hidden_columns, true)) { continue; } ?>
                        <?php
                        $state    = $column_state($column_key);
                        $label    = isset($column_settings['label']) ? $column_settings['label'] : $column_key;
                        $th_class = ['manage-column', 'column-' . $column_key, 'hrcm-col', 'hrcm-col--' . $column_key];
                        if ($state['is_active']) {
                            $th_class[] = 'sorted';
                            $th_class[] = $state['direction'];
                        } else {
                            $th_class[] = 'sortable';
                            $th_class[] = $state['direction'];
                        }
                        if ('traveler' === $column_key) {
                            $th_class[] = 'column-primary';
                        }
                        if ('resend_email' === $column_key) {
                            $th_class[] = 'resend-col';
                        }

                        $sort_url = add_query_arg(
                            [
                                'sort'  => $state['sort'],
                                'dir'   => $state['next_direction'],
                                'paged' => 1,
                            ],
                            $base_url
                        );

                        $direction_text = ('asc' === $state['next_direction']) ? __('ascending', 'hr-customer-manager') : __('descending', 'hr-customer-manager');
                        $aria_label     = sprintf(
                            /* translators: 1: Column label, 2: next sort direction. */
                            __('Sort by %1$s in %2$s order', 'hr-customer-manager'),
                            $label,
                            $direction_text
                        );
                        ?>
                        <th scope="col" class="<?php echo esc_attr(implode(' ', $th_class)); ?>" data-sort="<?php echo esc_attr($state['sort']); ?>" data-direction="<?php echo esc_attr($state['direction']); ?>" data-next-direction="<?php echo esc_attr($state['next_direction']); ?>" aria-sort="<?php echo esc_attr($state['aria']); ?>">
                            <a href="<?php echo esc_url($sort_url); ?>" aria-label="<?php echo esc_attr($aria_label); ?>">
                                <span><?php echo esc_html($label); ?></span>
                                <span class="sorting-indicator" aria-hidden="true"></span>
                            </a>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)) : ?>
                    <tr>
                        <td colspan="<?php echo (int) $visible_colspan; ?>" class="column-empty hrcm-col hrcm-col--empty hr-cm-empty">
                            <?php esc_html_e('No bookings found.', 'hr-customer-manager'); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <?php
                        $disabled       = !empty($row['resend_disabled']);
                        $disabled_attr  = $disabled ? 'disabled="disabled" aria-disabled="true"' : '';
                        $edit_link      = get_edit_post_link($row['booking_id']);
                        $has_departure  = '' !== $row['departure_date'];
                        $has_trav_email = '' !== $row['traveler_email'];
                        $payment        = isset($row['payment']) ? $row['payment'] : [];
                        $info           = isset($row['info']) ? $row['info'] : [];
                        $payment_class  = isset($payment['is_badge']) && $payment['is_badge'] ? (isset($payment['badge_class']) ? $payment['badge_class'] : '') : (isset($payment['class']) ? $payment['class'] : '');
                        $payment_class  = trim((string) $payment_class);
                        $payment_text   = isset($payment['text']) ? $payment['text'] : '';
                        $info_class     = isset($info['is_badge']) && $info['is_badge'] ? (isset($info['badge_class']) ? $info['badge_class'] : '') : (isset($info['class']) ? $info['class'] : '');
                        $info_class     = trim((string) $info_class);
                        $info_text      = isset($info['text']) ? $info['text'] : '';
                        ?>
                        <tr>
                            <?php foreach ($visible_columns as $column_key) : ?>
                                <?php
                                $cell_classes = ['column-' . $column_key, 'hrcm-col', 'hrcm-col--' . $column_key];
                                if ('booking_id' === $column_key) {
                                    $cell_classes[] = 'hrcm-booking-id';
                                }
                                if ('traveler' === $column_key) {
                                    $cell_classes[] = 'column-primary';
                                }
                                if ('resend_email' === $column_key) {
                                    $cell_classes[] = 'resend-col';
                                }
                                ?>
                                <td class="<?php echo esc_attr(implode(' ', $cell_classes)); ?>">
                                    <?php
                                    switch ($column_key) {
                                        case 'traveler':
                                            echo '<strong>' . esc_html($row['traveler_name']) . '</strong>';
                                            if ($has_trav_email) {
                                                echo '<div class="trav-email">' . esc_html($row['traveler_email']) . '</div>';
                                            }
                                            if (!empty($row['show_lead'])) {
                                                echo '<div class="trav-sub">' . esc_html__('Lead:', 'hr-customer-manager') . ' ' . esc_html($row['lead_name']) . '</div>';
                                            }
                                            break;
                                        case 'booking_id':
                                            if ($edit_link) {
                                                echo '<a href="' . esc_url($edit_link) . '" target="_blank" rel="noopener noreferrer">#' . esc_html($row['booking_id']) . '</a>';
                                            } else {
                                                echo '#' . esc_html($row['booking_id']);
                                            }
                                            break;
                                        case 'trip':
                                            echo esc_html($row['trip_name']);
                                            break;
                                        case 'departure':
                                            echo $has_departure ? esc_html($row['departure_date']) : '&mdash;';
                                            break;
                                        case 'days_to_trip':
                                            if ($row['days_to_trip'] === null) {
                                                echo '&mdash;';
                                            } else {
                                                echo esc_html(number_format_i18n($row['days_to_trip']));
                                            }
                                            break;
                                        case 'payment_status':
                                            if ('' !== $payment_class) {
                                                echo '<span class="' . esc_attr($payment_class) . '">' . esc_html($payment_text) . '</span>';
                                            } else {
                                                echo '<span>' . esc_html($payment_text) . '</span>';
                                            }
                                            break;
                                        case 'info_received':
                                            if ('' !== $info_class) {
                                                echo '<span class="' . esc_attr($info_class) . '">' . esc_html($info_text) . '</span>';
                                            } else {
                                                echo '<span>' . esc_html($info_text) . '</span>';
                                            }
                                            break;
                                        case 'current_phase':
                                            echo esc_html($row['phase_label']);
                                            break;
                                        case 'last_email_sent':
                                            echo '<span class="hr-cm-muted">' . esc_html($row['last_email_display']) . '</span>';
                                            break;
                                        case 'resend_email':
                                            ?>
                                            <div class="hrcm-resend">
                                                <label for="hrcm-email-<?php echo esc_attr($row['booking_id']); ?>" class="screen-reader-text"><?php esc_html_e('Choose email type', 'hr-customer-manager'); ?></label>
                                                <select id="hrcm-email-<?php echo esc_attr($row['booking_id']); ?>" class="hrcm-resend-select" <?php echo $disabled_attr; ?>>
                                                    <option value="" selected="selected"><?php esc_html_e('Select Email Template', 'hr-customer-manager'); ?></option>
                                                    <option value="travel-insurance"><?php esc_html_e('Travel Insurance Reminder', 'hr-customer-manager'); ?></option>
                                                    <option value="60-day-payment"><?php esc_html_e('60 Day Payment Reminder', 'hr-customer-manager'); ?></option>
                                                    <option value="14-day-payment"><?php esc_html_e('14 Day Payment Reminder', 'hr-customer-manager'); ?></option>
                                                    <option value="7-day-payment"><?php esc_html_e('7 Day Payment Reminder', 'hr-customer-manager'); ?></option>
                                                    <option value="3-day-payment"><?php esc_html_e('3 Day Payment Reminder', 'hr-customer-manager'); ?></option>
                                                    <option value="trip-day"><?php esc_html_e('0 Day Payment Reminder', 'hr-customer-manager'); ?></option>
                                                    <option value="final-payment"><?php esc_html_e('Final Payment Reminder', 'hr-customer-manager'); ?></option>
                                                    <option value="rider-information"><?php esc_html_e('Rider Information Form', 'hr-customer-manager'); ?></option>
                                                    <option value="packing-list"><?php esc_html_e('Destination Pack + Packing List', 'hr-customer-manager'); ?></option>
                                                    <option value="arrival-info"><?php esc_html_e('Final Arrival Info', 'hr-customer-manager'); ?></option>
                                                    <option value="trip-kickoff"><?php esc_html_e('Trip Kicks Off', 'hr-customer-manager'); ?></option>
                                                    <option value="post-trip"><?php esc_html_e('Post-Trip Debrief', 'hr-customer-manager'); ?></option>
                                                </select>
                                                <button type="button" class="button button-secondary hrcm-resend-btn" <?php echo $disabled_attr; ?>>
                                                    <?php esc_html_e('Resend', 'hr-customer-manager'); ?>
                                                </button>
                                            </div>
                                            <?php
                                            break;
                                        default:
                                            echo '&mdash;';
                                            break;
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="tablenav bottom">
        <div class="alignleft actions"></div>
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php
                if ($total_items > 0) {
                    printf(
                        /* translators: 1: start number, 2: end number, 3: total items */
                        esc_html__('Displaying %1$s–%2$s of %3$s travelers', 'hr-customer-manager'),
                        esc_html(number_format_i18n($page_start)),
                        esc_html(number_format_i18n($page_end)),
                        esc_html(number_format_i18n($total_items))
                    );
                } else {
                    esc_html_e('No travelers to display', 'hr-customer-manager');
                }
                ?>
            </span>
            <?php if (!empty($pagination_links)) : ?>
                <span class="pagination-links"><?php echo wp_kses_post($pagination_links); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="hr-cm-toast" role="status" aria-live="polite" aria-hidden="true"></div>

    <script>
        window.hrcmTripDeps = <?php echo wp_json_encode($trip_departures); ?>;
        window.hrcmState = <?php echo wp_json_encode(
            [
                'sort'    => $sort,
                'dir'     => $dir,
                'perPage' => $per_page,
                'paged'   => $current_page,
            ]
        ); ?>;
    </script>
</div>
