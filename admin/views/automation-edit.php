<?php
/**
 * Automation edit screen.
 *
 * @var array  $rule
 * @var array  $field_config
 * @var array  $operator_config
 * @var bool   $is_new
 * @var string $status
 */

if (!defined('ABSPATH')) {
    exit;
}

$action_url = admin_url('admin-post.php');
$back_url   = add_query_arg(['page' => HR_CM_Automations_Admin::SCREEN_SLUG], admin_url('admin.php'));
$headers    = isset($rule['action']['headers']) && is_array($rule['action']['headers']) ? $rule['action']['headers'] : [];
$payload    = isset($rule['action']['payload']) ? $rule['action']['payload'] : '';
$method     = isset($rule['action']['method']) ? $rule['action']['method'] : 'POST';
$url        = isset($rule['action']['url']) ? $rule['action']['url'] : '';
$raw        = !empty($rule['action']['raw']);
$repeat     = !empty($rule['schedule']['repeat']);

?>
<div class="wrap hr-cm-admin hrcm-automation-admin">
    <h1><?php echo esc_html($is_new ? __('Add Automation', 'hr-customer-manager') : __('Edit Automation', 'hr-customer-manager')); ?></h1>

    <?php
    $message = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';
    if ('missing-name' === $message) :
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Rule name is required.', 'hr-customer-manager'); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url($action_url); ?>" class="hrcm-automation-form">
        <?php wp_nonce_field(HR_CM_Automations_Admin::NONCE_ACTION); ?>
        <input type="hidden" name="action" value="hrcm_save_automation" />
        <input type="hidden" name="rule_id" value="<?php echo esc_attr($post ? $post->ID : 0); ?>" />

        <div class="hrcm-metabox">
            <h2><?php esc_html_e('Rule Basics', 'hr-customer-manager'); ?></h2>
            <div class="hrcm-field-group">
                <label for="hrcm-rule-name" class="hrcm-field-label"><?php esc_html_e('Rule Name', 'hr-customer-manager'); ?> <span class="required">*</span></label>
                <input type="text" id="hrcm-rule-name" name="rule_name" value="<?php echo esc_attr($post ? $post->post_title : ''); ?>" class="regular-text" required />
            </div>
            <div class="hrcm-field-group">
                <label for="hrcm-rule-status" class="hrcm-field-label"><?php esc_html_e('Status', 'hr-customer-manager'); ?></label>
                <select id="hrcm-rule-status" name="rule_status">
                    <option value="publish" <?php selected('publish', $status); ?>><?php esc_html_e('Enabled', 'hr-customer-manager'); ?></option>
                    <option value="draft" <?php selected('draft', $status); ?>><?php esc_html_e('Disabled', 'hr-customer-manager'); ?></option>
                </select>
            </div>
        </div>

        <div class="hrcm-metabox" id="hrcm-conditions-metabox"
            data-conditions="<?php echo esc_attr(wp_json_encode($rule['conditions'])); ?>"
            data-fields="<?php echo esc_attr(wp_json_encode($field_config)); ?>"
            data-operators="<?php echo esc_attr(wp_json_encode($operator_config)); ?>">
            <h2><?php esc_html_e('Conditions', 'hr-customer-manager'); ?></h2>
            <p class="description"><?php esc_html_e('Define when the automation should run. Rows are processed in order, and each join applies to the next condition.', 'hr-customer-manager'); ?></p>
            <div id="hrcm-conditions-container"></div>
            <p><button type="button" class="button button-secondary" id="hrcm-add-condition"><?php esc_html_e('Add condition', 'hr-customer-manager'); ?></button></p>
        </div>

        <div class="hrcm-metabox">
            <h2><?php esc_html_e('Webhook Action', 'hr-customer-manager'); ?></h2>
            <div class="hrcm-field-group">
                <label for="hrcm-webhook-url" class="hrcm-field-label"><?php esc_html_e('Webhook URL', 'hr-customer-manager'); ?> <span class="required">*</span></label>
                <input type="url" id="hrcm-webhook-url" name="rule[action][url]" value="<?php echo esc_attr($url); ?>" class="regular-text" required />
            </div>
            <div class="hrcm-field-group">
                <label for="hrcm-webhook-method" class="hrcm-field-label"><?php esc_html_e('Method', 'hr-customer-manager'); ?></label>
                <select id="hrcm-webhook-method" name="rule[action][method]">
                    <option value="POST" <?php selected('POST', $method); ?>><?php esc_html_e('POST', 'hr-customer-manager'); ?></option>
                    <option value="GET" <?php selected('GET', $method); ?>><?php esc_html_e('GET', 'hr-customer-manager'); ?></option>
                </select>
            </div>

            <div class="hrcm-field-group">
                <label class="hrcm-field-label"><?php esc_html_e('Headers', 'hr-customer-manager'); ?></label>
                <div id="hrcm-headers-container" data-headers="<?php echo esc_attr(wp_json_encode($headers)); ?>"></div>
                <p><button type="button" class="button button-secondary" id="hrcm-add-header"><?php esc_html_e('Add header', 'hr-customer-manager'); ?></button></p>
            </div>

            <div class="hrcm-field-group">
                <label for="hrcm-webhook-payload" class="hrcm-field-label"><?php esc_html_e('Payload template', 'hr-customer-manager'); ?></label>
                <textarea id="hrcm-webhook-payload" name="rule[action][payload]" rows="8" class="large-text code"><?php echo esc_textarea($payload); ?></textarea>
                <label><input type="checkbox" name="rule[action][raw]" value="1" <?php checked($raw); ?> /> <?php esc_html_e('Send as raw payload (disable JSON wrapping).', 'hr-customer-manager'); ?></label>
                <p class="description"><?php esc_html_e('Available merge tags:', 'hr-customer-manager'); ?> <code>{booking_id}</code>, <code>{trip_name}</code>, <code>{departure}</code>, <code>{days_to_trip}</code>, <code>{payment_status}</code>, <code>{info_received}</code>, <code>{current_phase}</code>, <code>{last_email_sent}</code>, <code>{last_email_template}</code>, <code>{last_email_sent_age_days}</code></p>
            </div>

            <div class="hrcm-field-group">
                <button type="button" class="button button-secondary" id="hrcm-test-webhook" data-test-nonce="<?php echo esc_attr(wp_create_nonce(HR_CM_Automations_Admin::TEST_NONCE_ACTION)); ?>"><?php esc_html_e('Test Send', 'hr-customer-manager'); ?></button>
                <span class="spinner"></span>
                <div id="hrcm-test-result" aria-live="polite"></div>
            </div>
        </div>

        <div class="hrcm-metabox">
            <h2><?php esc_html_e('Schedule & Execution', 'hr-customer-manager'); ?></h2>
            <p class="description"><?php esc_html_e('Rules run every 15 minutes. Enable repeat firing to re-send for the same booking on future evaluations.', 'hr-customer-manager'); ?></p>
            <label><input type="checkbox" name="rule[schedule][repeat]" value="1" <?php checked($repeat); ?> /> <?php esc_html_e('Re-fire for the same booking on subsequent runs', 'hr-customer-manager'); ?></label>
        </div>

        <p class="submit">
            <a href="<?php echo esc_url($back_url); ?>" class="button button-secondary"><?php esc_html_e('Cancel', 'hr-customer-manager'); ?></a>
            <button type="submit" class="button button-primary"><?php esc_html_e('Save Automation', 'hr-customer-manager'); ?></button>
        </p>
    </form>
</div>
