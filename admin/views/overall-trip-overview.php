<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap hr-cm-admin hrcm-overall-trip-overview">
    <h1><?php esc_html_e('Overall Trip Overview', 'hr-customer-manager'); ?></h1>
    <div class="hrcm-tab-bar" role="tablist">
        <button type="button" class="button button-secondary hrcm-tab is-active" data-view="overall" aria-selected="true">
            <?php esc_html_e('Overall', 'hr-customer-manager'); ?>
        </button>
    </div>
    <div id="hrcm-view-root" class="hrcm-view-root" aria-live="polite"></div>
</div>
