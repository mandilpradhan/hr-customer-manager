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
$per_page_options = isset($data['per_page_options']) ? (array) $data['per_page_options'] : [25, 50, 100, 250];
$trip_departures  = isset($data['trip_departures']) ? $data['trip_departures'] : [];
$query_args       = isset($data['query_args']) ? $data['query_args'] : [];

$dates_for_trip = [];
if ('' !== $selected_trip && isset($trip_options[$selected_trip])) {
    $dates_for_trip = $trip_options[$selected_trip]['dates'];
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

$column_state = static function ($key) use ($sort, $dir) {
    $is_active = ($key === $sort);
    $indicator = $is_active ? ('asc' === $dir ? '▲' : '▼') : '↕';
    $aria      = $is_active ? ('asc' === $dir ? 'ascending' : 'descending') : 'none';

    return [
        'indicator' => $indicator,
        'aria'      => $aria,
    ];
};
?>
<div class="wrap hr-cm-admin">
    <h1><?php esc_html_e('Customer Overview', 'hr-customer-manager'); ?></h1>

    <form method="get" class="hr-cm-filters">
        <input type="hidden" name="page" value="hr-customer-manager" />
        <input type="hidden" name="sort" value="<?php echo esc_attr($sort); ?>" />
        <input type="hidden" name="dir" value="<?php echo esc_attr($dir); ?>" />
        <input type="hidden" name="per_page" value="<?php echo esc_attr($per_page); ?>" />
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

    <div class="tablenav top">
        <div class="alignleft actions">
            <label for="hrcm-per-page" class="screen-reader-text"><?php esc_html_e('Rows per page', 'hr-customer-manager'); ?></label>
            <select id="hrcm-per-page">
                <?php foreach ($per_page_options as $option) : ?>
                    <option value="<?php echo esc_attr((int) $option); ?>" <?php selected((int) $option, $per_page); ?>><?php echo esc_html(number_format_i18n((int) $option)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
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

    <div class="hr-cm-table-wrapper">
        <table class="wp-list-table widefat fixed striped table-view-list hrcm-table">
            <thead>
                <tr>
                    <?php $state = $column_state('traveler'); ?>
                    <th scope="col" class="hrcm-th" data-sort="traveler" aria-sort="<?php echo esc_attr($state['aria']); ?>"><?php esc_html_e('Traveler(s)', 'hr-customer-manager'); ?><span class="sort-ind"><?php echo esc_html($state['indicator']); ?></span></th>
                    <?php $state = $column_state('booking'); ?>
                    <th scope="col" class="hrcm-th" data-sort="booking" aria-sort="<?php echo esc_attr($state['aria']); ?>"><?php esc_html_e('Booking ID', 'hr-customer-manager'); ?><span class="sort-ind"><?php echo esc_html($state['indicator']); ?></span></th>
                    <?php $state = $column_state('trip'); ?>
                    <th scope="col" class="hrcm-th" data-sort="trip" aria-sort="<?php echo esc_attr($state['aria']); ?>"><?php esc_html_e('Trip', 'hr-customer-manager'); ?><span class="sort-ind"><?php echo esc_html($state['indicator']); ?></span></th>
                    <?php $state = $column_state('departure'); ?>
                    <th scope="col" class="hrcm-th" data-sort="departure" aria-sort="<?php echo esc_attr($state['aria']); ?>"><?php esc_html_e('Departure', 'hr-customer-manager'); ?><span class="sort-ind"><?php echo esc_html($state['indicator']); ?></span></th>
                    <?php $state = $column_state('days'); ?>
                    <th scope="col" class="hrcm-th" data-sort="days" aria-sort="<?php echo esc_attr($state['aria']); ?>"><?php esc_html_e('Days to Trip', 'hr-customer-manager'); ?><span class="sort-ind"><?php echo esc_html($state['indicator']); ?></span></th>
                    <?php $state = $column_state('payment'); ?>
                    <th scope="col" class="hrcm-th" data-sort="payment" aria-sort="<?php echo esc_attr($state['aria']); ?>"><?php esc_html_e('Payment Status', 'hr-customer-manager'); ?><span class="sort-ind"><?php echo esc_html($state['indicator']); ?></span></th>
                    <?php $state = $column_state('info'); ?>
                    <th scope="col" class="hrcm-th" data-sort="info" aria-sort="<?php echo esc_attr($state['aria']); ?>"><?php esc_html_e('Info received', 'hr-customer-manager'); ?><span class="sort-ind"><?php echo esc_html($state['indicator']); ?></span></th>
                    <?php $state = $column_state('phase'); ?>
                    <th scope="col" class="hrcm-th" data-sort="phase" aria-sort="<?php echo esc_attr($state['aria']); ?>"><?php esc_html_e('Current Phase', 'hr-customer-manager'); ?><span class="sort-ind"><?php echo esc_html($state['indicator']); ?></span></th>
                    <?php $state = $column_state('last_email'); ?>
                    <th scope="col" class="hrcm-th" data-sort="last_email" aria-sort="<?php echo esc_attr($state['aria']); ?>"><?php esc_html_e('Last Email Sent', 'hr-customer-manager'); ?><span class="sort-ind"><?php echo esc_html($state['indicator']); ?></span></th>
                    <?php $state = $column_state('resend'); ?>
                    <th scope="col" class="resend-col hrcm-th" data-sort="resend" aria-sort="<?php echo esc_attr($state['aria']); ?>"><?php esc_html_e('Resend Email', 'hr-customer-manager'); ?><span class="sort-ind"><?php echo esc_html($state['indicator']); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)) : ?>
                    <tr>
                        <td colspan="10" class="hr-cm-empty">
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
                        $has_lead_email = '' !== $row['lead_email'];
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($row['traveler_name']); ?></strong>
                                <?php if ($has_trav_email) : ?>
                                    <div class="trav-email"><?php echo esc_html($row['traveler_email']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($row['show_lead'])) : ?>
                                    <div class="trav-sub">
                                        <?php esc_html_e('Lead:', 'hr-customer-manager'); ?>
                                        <?php echo ' ' . esc_html($row['lead_name']); ?>
                                        <?php if ($has_lead_email) : ?>
                                            <?php echo ' (' . esc_html($row['lead_email']) . ')'; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="hrcm-booking-id">
                                <?php if ($edit_link) : ?>
                                    <a href="<?php echo esc_url($edit_link); ?>" target="_blank" rel="noopener noreferrer">#<?php echo esc_html($row['booking_id']); ?></a>
                                <?php else : ?>
                                    #<?php echo esc_html($row['booking_id']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($row['trip_name']); ?></td>
                            <td>
                                <?php
                                if (!$has_departure) {
                                    echo '&mdash;';
                                } else {
                                    echo esc_html($row['departure_date']);
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                if ($row['days_to_trip'] === null) {
                                    echo '&mdash;';
                                } else {
                                    echo esc_html(number_format_i18n($row['days_to_trip']));
                                }
                                ?>
                            </td>
                            <td><span class="<?php echo esc_attr($row['payment']['class']); ?>"><?php echo esc_html($row['payment']['text']); ?></span></td>
                            <td><span class="<?php echo esc_attr($row['info']['class']); ?>"><?php echo esc_html($row['info']['text']); ?></span></td>
                            <td><?php echo esc_html($row['phase_label']); ?></td>
                            <td class="hr-cm-muted"><?php echo esc_html($row['last_email_display']); ?></td>
                            <td class="resend-col">
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
                            </td>
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
