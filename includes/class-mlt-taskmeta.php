<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The parts of a task that live beside its row: the readable code (MH-1), the assignees, and the checks for the
 * "one template" fields (estimate, due date, done criteria).
 */
class MLT_Taskmeta
{
    const ESTIMATE_MIN = 5;
    const ESTIMATE_MAX = 43200; // 30 days, in minutes
    const CRITERIA_MAX = 2000;
    const BOARD_KINDS = ['phase', 'new'];
    const CODE_PATTERN = '[A-Za-z]{2}-\d+';

    // ---- codes ----

    /**
     * Next number for a prefix, atomically (same pattern as the rate limiter: the row is created with INSERT IGNORE,
     * then one UPDATE hands back this caller's own value through LAST_INSERT_ID(expr)).
     */
    public static function next_code($prefix)
    {
        global $wpdb;
        $t = $wpdb->prefix . 'mlt_counters';
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (name, value) VALUES (%s, 0)", $prefix));
        $wpdb->query($wpdb->prepare("UPDATE $t SET value = LAST_INSERT_ID(value + 1) WHERE name = %s", $prefix));
        return $prefix . '-' . (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
    }

    /** Gives a board task its code (once; it never changes afterwards). $slug picks the prefix. Other kinds get none. */
    public static function assign_code($task_id, $slug)
    {
        global $wpdb;
        $t = $wpdb->prefix . 'mlt_tasks';
        $task = $wpdb->get_row($wpdb->prepare("SELECT kind, code FROM $t WHERE id = %d", $task_id), ARRAY_A);
        if (!$task || $task['code'] !== null || !in_array($task['kind'], self::BOARD_KINDS, true)) {
            return null;
        }
        $code = self::next_code(MLT_Identity::prefix($slug));
        $wpdb->update($t, ['code' => $code], ['id' => $task_id]);
        return $code;
    }

    /** Numeric id for "42" or a code like "MH-3" (any case); 0 when there is no such task. */
    public static function resolve($ref)
    {
        global $wpdb;
        $ref = (string) $ref;
        if (ctype_digit($ref)) {
            return (int) $ref;
        }
        return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}mlt_tasks WHERE code = %s", strtoupper($ref)));
    }

    // ---- assignees ----

    /** task id => list of slugs (in the team's usual order), for many tasks in one query. */
    public static function assignees_for(array $ids)
    {
        global $wpdb;
        $out = array_fill_keys(array_map('intval', $ids), []);
        if (!$ids) {
            return $out;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT task_id, who FROM {$wpdb->prefix}mlt_task_assignees WHERE task_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ')',
            array_map('intval', $ids)
        ), ARRAY_A);
        foreach ($rows as $r) {
            $out[(int) $r['task_id']][] = $r['who'];
        }
        foreach ($out as &$list) {
            $list = array_values(array_intersect(MLT_Identity::slugs(), $list));
        }
        return $out;
    }

    /** Replaces the assignees; the owner is always one of them. Returns [added, removed]. */
    public static function set_assignees($task_id, array $slugs, $owner)
    {
        global $wpdb;
        $t = $wpdb->prefix . 'mlt_task_assignees';
        $want = array_values(array_intersect(MLT_Identity::slugs(), array_merge($slugs, [$owner])));
        $have = self::assignees_for([$task_id])[(int) $task_id];
        foreach (array_diff($want, $have) as $who) {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (task_id, who) VALUES (%d, %s)", $task_id, $who));
        }
        foreach (array_diff($have, $want) as $who) {
            $wpdb->delete($t, ['task_id' => $task_id, 'who' => $who]);
        }
        return [array_values(array_diff($want, $have)), array_values(array_diff($have, $want))];
    }

    // ---- field checks: each returns the clean value, or false when it is not acceptable ----

    /** Whole minutes between ESTIMATE_MIN and ESTIMATE_MAX. */
    public static function estimate($value)
    {
        if (is_bool($value) || !is_numeric($value) || (int) $value != $value) {
            return false;
        }
        $value = (int) $value;
        return $value >= self::ESTIMATE_MIN && $value <= self::ESTIMATE_MAX ? $value : false;
    }

    /** '' / null -> null (no date); a real "Y-m-d" date -> itself; anything else -> false. */
    public static function date($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $d = is_string($value) ? DateTime::createFromFormat('!Y-m-d', $value) : false;
        return $d && $d->format('Y-m-d') === $value ? $value : false;
    }

    /** '' -> null; text up to CRITERIA_MAX characters -> itself; longer -> false. */
    public static function criteria($value)
    {
        $value = trim(sanitize_textarea_field((string) $value));
        if (mb_strlen($value) > self::CRITERIA_MAX) {
            return false;
        }
        return $value === '' ? null : $value;
    }

    /** Called right after a task row was inserted: owner as assignee, and a code for board tasks. */
    public static function after_insert($task_id, $owner, $code_slug, array $assignees = [])
    {
        self::set_assignees($task_id, $assignees, $owner);
        self::assign_code($task_id, $code_slug);
    }

    /** Fields shown in the API for every task. */
    public static function flags(array $row)
    {
        $has_template = in_array($row['kind'], ['phase', 'new', 'routine'], true);
        return [
            'incomplete' => $has_template && $row['estimate_minutes'] === null,
            'late'       => $row['due_date'] !== null && $row['status'] !== 'done' && $row['due_date'] < MLT_Time::today(),
        ];
    }
}
