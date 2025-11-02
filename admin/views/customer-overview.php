<?php
/**
 * Customer overview screen markup.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

$filters       = isset($data['filters']) ? $data['filters'] : [];
$filters       = wp_parse_args(
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
$options       = isset($data['filter_options']) ? $data['filter_options'] : [];
$nonce_action  = isset($data['nonce_action']) ? $data['nonce_action'] : 'hr_cm_filters';
$selected_trip = isset($filters['trip']) ? $filters['trip'] : '';
$trip_options  = isset($options['trips']) ? $options['trips'] : [];
$status_options = isset($options['statuses']) ? $options['statuses'] : [];
$days_options    = isset($options['days_ranges']) ? $options['days_ranges'] : [];
$dates_for_trip = [];

if ('' !== $selected_trip && isset($trip_options[$selected_trip])) {
    $dates_for_trip = $trip_options[$selected_trip]['dates'];
}

$rows = isset($data['rows']) ? $data['rows'] : [];
?>
<div class="wrap hr-cm-admin">
    <h1><?php esc_html_e('Customer Overview', 'hr-customer-manager'); ?></h1>

    <form method="get" class="hr-cm-filters">
        <input type="hidden" name="page" value="hr-customer-manager" />
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
                <select id="hr-cm-departure" name="hr_cm_departure">
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

    <div class="hr-cm-table-wrapper">
        <table class="wp-list-table widefat fixed striped table-view-list hrcm-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Traveler(s)', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Booking ID', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Trip Name', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Departure Date', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Days to Trip', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Payment Status', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Info received', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Current Phase', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Last Email Sent', 'hr-customer-manager'); ?></th>
                    <th scope="col" class="resend-col"><?php esc_html_e('Resend Email', 'hr-customer-manager'); ?></th>
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
                        $disabled        = !empty($row['resend_disabled']);
                        $disabled_attr   = $disabled ? 'disabled="disabled" aria-disabled="true"' : '';
                        $edit_link       = get_edit_post_link($row['booking_id']);
                        $has_departure   = '' !== $row['departure_date'];
                        $has_trav_email  = '' !== $row['traveler_email'];
                        $has_lead_email  = '' !== $row['lead_email'];
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
                                    <a href="<?php echo esc_url($edit_link); ?>">#<?php echo esc_html($row['booking_id']); ?></a>
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
                            <td class="hr-cm-muted">&mdash;</td>
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
    <div class="hr-cm-toast" role="status" aria-live="polite" aria-hidden="true"></div>
</div>
