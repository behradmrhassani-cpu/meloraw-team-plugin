<?php
/**
 * فعال‌سازی افزونه: ساخت جدول‌ها و capability.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLT_Install {

    const CAPABILITY = 'manage_meloraw_tasks';
    const DB_VERSION_OPTION = 'mlt_db_version';

    public static function activate() {
        self::create_tables();
        self::add_capability();
        update_option(self::DB_VERSION_OPTION, MLT_VERSION);
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $tasks = $wpdb->prefix . 'mlt_tasks';
        $comments = $wpdb->prefix . 'mlt_comments';
        $log = $wpdb->prefix . 'mlt_activity_log';

        // dbDelta به فرمت دقیق حساسه: هر فیلد در یک خط و دو فاصله بعد از PRIMARY KEY.
        dbDelta("CREATE TABLE $tasks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL,
  description longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'open',
  bucket varchar(20) NOT NULL DEFAULT 'normal',
  priority varchar(10) NOT NULL DEFAULT 'med',
  owner varchar(20) NOT NULL DEFAULT 'you',
  kind varchar(20) NOT NULL DEFAULT 'new',
  recurrence varchar(10) NOT NULL DEFAULT 'none',
  period_key varchar(20) NULL,
  source_comment_id bigint(20) unsigned NULL,
  created_by varchar(60) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_by varchar(60) NOT NULL DEFAULT '',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY owner (owner),
  KEY kind (kind)
) $charset;");

        dbDelta("CREATE TABLE $comments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  task_id bigint(20) unsigned NOT NULL,
  body text NOT NULL,
  author varchar(60) NOT NULL,
  agent varchar(100) NULL,
  flagged tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY task_id (task_id),
  KEY flagged (flagged)
) $charset;");

        dbDelta("CREATE TABLE $log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  task_id bigint(20) unsigned NULL,
  user varchar(60) NOT NULL,
  agent varchar(100) NULL,
  action varchar(40) NOT NULL,
  summary text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY task_id (task_id),
  KEY created_at (created_at)
) $charset;");
    }

    public static function add_capability() {
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap(self::CAPABILITY);
        }
    }
}
