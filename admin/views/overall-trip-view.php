<?php
/**
 * Overall trip view screen markup.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

$per_page      = isset($per_page) ? (int) $per_page : HR_CM_Overall_Trip_View::DEFAULT_PER_PAGE;
$debug_enabled = !empty($debug_enabled);
?>
<div class="wrap hr-cm-admin hrcm-overall-trip-view" data-debug="<?php echo $debug_enabled ? '1' : '0'; ?>" data-per-page="<?php echo esc_attr($per_page); ?>">
    <span class="hrcm-badge"><?php esc_html_e('Overall Trip View', 'hr-customer-manager'); ?></span>
    <h1><?php esc_html_e('Overall Trip View', 'hr-customer-manager'); ?></h1>

    <div class="hrcm-overall-trip-view__toolbar">
        <label class="screen-reader-text" for="hrcm-overall-trip-search"><?php esc_html_e('Search trips', 'hr-customer-manager'); ?></label>
        <input type="search" id="hrcm-overall-trip-search" class="hrcm-overall-trip-view__search" placeholder="<?php esc_attr_e('Search trips by code or title…', 'hr-customer-manager'); ?>" autocomplete="off" />
    </div>

    <div class="hrcm-table-wrapper hrcm-table-wrap" aria-live="polite" aria-busy="true">
        <table class="wp-list-table widefat fixed striped hrcm-table" id="hrcm-overall-trip-table">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-trip-code" data-sort-key="id" aria-sort="none">
                        <button type="button" class="hrcm-sort-button" data-sort="id">
                            <?php esc_html_e('Trip Code', 'hr-customer-manager'); ?>
                        </button>
                    </th>
                    <th scope="col" class="manage-column column-trip column-primary" data-sort-key="title" aria-sort="none">
                        <button type="button" class="hrcm-sort-button" data-sort="title">
                            <?php esc_html_e('Trip', 'hr-customer-manager'); ?>
                        </button>
                    </th>
                    <th scope="col" class="manage-column column-country" data-sort-key="country" aria-sort="none">
                        <button type="button" class="hrcm-sort-button" data-sort="country">
                            <?php esc_html_e('Country', 'hr-customer-manager'); ?>
                        </button>
                    </th>
                    <th scope="col" class="manage-column column-departures" data-sort-key="departures" aria-sort="none">
                        <button type="button" class="hrcm-sort-button" data-sort="departures">
                            <?php esc_html_e('Departures', 'hr-customer-manager'); ?>
                        </button>
                    </th>
                    <th scope="col" class="manage-column column-next-date" data-sort-key="next_date" aria-sort="none">
                        <button type="button" class="hrcm-sort-button" data-sort="next_date">
                            <?php esc_html_e('Next Departure', 'hr-customer-manager'); ?>
                        </button>
                    </th>
                    <th scope="col" class="manage-column column-days-to-next" data-sort-key="days_to_next" aria-sort="none">
                        <button type="button" class="hrcm-sort-button" data-sort="days_to_next">
                            <?php esc_html_e('Days to Next', 'hr-customer-manager'); ?>
                        </button>
                    </th>
                    <th scope="col" class="manage-column column-total-pax" data-sort-key="total_pax" aria-sort="none">
                        <button type="button" class="hrcm-sort-button" data-sort="total_pax">
                            <?php esc_html_e('Total Pax', 'hr-customer-manager'); ?>
                        </button>
                    </th>
                    <th scope="col" class="manage-column column-pax-next" data-sort-key="pax_on_next" aria-sort="none">
                        <button type="button" class="hrcm-sort-button" data-sort="pax_on_next">
                            <?php esc_html_e('Pax on Next Departure', 'hr-customer-manager'); ?>
                        </button>
                    </th>
                    <?php if ($debug_enabled) : ?>
                        <th scope="col" class="manage-column column-debug">
                            <span class="screen-reader-text"><?php esc_html_e('Debug', 'hr-customer-manager'); ?></span>
                        </th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php for ($i = 0; $i < min(5, $per_page); $i++) : ?>
                    <tr class="hrcm-skeleton-row">
                        <td><span class="hrcm-skeleton-text"></span></td>
                        <td><span class="hrcm-skeleton-text"></span></td>
                        <td><span class="hrcm-skeleton-text"></span></td>
                        <td><span class="hrcm-skeleton-text"></span></td>
                        <td><span class="hrcm-skeleton-text"></span></td>
                        <td><span class="hrcm-skeleton-text"></span></td>
                        <td><span class="hrcm-skeleton-text"></span></td>
                        <td><span class="hrcm-skeleton-text"></span></td>
                        <?php if ($debug_enabled) : ?>
                            <td><span class="hrcm-skeleton-text"></span></td>
                        <?php endif; ?>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        <div class="hrcm-overall-trip-view__empty" hidden><?php esc_html_e('No trips match your filters.', 'hr-customer-manager'); ?></div>
        <div class="hrcm-overall-trip-view__error" hidden role="alert"></div>
    </div>

    <div class="hrcm-overall-trip-view__pagination" aria-live="polite">
        <button type="button" class="button" data-page="prev" disabled>
            <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php esc_html_e('Previous page', 'hr-customer-manager'); ?></span>
        </button>
        <span class="hrcm-overall-trip-view__pagination-summary"></span>
        <button type="button" class="button" data-page="next" disabled>
            <span class="screen-reader-text"><?php esc_html_e('Next page', 'hr-customer-manager'); ?></span>
            <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
        </button>
    </div>
</div>

<div class="hrcm-overall-trip-view__modal" hidden>
    <div class="hrcm-overall-trip-view__modal-backdrop" data-action="close"></div>
    <div class="hrcm-overall-trip-view__modal-dialog" role="dialog" aria-modal="true" aria-labelledby="hrcm-overall-trip-view-modal-title">
        <div class="hrcm-overall-trip-view__modal-header">
            <h2 id="hrcm-overall-trip-view-modal-title"><?php esc_html_e('Trip data', 'hr-customer-manager'); ?></h2>
            <button type="button" class="hrcm-overall-trip-view__modal-close" data-action="close">
                <span class="screen-reader-text"><?php esc_html_e('Close', 'hr-customer-manager'); ?></span>
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <div class="hrcm-overall-trip-view__modal-body">
            <pre class="hrcm-overall-trip-view__modal-json" aria-live="polite"></pre>
        </div>
    </div>
</div>
