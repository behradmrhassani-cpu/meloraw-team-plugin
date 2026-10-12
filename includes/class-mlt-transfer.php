<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GET /export and POST /import: the whole board (tasks, routines and ideas are all tasks rows, plus
 * comments) as one JSON document, and the way back in.
 *
 * Nothing sensitive is in the export: no settings, token, e-mail or password hash, and no activity
 * log. Users appear as logins only; importing maps a login to a user on this site, else to the importer.
 *
 * Import skips duplicates: a row with a legacy_id is a duplicate when that legacy_id exists; a row
 * without one is a duplicate when a task with the same title existed before the import began. Comments
 * are imported only for tasks that were created by that same import (so a second import adds nothing).
 * The inserts run in one transaction: if the database fails midway nothing is kept.
 */
class MLT_Transfer
{
    const FORMAT = 'meloraw-tasks-export';
    const VERSION = 1;
    const MAX_BYTES = 5242880;
    const MAX_REASONS = 30;
    const RECURRENCES = ['none', 'daily', 'weekly', 'monthly'];
    const META_KEYS = ['routine_id', 'type', 'category', 'tag'];

    public static function export()
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $tasks = $wpdb->get_results("SELECT * FROM {$p}mlt_tasks ORDER BY id ASC", ARRAY_A);
        $comments = $wpdb->get_results("SELECT * FROM {$p}mlt_comments ORDER BY id ASC", ARRAY_A);

        $ids = [];
        foreach ($tasks as $t) {
            $ids[] = (int) $t['created_by'];
            $ids[] = (int) $t['updated_by'];
        }
        cache_users(array_unique(array_merge($ids, array_map('intval', wp_list_pluck($comments, 'author')))));
        $login = function ($id) {
            $user = get_userdata((int) $id);
            return $user ? $user->user_login : null;
        };

