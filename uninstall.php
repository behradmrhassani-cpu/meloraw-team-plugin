<?php
/**
 * Runs when the plugin is deleted from the WordPress plugins screen.
 * The Telegram bot token is always removed. Everything else stays unless someone ticked
 * "delete all data on uninstall" on the settings page (mlt_settings[delete_on_uninstall]).
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('mlt_settings', []);
$settings = is_array($settings) ? $settings : [];

// The bot token is a secret: it is removed on every uninstall, whatever the flag says.
if (isset($settings['telegram_bot_token'])) {
    unset($settings['telegram_bot_token']);
    update_option('mlt_settings', $settings, false);
}

if (empty($settings['delete_on_uninstall'])) {
    return;
}

global $wpdb;
foreach (['mlt_tasks', 'mlt_comments', 'mlt_activity_log'] as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
}
delete_option('mlt_settings');
delete_option('mlt_db_version');
// rate-limit counters (mlt_rl_* for Telegram, mlt_rg_* for the REST routes)
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('mlt_rl_') . '%',
    $wpdb->esc_like('mlt_rg_') . '%'
));
foreach (wp_roles()->role_objects as $role) {
    $role->remove_cap('manage_meloraw_tasks');
}
