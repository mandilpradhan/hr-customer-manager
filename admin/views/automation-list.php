<?php
/**
 * Automation list screen.
 *
 * @var HR_CM_Automations_List_Table $table
 * @var int|false                    $next_run
 */

if (!defined('ABSPATH')) {
    exit;
}

$add_url = add_query_arg([
    'page'   => HR_CM_Automations_Admin::SCREEN_SLUG,
    'action' => 'new',
], admin_url('admin.php'));

$message = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';
$notice  = '';
$notice_type = 'success';

switch ($message) {
    case 'saved':
        $notice = __('Automation rule saved.', 'hr-customer-manager');
        break;
    case 'deleted':
        $notice = __('Automation deleted.', 'hr-customer-manager');
        break;
    case 'queued':
        $notice = __('Automation queued to run.', 'hr-customer-manager');
        break;
    case 'missing-name':
        $notice = __('Rule name is required.', 'hr-customer-manager');
        $notice_type = 'error';
        break;
}

?>
<div class="wrap hr-cm-admin hrcm-automation-admin">
    <h1 class="wp-heading-inline"><?php esc_html_e('Automations', 'hr-customer-manager'); ?></h1>
    <a href="<?php echo esc_url($add_url); ?>" class="page-title-action"><?php esc_html_e('Add New', 'hr-customer-manager'); ?></a>

    <?php if ($notice) : ?>
        <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible">
            <p><?php echo esc_html($notice); ?></p>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="page" value="<?php echo esc_attr(HR_CM_Automations_Admin::SCREEN_SLUG); ?>" />
        <?php $table->display(); ?>
    </form>

    <p class="description">
        <?php if ($next_run) : ?>
            <?php printf(
                /* translators: %s: datetime */
                esc_html__('Next run scheduled for %s.', 'hr-customer-manager'),
                esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run))
            ); ?>
        <?php else : ?>
            <?php esc_html_e('Cron schedule pending setup.', 'hr-customer-manager'); ?>
        <?php endif; ?>
    </p>
</div>
