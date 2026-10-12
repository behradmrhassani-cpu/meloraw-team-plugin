<?php
/**
 * Plugin Name:       MELORAW Team Tasks
 * Description:       تسک‌بورد داخلی تیم MELORAW. فقط برای ادمین‌ها، با API برای ایجنت‌ها.
 * Version:           0.1.0
 * Requires PHP:      7.4
 * Author:            MELORAW
 * License:           Proprietary
 * Text Domain:       meloraw-team-tasks
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MLT_VERSION', '0.1.0');
define('MLT_PLUGIN_FILE', __FILE__);
define('MLT_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once MLT_PLUGIN_DIR . 'includes/class-mlt-install.php';

register_activation_hook(__FILE__, ['MLT_Install', 'activate']);

// TODO: include remaining classes from includes/ as they are built (REST, admin, telegram).