        $out = [];
        foreach ($tasks as $t) {
            $t = MLT_REST::format_task($t);
            $t['created_by_login'] = $login($t['created_by']);
            $t['updated_by_login'] = $login($t['updated_by']);
            $out[] = $t;
        }
        return [
            'format'      => self::FORMAT,
            'version'     => self::VERSION,
            'exported_at' => gmdate('Y-m-d H:i:s'),
            'tasks'       => $out,
            'comments'    => array_map(['MLT_REST', 'format_comment'], $comments),
        ];
    }

    /** @return array|WP_Error report: dry, format, tasks{created,duplicate,invalid}, comments{created,skipped,invalid}, reasons[] */
    public static function import(array $data, $dry)
    {
        global $wpdb;
        $p = $wpdb->prefix;
        if (($data['version'] ?? null) !== self::VERSION || !isset($data['tasks']) || !is_array($data['tasks']) || (isset($data['comments']) && !is_array($data['comments']))) {
            return new WP_Error('mlt_import_format', 'قالب فایل export درست نیست (version، tasks یا comments).', ['status' => 400]);
        }

        $now = current_time('mysql', true);
        $me = get_current_user_id();
        $report = [
            'dry'      => (bool) $dry,
            'format'   => 'export',
            'tasks'    => ['created' => 0, 'duplicate' => 0, 'invalid' => 0],
            'comments' => ['created' => 0, 'skipped' => 0, 'invalid' => 0],
            'reasons'  => [],
        ];

        // what is already there (before this import begins)
        $legacy = array_flip($wpdb->get_col("SELECT legacy_id FROM {$p}mlt_tasks WHERE legacy_id IS NOT NULL"));
        $titles = [];
        foreach ($wpdb->get_col("SELECT title FROM {$p}mlt_tasks") as $title) {
            $titles[mb_strtolower(trim($title))] = true;
        }

        // 1) tasks: validate, drop duplicates
        $new_tasks = []; // file task id => row to insert
        foreach ($data['tasks'] as $i => $item) {
            $row = is_array($item) ? self::build_task($item, $now, $me) : 'ردیف یک شیء JSON نیست';
            if (is_string($row)) {
                self::reject($report, 'tasks', $item, $i, $row);
                continue;
            }
            $key = $row['legacy_id'];
            if ($key !== null ? isset($legacy[$key]) : isset($titles[mb_strtolower($row['title'])])) {
                $report['tasks']['duplicate']++;
                continue;
            }
            if ($key !== null) {
                $legacy[$key] = true; // a repeat inside the file is a duplicate too
            }
            $new_tasks[] = [$item['id'] ?? null, $row];
            $report['tasks']['created']++;
        }

        // 2) comments of the tasks that are being created
        $by_file_id = [];
        foreach ($new_tasks as $n => list($file_id, $row)) {
            if (is_int($file_id) && $file_id > 0) {
                $by_file_id[$file_id] = $n;
            }
        }
        $new_comments = [];
        foreach ($data['comments'] ?? [] as $i => $item) {
            $row = is_array($item) ? self::build_comment($item, $now, $me) : 'ردیف یک شیء JSON نیست';
            if (is_string($row)) {
                self::reject($report, 'comments', $item, $i, $row);
                continue;
            }
            $n = $by_file_id[$item['task_id']] ?? null;
            if ($n === null) {
                $report['comments']['skipped']++; // its task was a duplicate or is not in the file
                continue;
            }
            $new_comments[] = [$n, $item['id'] ?? null, $row];
            $report['comments']['created']++;
        }

        if ($dry) {
            return $report;
        }

        // 3) write everything or nothing
        $wpdb->query('START TRANSACTION');
        $task_ids = [];
        foreach ($new_tasks as $n => list($file_id, $row)) {
            $source = $row['source_comment_id'];
            $row['source_comment_id'] = null; // linked below, once the comments have new ids
            if (!$wpdb->insert("{$p}mlt_tasks", $row)) {
                return self::abort();
            }
            $task_ids[$n] = [$wpdb->insert_id, $source];
        }
        $comment_ids = [];
        foreach ($new_comments as list($n, $file_id, $row)) {
            if (!$wpdb->insert("{$p}mlt_comments", ['task_id' => $task_ids[$n][0]] + $row)) {
                return self::abort();
            }
            if (is_int($file_id) && $file_id > 0) {
                $comment_ids[$file_id] = $wpdb->insert_id;
            }
        }
        foreach ($task_ids as list($id, $source)) {
            if ($source !== null && isset($comment_ids[$source]) && $wpdb->update("{$p}mlt_tasks", ['source_comment_id' => $comment_ids[$source]], ['id' => $id]) === false) {
                return self::abort();
            }
        }
        $wpdb->query('COMMIT');

        MLT_REST::log_activity(null, 'import', sprintf(
            'export file | tasks: %d created, %d duplicate, %d invalid | comments: %d created, %d skipped, %d invalid',
            $report['tasks']['created'],
            $report['tasks']['duplicate'],
            $report['tasks']['invalid'],
            $report['comments']['created'],
            $report['comments']['skipped'],
            $report['comments']['invalid']
        ));
        return $report;
    }

    private static function abort()
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
        return new WP_Error('mlt_db_error', 'ذخیره انجام نشد؛ هیچ‌چیز وارد نشد.', ['status' => 500]);
    }

    // ---- row builders: a DB row, or a Persian reason when the row is rejected ----

    private static function build_task(array $r, $now, $me)
    {
        $legacy = $r['legacy_id'] ?? null;
        if ($legacy === '') {
            $legacy = null;
        }
        if ($legacy !== null && (!is_string($legacy) || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $legacy))) {
            return 'legacy_id نامعتبر است';
        }
        $title = isset($r['title']) && is_string($r['title']) ? sanitize_text_field($r['title']) : '';
        if ($title === '') {
            return 'عنوان خالی است';
        }
        if (mb_strlen($title) > MLT_REST::TITLE_MAX) {
            return 'عنوان بیش از حد طولانی است';
        }
        if (isset($r['description']) && !is_string($r['description'])) {
            return 'توضیح باید متن باشد';
        }

        $row = ['legacy_id' => $legacy, 'title' => $title, 'description' => sanitize_textarea_field($r['description'] ?? '')];
        $defaults = ['status' => 'open', 'bucket' => 'normal', 'priority' => 'med', 'owner' => null, 'kind' => 'new'];
        foreach ($defaults as $field => $default) {
            $value = $r[$field] ?? $default;
            if ($value === null) {
                return 'مسئول (owner) مشخص نیست';
            }
            if (!is_string($value) || !in_array($value, MLT_REST::ENUMS[$field], true)) {
                return 'مقدار ' . $field . ' نامعتبر است';
            }
            $row[$field] = $value;
        }

        $recurrence = $r['recurrence'] ?? 'none';
        if (!is_string($recurrence) || !in_array($recurrence, self::RECURRENCES, true)) {
            return 'recurrence نامعتبر است';
        }
        $period = $r['period_key'] ?? null;
        if ($recurrence === 'none' ? $period !== null : (!is_string($period) || !preg_match(MLT_Importer::PERIOD_FORMAT[$recurrence], $period))) {
            return 'period_key با recurrence نمی‌خواند';
        }
        $meta = self::meta($r['meta'] ?? null);
        if (is_string($meta)) {
            return $meta;
        }
        if ($row['kind'] === 'routine' && ($recurrence === 'none' || !isset($meta['routine_id']))) {
            return 'روتین باید recurrence، period_key و meta.routine_id داشته باشد';
        }
        $source = $r['source_comment_id'] ?? null;
        if ($source !== null && (!is_int($source) || $source < 1)) {
            return 'source_comment_id نامعتبر است';
        }

        $created = self::date($r, 'created_at', $now);
        $updated = self::date($r, 'updated_at', $created);
        if ($created === null || $updated === null) {
            return 'created_at یا updated_at نامعتبر است';
        }
        return $row + [
            'recurrence'        => $recurrence,
            'period_key'        => $period,
            'source_comment_id' => $source,
            'meta'              => $meta ? wp_json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'created_by'        => self::user($r['created_by_login'] ?? null, $me),
            'created_at'        => $created,
            'updated_by'        => self::user($r['updated_by_login'] ?? null, $me),
            'updated_at'        => $updated,
        ];
    }

    private static function build_comment(array $r, $now, $me)
    {
        if (!isset($r['task_id']) || !is_int($r['task_id'])) {
            return 'task_id نامعتبر است';
        }
        $body = isset($r['body']) && is_string($r['body']) ? trim(sanitize_textarea_field($r['body'])) : '';
        if ($body === '') {
            return 'متن کامنت خالی است';
        }
        if (mb_strlen($body) > MLT_REST::BODY_MAX) {
            return 'متن کامنت بیش از حد طولانی است';
        }
        $agent = $r['agent'] ?? null;
        if ($agent !== null && !is_string($agent)) {
            return 'agent نامعتبر است';
        }
        $created = self::date($r, 'created_at', $now);
        if ($created === null) {
            return 'created_at نامعتبر است';
        }
        return [
            'body'       => $body,
            'author'     => self::user($r['author_login'] ?? null, $me),
            'agent'      => $agent === null || $agent === '' ? null : mb_substr(sanitize_text_field($agent), 0, 100),
            'flagged'    => !empty($r['flagged']) ? 1 : 0,
            'created_at' => $created,
        ];
    }

    /** null/[] -> []; otherwise only the keys the plugin uses, with short text values; else a reason string. */
    private static function meta($meta)
    {
        if ($meta === null || $meta === []) {
            return [];
        }
        if (!is_array($meta)) {
            return 'meta نامعتبر است';
        }
        $clean = [];
        foreach ($meta as $key => $value) {
            if (!in_array($key, self::META_KEYS, true) || !is_string($value) || mb_strlen($value) > 200) {
                return 'meta نامعتبر است';
            }
            $clean[$key] = sanitize_text_field($value);
        }
        if (isset($clean['routine_id']) && !preg_match('/^[A-Za-z0-9._-]{1,32}$/', $clean['routine_id'])) {
            return 'meta.routine_id نامعتبر است';
        }
        return $clean;
    }

    /** $r[$field] as a 'Y-m-d H:i:s' string; $default when the field is missing; null when it is malformed. */
    private static function date(array $r, $field, $default)
    {
        $value = $r[$field] ?? $default;
        $date = is_string($value) ? DateTime::createFromFormat('Y-m-d H:i:s', $value) : false;
        return $date && $date->format('Y-m-d H:i:s') === $value ? $value : null;
    }

    private static function user($login, $me)
    {
        $user = is_string($login) && $login !== '' ? get_user_by('login', $login) : false;
        return $user ? (int) $user->ID : $me;
    }

    private static function reject(array &$report, $section, $item, $index, $reason)
    {
        $report[$section]['invalid']++;
        if (count($report['reasons']) < self::MAX_REASONS) {
            $id = is_array($item) && isset($item['id']) && is_scalar($item['id']) ? mb_substr((string) $item['id'], 0, 40) : '#' . $index;
            $report['reasons'][] = ['section' => $section, 'id' => $id, 'reason' => $reason];
        }
    }
}
