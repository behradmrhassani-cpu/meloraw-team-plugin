<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Points, given when a board task is set to done. The basis is the net work time of whoever completed it,
 * the ORIGINAL estimate (extensions never move it) and the due date:
 *
 *   on time and net <= estimate           -> 10 x weight           (tier "full")
 *   on time and net <= estimate + 25 %    ->  5 x weight           (tier "half")
 *   anything else (more than +25 % or past the due date)
 *                                         ->  1 x weight, 2 x weight with a reason (tiers "low", "low_reason")
 *   no timer was used                     ->  0, tier "no_timer"   (the task is still done)
 *   no estimate (incomplete task)         ->  0, tier "no_estimate"
 *
 * weight: high 3, medium 2, low 1. One row per task in mlt_scores; the row keeps the inputs, so everything
 * can be recomputed (recalculate_all). Reopening a task removes its row.
 */
class MLT_Score
{
    const WEIGHT = ['high' => 3, 'med' => 2, 'low' => 1];
    const FULL = 10;
    const HALF = 5;
    const LOW = 1;
    const LOW_REASON = 2;
    const REASON_MAX = 500;

    const TIERS = [
        'full'        => 'امتیاز کامل',
        'half'        => 'نصف امتیاز',
        'low'         => 'امتیاز خیلی کم',
        'low_reason'  => 'امتیاز کم (با دلیل)',
        'no_timer'    => 'بدون زمان‌سنج',
        'no_estimate' => 'بدون زمان تقریبی',
    ];

    /**
     * Pure function: [points, tier]. Integer arithmetic only, so "exactly 25 % over" is decided without rounding:
     * net <= estimate * 1.25  <=>  net * 4 <= estimate * 5 (both in seconds).
     */
    public static function points($priority, $estimate_original_minutes, $net_seconds, $has_timer, $late, $has_reason)
    {
        if (!$has_timer) {
            return [0, 'no_timer'];
        }
        if ($estimate_original_minutes === null) {
            return [0, 'no_estimate'];
        }
        $weight = self::WEIGHT[$priority] ?? 1;
        $estimate = (int) $estimate_original_minutes * 60;
        $net = (int) $net_seconds;
        if (!$late && $net <= $estimate) {
            return [self::FULL * $weight, 'full'];
        }
        if (!$late && $net * 4 <= $estimate * 5) {
            return [self::HALF * $weight, 'half'];
        }
        return $has_reason ? [self::LOW_REASON * $weight, 'low_reason'] : [self::LOW * $weight, 'low'];
    }

    /** "2026-W42" for a Unix time, the same week numbering the routines use. */
    public static function week_key($ts)
    {
        return MLT_Routines::period_key('weekly', (new DateTimeImmutable('@' . (int) $ts))->setTimezone(new DateTimeZone('UTC')));
    }

