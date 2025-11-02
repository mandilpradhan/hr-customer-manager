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

$bookings = isset($data['bookings']) ? $data['bookings'] : [];
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
        <table class="wp-list-table widefat fixed striped table-view-list hr-cm-table table-fullwidth">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Traveler(s)', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Booking ID', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Trip Name', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Departure Date', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Days to Trip', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Payment Status', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Manifest Received', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Current Phase', 'hr-customer-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Last Email Sent', 'hr-customer-manager'); ?></th>
                    <th scope="col" class="hr-cm-column-resend-email"><?php esc_html_e('Resend Email', 'hr-customer-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bookings)) : ?>
                    <tr>
                        <td colspan="10" class="hr-cm-empty">
                            <?php esc_html_e('No bookings found.', 'hr-customer-manager'); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($bookings as $booking) : ?>
                        <tr>
                            <td>
                                <div class="hr-cm-traveler">
                                    <strong><?php echo esc_html($booking['traveler_name']); ?></strong>
                                    <span class="hr-cm-email"><?php echo esc_html($booking['traveler_email']); ?></span>
                                </div>
                                <?php if (!empty($booking['lead_differs'])) : ?>
                                    <div class="hr-cm-lead">
                                        <span class="hr-cm-label"><?php esc_html_e('Lead:', 'hr-customer-manager'); ?></span>
                                        <span class="hr-cm-name"><?php echo esc_html($booking['lead_name']); ?></span>
                                        <span class="hr-cm-email"><?php echo esc_html($booking['lead_email']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="hr-cm-booking-id">#<?php echo esc_html($booking['booking_id']); ?></td>
                            <td><?php echo esc_html($booking['trip_name']); ?></td>
                            <td><?php echo esc_html($booking['departure_date']); ?></td>
                            <td>
                                <?php
                                if ($booking['days_to_trip'] === null) {
                                    echo '&mdash;';
                                } else {
                                    echo esc_html(number_format_i18n($booking['days_to_trip']));
                                }
                                ?>
                            </td>
                            <td>
                                <?php if (!empty($booking['payment_badge']['show_badge'])) : ?>
                                    <span class="hr-cm-badge badge <?php echo esc_attr($booking['payment_badge']['class']); ?>">
                                        <?php echo esc_html($booking['payment_badge']['label']); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="hr-cm-status-text"><?php echo esc_html($booking['payment_badge']['label']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($booking['manifest_status']['show_badge'])) : ?>
                                    <span class="hr-cm-badge badge <?php echo esc_attr($booking['manifest_status']['class']); ?>">
                                        <?php echo esc_html($booking['manifest_status']['label']); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="hr-cm-status-text"><?php echo esc_html($booking['manifest_status']['label']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($booking['phase_label']); ?></td>
                            <td class="hr-cm-muted">&mdash;</td>
                            <td class="hr-cm-column-resend-email">
                                <form class="hr-cm-email-form" data-booking-id="<?php echo esc_attr($booking['booking_id']); ?>">
                                    <label for="hr-cm-email-<?php echo esc_attr($booking['booking_id']); ?>" class="screen-reader-text"><?php esc_html_e('Choose email type', 'hr-customer-manager'); ?></label>
                                    <select id="hr-cm-email-<?php echo esc_attr($booking['booking_id']); ?>" class="hr-cm-email-select">
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
                                    <button type="button" class="button button-secondary hr-cm-email-send">
                                        <?php esc_html_e('Resend', 'hr-customer-manager'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="hr-cm-toast" role="status" aria-live="polite" aria-hidden="true"></div>
</div>
