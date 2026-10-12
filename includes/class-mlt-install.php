<?php
if (!defined('ABSPATH')) {
    exit;
}

class MLT_Install
{
    const DB_VERSION = '2';

    public static function activate()
    {
        self::create_tables();
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap(MLT_CAP);
        }
        update_option('mlt_db_version', self::DB_VERSION);
    }

    public static function maybe_upgrade()
    {
        if (get_option('mlt_db_version') !== self::DB_VERSION) {
            self::activate();
        }
    }

    private static function create_tables()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}mlt_tasks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  legacy_id varchar(64) DEFAULT NULL,
  title varchar(255) NOT NULL,
  description longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'open',
  bucket varchar(20) NOT NULL DEFAULT 'normal',
  priority varchar(10) NOT NULL DEFAULT 'med',
  owner varchar(20) NOT NULL DEFAULT 'you',
  kind varchar(20) NOT NULL DEFAULT 'new',
  recurrence varchar(10) NOT NULL DEFAULT 'none',
  period_key varchar(20) DEFAULT NULL,
  source_comment_id bigint(20) unsigned DEFAULT NULL,
  meta json DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY legacy_id (legacy_id),
  KEY status (status),
  KEY owner (owner),
  KEY kind_period (kind,period_key)
) $charset;");

        dbDelta("CREATE TABLE {$p}mlt_comments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  task_id bigint(20) unsigned NOT NULL,
  body text NOT NULL,
  author bigint(20) unsigned NOT NULL,
  agent varchar(100) DEFAULT NULL,
  flagged tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY task_id (task_id),
  KEY flagged (flagged)
) $charset;");

        dbDelta("CREATE TABLE {$p}mlt_activity_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  task_id bigint(20) unsigned DEFAULT NULL,
  user bigint(20) unsigned NOT NULL,
  agent varchar(100) DEFAULT NULL,
  action varchar(50) NOT NULL,
  summary text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY task_id (task_id),
  KEY created_at (created_at)
) $charset;");
    }
}