    private static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'mlt_scores';
    }

    /** The setter's own running timer on this task ends with the task. Call before the status is saved. */
    public static function before_done($user, $task_id)
    {
        MLT_Timer::finish_for_task($user, $task_id);
    }

    /** Called after a status change was saved: score when it became done, forget the score when it was reopened. */
    public static function on_status_change(array $old, array $new, $late_reason)
    {
        global $wpdb;
        if (!in_array($new['kind'], MLT_Taskmeta::BOARD_KINDS, true)) {
            return;
        }
        if ($new['status'] === 'done' && $old['status'] !== 'done') {
            self::record($new, get_current_user_id(), $late_reason);
        } elseif ($old['status'] === 'done' && $new['status'] !== 'done') {
            if ($wpdb->delete(self::table(), ['task_id' => (int) $new['id']])) {
                MLT_REST::log_activity((int) $new['id'], 'score_remove', sprintf('«%s»: reopened', $new['title']));
            }
        }
    }

    /** Computes and stores the score row of a task that was just completed by $user. */
    public static function record(array $task, $user, $late_reason = null, $completed_ts = null)
    {
        global $wpdb;
        $completed_ts = $completed_ts ?? time();
        $user = (int) $user;
        $entries = MLT_Timer::entries_of_task((int) $task['id'], $user);
        $net = MLT_Timer::summarize($entries)['net_seconds'];
        $late = self::is_late($task['due_date'], $completed_ts);
        $reason = $late_reason !== null && $late_reason !== '' ? mb_substr(sanitize_text_field((string) $late_reason), 0, self::REASON_MAX) : null;
        $estimate = $task['estimate_original'] === null ? null : (int) $task['estimate_original'];
        list($points, $tier) = self::points($task['priority'], $estimate, $net, (bool) $entries, $late, $reason !== null);

        $row = [
            'task_id'           => (int) $task['id'],
            'user'              => $user,
            'week_key'          => self::week_key($completed_ts),
            'completed_at'      => gmdate('Y-m-d H:i:s', $completed_ts),
            'net_seconds'       => $net,
            'estimate_original' => $estimate,
            'due_date'          => $task['due_date'],
            'priority'          => $task['priority'],
            'late'              => $late ? 1 : 0,
            'late_reason'       => $reason,
            'has_timer'         => $entries ? 1 : 0,
            'points'            => $points,
            'tier'              => $tier,
        ];
        $wpdb->delete(self::table(), ['task_id' => $row['task_id']]);
        $wpdb->insert(self::table(), $row);
        MLT_REST::log_activity((int) $task['id'], 'score', sprintf(
            '«%s» [%s]: %+d (%s)%s%s',
            $task['title'],
            (string) $task['code'],
            $points,
            $tier,
            $late ? ' late' : '',
            $reason !== null ? ' - reason: ' . $reason : ''
        ));
        return $row;
    }

    /** Done after the due date's last second (the due date is a UTC calendar day). */
    private static function is_late($due_date, $completed_ts)
    {
        return $due_date !== null && gmdate('Y-m-d', $completed_ts) > $due_date;
    }

    /** Recomputes every score from the task and its time entries (e.g. after the rules changed). Returns [rows, changed]. */
    public static function recalculate_all()
    {
        global $wpdb;
        $changed = 0;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table(), ARRAY_A);
        foreach ($rows as $r) {
            $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mlt_tasks WHERE id = %d", $r['task_id']), ARRAY_A);
            if (!$task || $task['status'] !== 'done') {
                $wpdb->delete(self::table(), ['id' => $r['id']]);
                $changed++;
                continue;
            }
            $entries = MLT_Timer::entries_of_task((int) $r['task_id'], (int) $r['user']);
            $net = MLT_Timer::summarize($entries)['net_seconds'];
            $ts = (new DateTimeImmutable($r['completed_at'], new DateTimeZone('UTC')))->getTimestamp();
            $late = self::is_late($task['due_date'], $ts);
            $estimate = $task['estimate_original'] === null ? null : (int) $task['estimate_original'];
            list($points, $tier) = self::points($task['priority'], $estimate, $net, (bool) $entries, $late, $r['late_reason'] !== null);
            $new = ['net_seconds' => $net, 'estimate_original' => $estimate, 'due_date' => $task['due_date'], 'priority' => $task['priority'], 'late' => $late ? 1 : 0, 'has_timer' => $entries ? 1 : 0, 'points' => $points, 'tier' => $tier];
            $differs = false;
            foreach ($new as $k => $v) {
                $differs = $differs || (string) $v !== (string) $r[$k];
            }
            if ($differs) {
                $wpdb->update(self::table(), $new, ['id' => $r['id']]);
                $changed++;
            }
        }
        MLT_REST::log_activity(null, 'score_recalc', sprintf('rows: %d, changed: %d', count($rows), $changed));
        return [count($rows), $changed];
    }

    // ---- reading ----

    /** Score rows of one week, optionally for one user. */
    public static function rows($week, $user = null)
    {
        global $wpdb;
        $sql = 'SELECT s.*, t.code, t.title FROM ' . self::table() . " s LEFT JOIN {$wpdb->prefix}mlt_tasks t ON t.id = s.task_id WHERE s.week_key = %s" . ($user !== null ? ' AND s.user = %d' : '') . ' ORDER BY s.id DESC';
        return $wpdb->get_results($wpdb->prepare($sql, $user !== null ? [$week, $user] : [$week]), ARRAY_A);
    }

    public static function present(array $r)
    {
        $user = get_userdata((int) $r['user']);
        return [
            'task_id'           => (int) $r['task_id'],
            'code'              => $r['code'],
            'title'             => $r['title'],
            'user'              => $user ? $user->user_login : null,
            'week'              => $r['week_key'],
            'completed_at'      => $r['completed_at'],
            'points'            => (int) $r['points'],
            'tier'              => $r['tier'],
            'net_seconds'       => (int) $r['net_seconds'],
            'estimate_original' => $r['estimate_original'] === null ? null : (int) $r['estimate_original'],
            'due_date'          => $r['due_date'],
            'priority'          => $r['priority'],
            'late'              => (bool) $r['late'],
            'late_reason'       => $r['late_reason'],
            'has_timer'         => (bool) $r['has_timer'],
        ];
    }

    /** May the current user see this user's points? Themselves, or an administrator. */
    public static function can_see($user_id)
    {
        return (int) $user_id === get_current_user_id() || current_user_can('manage_options');
    }
}
