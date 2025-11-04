<?php
/**
 * Debug Viewer admin page template.
 *
 * @package HR_Customer_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

/** @var array $bookings */
/** @var array $sections */
?>
<div class="wrap hr-cm-admin hr-cm-debug-viewer" id="hrcm-debug-viewer">
    <h1><?php esc_html_e('Debug Viewer', 'hr-customer-manager'); ?></h1>

    <div class="hrcm-debug-controls">
        <div class="hrcm-debug-control hrcm-debug-control--booking">
            <label for="hrcm-debug-booking-search" class="hrcm-debug-label"><?php esc_html_e('Booking selector', 'hr-customer-manager'); ?></label>
            <div class="hrcm-debug-booking-select">
                <input type="search" id="hrcm-debug-booking-search" class="hrcm-debug-search" placeholder="<?php echo esc_attr__('Search bookings…', 'hr-customer-manager'); ?>" aria-label="<?php esc_attr_e('Search bookings', 'hr-customer-manager'); ?>" />
                <select id="hrcm-debug-booking-select" class="hrcm-debug-select" aria-label="<?php esc_attr_e('Select booking', 'hr-customer-manager'); ?>">
                    <?php foreach ($bookings as $index => $booking) : ?>
                        <option value="<?php echo esc_attr($booking['id']); ?>" data-label="<?php echo esc_attr($booking['label']); ?>" <?php selected(0 === $index); ?>>
                            <?php echo esc_html($booking['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="hrcm-debug-control hrcm-debug-control--sections">
            <span class="hrcm-debug-label"><?php esc_html_e('Sections', 'hr-customer-manager'); ?></span>
            <div class="hrcm-debug-section-toggle-group" role="group" aria-label="<?php esc_attr_e('Toggle sections', 'hr-customer-manager'); ?>">
                <?php foreach ($sections as $section_key => $section_label) : ?>
                    <button type="button" class="hrcm-debug-section-toggle is-active" data-section="<?php echo esc_attr($section_key); ?>">
                        <?php echo esc_html($section_label); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="hrcm-debug-control hrcm-debug-control--copy">
            <button type="button" class="button button-secondary" id="hrcm-debug-copy-all">
                <?php esc_html_e('Copy all visible', 'hr-customer-manager'); ?>
            </button>
        </div>
    </div>

    <div class="hrcm-debug-feedback" id="hrcm-debug-feedback" aria-live="polite"></div>

    <div class="hrcm-debug-loading" id="hrcm-debug-loading" hidden>
        <span class="spinner is-active"></span>
        <span class="screen-reader-text"><?php esc_html_e('Loading booking data…', 'hr-customer-manager'); ?></span>
    </div>

    <div class="hrcm-debug-sections" id="hrcm-debug-sections">
        <?php foreach ($sections as $section_key => $section_label) : ?>
            <section class="hrcm-debug-section" data-section="<?php echo esc_attr($section_key); ?>">
                <header class="hrcm-debug-section__header">
                    <h2 class="hrcm-debug-section__title"><?php echo esc_html($section_label); ?></h2>
                    <div class="hrcm-debug-section__controls">
                        <div class="hrcm-debug-view-toggle" role="group" aria-label="<?php echo esc_attr(sprintf(__('View mode for %s', 'hr-customer-manager'), $section_label)); ?>">
                            <button type="button" class="hrcm-debug-view-button is-active" data-mode="pretty">
                                <?php esc_html_e('Pretty', 'hr-customer-manager'); ?>
                            </button>
                            <button type="button" class="hrcm-debug-view-button" data-mode="raw">
                                <?php esc_html_e('Raw JSON', 'hr-customer-manager'); ?>
                            </button>
                        </div>
                        <button type="button" class="button button-small hrcm-debug-copy-section" data-section="<?php echo esc_attr($section_key); ?>">
                            <?php esc_html_e('Copy', 'hr-customer-manager'); ?>
                        </button>
                    </div>
                </header>
                <div class="hrcm-debug-section__body" data-view="pretty">
                    <table class="widefat fixed striped hrcm-debug-table" data-mode="pretty">
                        <thead>
                            <tr>
                                <th scope="col">
                                    <span class="hrcm-debug-col-label">C1</span>
                                    <?php esc_html_e('Key', 'hr-customer-manager'); ?>
                                </th>
                                <th scope="col">
                                    <span class="hrcm-debug-col-label">C2</span>
                                    <?php esc_html_e('Value', 'hr-customer-manager'); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <pre class="hrcm-debug-raw" data-mode="raw" hidden></pre>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</div>
