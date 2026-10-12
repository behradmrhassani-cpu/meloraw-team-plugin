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
define('MLT_CAP', 'manage_meloraw_tasks');

require_once MLT_PLUGIN_DIR . 'includes/class-mlt-install.php';
require_once MLT_PLUGIN_DIR . 'includes/class-mlt-rest.php';
require_once MLT_PLUGIN_DIR . 'includes/class-mlt-time.php';
require_once MLT_PLUGIN_DIR . 'includes/class-mlt-log.php';
require_once MLT_PLUGIN_DIR . 'includes/class-mlt-reports.php';
require_once MLT_PLUGIN_DIR . 'includes/class-mlt-settings.php';
require_once MLT_PLUGIN_DIR . 'includes/class-mlt-telegram.php';
require_once MLT_PLUGIN_DIR . 'includes/class-mlt-routines.php';
require_once MLT_PLUGIN_DIR . 'includes/class-mlt-importer.php';
require_once MLT_PLUGIN_DIR . 'includes/class-mlt-admin.php';

register_activation_hook(__FILE__, ['MLT_Install', 'activate']);
add_action('plugins_loaded', ['MLT_Install', 'maybe_upgrade']);
add_action('rest_api_init', ['MLT_REST', 'register_routes']);
MLT_Admin::init();
