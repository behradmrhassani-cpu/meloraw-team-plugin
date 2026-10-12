<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The estimate of a task is fixed: estimate_original is written once (at creation, or when an incomplete task is
 * filled in) and never changes. The only way to raise estimate_minutes afterwards is a documented extension with
 * a reason. Nothing here ever looks at real time spent: the estimates of later tasks are never adjusted from it.
 */
class MLT_Estimate
{
    const REASON_MIN = 10;
    const REASON_MAX = 1000;

    /** @return array|WP_Error the extension row */
    public static function extend($task_id, $new_minutes, $reason)
    {
        global $wpdb;
        $t = $wpdb->prefix . 'mlt_tasks';
        $task = $wpdb->get_row($wpdb->prepare("SELECT id, title, kind, estimate_minutes FROM $t WHERE id = %d", $task_id), ARRAY_A);
        if (!$task || !in_array($task['kind'], MLT_Taskmeta::BOARD_KINDS, true)) {
            return new WP_Error('mlt_not_found', 'پیدا نشد.', ['status' => 404]);
        }
        if ($task['estimate_minutes'] === null) {
            return new WP_Error('mlt_bad_request', 'این تسک هنوز زمان تقریبی ندارد؛ اول آن را با PATCH ثبت کن.', ['status' => 400]);
        }
        $new = MLT_Taskmeta::estimate($new_minutes);
        if ($new === false) {
            return new WP_Error('mlt_bad_request', sprintf('زمان تقریبی باید عدد صحیح بین %d و %d دقیقه باشد.', MLT_Taskmeta::ESTIMATE_MIN, MLT_Taskmeta::ESTIMATE_MAX), ['status' => 400]);
        }
        $old = (int) $task['estimate_minutes'];
        if ($new <= $old) {
            return new WP_Error('mlt_bad_request', 'تمدید فقط می‌تواند زمان را بیشتر کند (الان ' . $old . ' دقیقه است).', ['status' => 400]);
        }
        $reason = trim(sanitize_textarea_field((string) $reason));
        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            return new WP_Error('mlt_bad_request', sprintf('دلیل تمدید لازم است (بین %d و %d نویسه).', self::REASON_MIN, self::REASON_MAX), ['status' => 400]);
        }

        // conditional update: two requests extending from the same old value cannot both win
        $changed = $wpdb->query($wpdb->prepare("UPDATE $t SET estimate_minutes = %d, updated_by = %d, updated_at = %s WHERE id = %d AND estimate_minutes = %d", $new, get_current_user_id(), current_time('mysql', true), $task_id, $old));
        if ($changed !== 1) {
            return new WP_Error('mlt_conflict', 'زمان تقریبی همین الان عوض شده؛ تسک را دوباره بگیر.', ['status' => 409]);
        }
        $row = [
            'task_id'     => $task_id,
            'user'        => get_current_user_id(),
            'agent'       => MLT_REST::current_agent(),
            'old_minutes' => $old,
            'new_minutes' => $new,
            'reason'      => $reason,
            'created_at'  => current_time('mysql', true),
        ];
        $wpdb->insert($wpdb->prefix . 'mlt_estimate_extensions', $row);
        // the reason itself stays in the extensions table; the log only records that and by how much
        MLT_REST::log_activity($task_id, 'estimate_extend', sprintf('«%s»: %d → %d min (reason recorded)', $task['title'], $old, $new));
        return self::present($row + ['id' => $wpdb->insert_id]);
    }

    public static function extensions($task_id)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mlt_estimate_extensions WHERE task_id = %d ORDER BY id ASC", $task_id), ARRAY_A);
        return array_map([__CLASS__, 'present'], $rows);
    }

    private static function present(array $row)
    {
        $user = get_userdata((int) $row['user']);
        return [
            'id'          => (int) $row['id'],
            'task_id'     => (int) $row['task_id'],
            'user'        => $user ? $user->user_login : null,
            'agent'       => $row['agent'],
            'old_minutes' => (int) $row['old_minutes'],
            'new_minutes' => (int) $row['new_minutes'],
            'reason'      => $row['reason'],
            'created_at'  => $row['created_at'],
        ];
    }
}
