<?php
if (!defined('ABSPATH')) {
    exit;
}

class MLT_Install
{
    const DB_VERSION = '3';

    public static function activate()
    {
        self::create_tables();
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap(MLT_CAP);
        }
        self::migrate();
        update_option('mlt_db_version', self::DB_VERSION);
    }

    /**
     * v3: every existing board task (kind phase/new) gets a code by its current owner, in id order, and every task
     * gets its owner as an assignee. Safe to run again: rows that already have a code / assignee are skipped.
     * Nothing is deleted or changed otherwise; the new fields stay NULL ("incomplete").
     */
    private static function migrate()
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query("INSERT IGNORE INTO {$p}mlt_task_assignees (task_id, who) SELECT id, owner FROM {$p}mlt_tasks");
        $rows = $wpdb->get_results("SELECT id, owner FROM {$p}mlt_tasks WHERE kind IN ('phase','new') AND code IS NULL ORDER BY id ASC", ARRAY_A);
        foreach ($rows as $r) {
            MLT_Taskmeta::assign_code((int) $r['id'], $r['owner']);
        }
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
  code varchar(12) DEFAULT NULL,
  kind varchar(20) NOT NULL DEFAULT 'new',
  recurrence varchar(10) NOT NULL DEFAULT 'none',
  period_key varchar(20) DEFAULT NULL,
  source_comment_id bigint(20) unsigned DEFAULT NULL,
  estimate_minutes int(10) unsigned DEFAULT NULL,
  estimate_original int(10) unsigned DEFAULT NULL,
  due_date date DEFAULT NULL,
  done_criteria text DEFAULT NULL,
  recurrence_until date DEFAULT NULL,
  meta json DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY legacy_id (legacy_id),
  UNIQUE KEY code (code),
  KEY status (status),
  KEY owner (owner),
  KEY kind_period (kind,period_key)
) $charset;");

        dbDelta("CREATE TABLE {$p}mlt_task_assignees (
  task_id bigint(20) unsigned NOT NULL,
  who varchar(20) NOT NULL,
  PRIMARY KEY  (task_id,who),
  KEY who (who)
) $charset;");

        dbDelta("CREATE TABLE {$p}mlt_counters (
  name varchar(20) NOT NULL,
  value bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (name)
) $charset;");

        dbDelta("CREATE TABLE {$p}mlt_estimate_extensions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  task_id bigint(20) unsigned NOT NULL,
  user bigint(20) unsigned NOT NULL,
  agent varchar(100) DEFAULT NULL,
  old_minutes int(10) unsigned NOT NULL,
  new_minutes int(10) unsigned NOT NULL,
  reason text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY task_id (task_id)
) $charset;");

        dbDelta("CREATE TABLE {$p}mlt_time_entries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  task_id bigint(20) unsigned NOT NULL,
  user bigint(20) unsigned NOT NULL,
  agent varchar(100) DEFAULT NULL,
  started_at datetime NOT NULL,
  ended_at datetime DEFAULT NULL,
  pause_reason varchar(20) DEFAULT NULL,
  is_final tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY user_id (user,id),
  KEY task_id (task_id)
) $charset;");

        dbDelta("CREATE TABLE {$p}mlt_scores (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  task_id bigint(20) unsigned NOT NULL,
  user bigint(20) unsigned NOT NULL,
  week_key varchar(10) NOT NULL,
  completed_at datetime NOT NULL,
  net_seconds int(10) unsigned NOT NULL DEFAULT 0,
  estimate_original int(10) unsigned DEFAULT NULL,
  due_date date DEFAULT NULL,
  priority varchar(10) NOT NULL,
  late tinyint(1) NOT NULL DEFAULT 0,
  late_reason varchar(500) DEFAULT NULL,
  has_timer tinyint(1) NOT NULL DEFAULT 0,
  points int(11) NOT NULL DEFAULT 0,
  tier varchar(20) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY task_id (task_id),
  KEY week_user (week_key,user)
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
