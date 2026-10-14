<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Work timer. One entry in mlt_time_entries is one stretch of work (started_at .. ended_at). Pausing closes the
 * stretch and records why; resuming opens a new one; finishing closes the last one for good (is_final = 1).
 * A user has at most one timer that is not finished (running or paused) and can only touch their own.
 *
 * Net time = the stretches added up. Total time = first start to last end (to now while it is still going).
 * Nothing is ever stopped automatically: a long pause or a very long run only makes the panel ask.
 */
class MLT_Timer
{
    const REASONS = [
        'rest'        => 'استراحت',
        'food'        => 'غذا',
        'offhours'    => 'خواب / خارج از ساعت کار',
        'waiting'     => 'منتظر کسی',
        'interrupted' => 'قطع شدن / کار فوری',
        'other_task'  => 'کار دیگر',
    ];
    const WHY = 'می‌پرسیم تا ببینیم وقت کجا می‌رود و کار چقدر قطع می‌شود؛ برای دیدن بهره‌وری است، نه سرزنش.';

    const LONG_PAUSE = 1800; // seconds: a pause longer than this makes the panel ask "are you continuing?"
    const LONG_RUN = 14400;  // seconds: a stretch longer than this (or one that crossed UTC midnight) makes it ask "when did you finish?"

    private static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'mlt_time_entries';
    }

    private static function ts($utc)
    {
        return (new DateTimeImmutable((string) $utc, new DateTimeZone('UTC')))->getTimestamp();
    }

    private static function now()
    {
        return current_time('mysql', true);
    }

    // ---- state ----

    /** @return array|null ['entry' => row, 'state' => 'running'|'paused'] for the user's unfinished timer */
    public static function active($user)
    {
        global $wpdb;
        $e = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE user = %d ORDER BY id DESC LIMIT 1', $user), ARRAY_A);
        if (!$e || (int) $e['is_final']) {
            return null;
        }
        return ['entry' => $e, 'state' => $e['ended_at'] === null ? 'running' : 'paused'];
    }

    /** Something the panel (or an agent) should ask the user about, or null. */
    public static function attention(array $active)
    {
        $e = $active['entry'];
        $now = time();
        if ($active['state'] === 'paused' && $now - self::ts($e['ended_at']) > self::LONG_PAUSE) {
            return 'long_pause';
        }
        if ($active['state'] === 'running') {
            $start = self::ts($e['started_at']);
            if ($now - $start > self::LONG_RUN || gmdate('Y-m-d', $start) !== gmdate('Y-m-d', $now)) {
                return 'overlong';
            }
        }
        return null;
    }

    /** One timer operation at a time per user, so two parallel "start" calls cannot both win. */
    private static function locked($user, callable $fn)
    {
        global $wpdb;
        $name = 'mlt_timer_' . (int) $user;
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name)) !== 1) {
            return new WP_Error('mlt_busy', 'درخواست دیگری برای تایمر در جریان است؛ دوباره امتحان کن.', ['status' => 409]);
        }
        try {
            return $fn();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    private static function conflict($msg)
    {
        return new WP_Error('mlt_timer_state', $msg, ['status' => 409]);
    }

    private static function task($task_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT id, code, title, kind, status FROM {$wpdb->prefix}mlt_tasks WHERE id = %d", $task_id), ARRAY_A);
    }

    private static function label($task)
    {
        return ($task['code'] ?: '#' . $task['id']);
    }

    // ---- actions (always for the current user) ----

    public static function start($task_id)
    {
        $user = get_current_user_id();
        return self::locked($user, function () use ($user, $task_id) {
            global $wpdb;
            $task = self::task($task_id);
            if (!$task || !in_array($task['kind'], MLT_Taskmeta::BOARD_KINDS, true)) {
                return new WP_Error('mlt_not_found', 'پیدا نشد.', ['status' => 404]);
            }
            if ($task['status'] === 'done') {
                return self::conflict('این تسک انجام‌شده است؛ اول وضعیتش را عوض کن.');
            }
            if ($active = self::active($user)) {
                $other = self::task((int) $active['entry']['task_id']);
                return self::conflict('الان تایمر ' . ($active['state'] === 'running' ? 'روشن' : 'پازشده') . 'ی روی ' . ($other ? self::label($other) : '؟') . ' داری؛ اول آن را تمام کن.');
            }
            $wpdb->insert(self::table(), ['task_id' => $task_id, 'user' => $user, 'agent' => MLT_REST::current_agent(), 'started_at' => self::now()]);
            MLT_REST::log_activity($task_id, 'timer_start', sprintf('«%s» [%s]', $task['title'], self::label($task)));
            if ($task['status'] === 'open') { // working on it means it is in progress
                $wpdb->update("{$wpdb->prefix}mlt_tasks", ['status' => 'in_progress', 'updated_by' => $user, 'updated_at' => self::now()], ['id' => $task_id]);
                MLT_REST::log_activity($task_id, 'status_change', sprintf('«%s»: open → in_progress (timer)', $task['title']));
            }
            return self::active($user);
        });
    }

    public static function pause($task_id, $reason)
    {
        $user = get_current_user_id();
        if (!is_string($reason) || !isset(self::REASONS[$reason])) {
            return new WP_Error('mlt_bad_request', 'دلیل پاز لازم است: ' . implode('، ', array_values(self::REASONS)) . '.', ['status' => 400]);
        }
        return self::locked($user, function () use ($user, $task_id, $reason) {
            global $wpdb;
            $active = self::mine_on($user, $task_id, 'running');
            if (is_wp_error($active)) {
                return $active;
            }
            $wpdb->update(self::table(), ['ended_at' => self::now(), 'pause_reason' => $reason], ['id' => $active['entry']['id']]);
            $task = self::task($task_id);
            MLT_REST::log_activity($task_id, 'timer_pause', sprintf('«%s»: %s', $task['title'], $reason));
            return self::active($user);
        });
    }

    public static function resume($task_id)
    {
        $user = get_current_user_id();
        return self::locked($user, function () use ($user, $task_id) {
            global $wpdb;
            $active = self::mine_on($user, $task_id, 'paused');
            if (is_wp_error($active)) {
                return $active;
            }
            $task = self::task($task_id);
            if ($task['status'] === 'done') {
                return self::conflict('این تسک انجام‌شده است.');
            }
            $wpdb->insert(self::table(), ['task_id' => $task_id, 'user' => $user, 'agent' => MLT_REST::current_agent(), 'started_at' => self::now()]);
            MLT_REST::log_activity($task_id, 'timer_resume', sprintf('«%s»', $task['title']));
            return self::active($user);
        });
    }

    /**
     * Ends the timer. $ended_at ("Y-m-d H:i[:s]" UTC) corrects a stretch that was left running: it must lie between
     * the stretch's start and now. Finishing a paused timer ends the work where the pause began.
     * @return array|WP_Error the closed entry
     */
    public static function finish($task_id, $ended_at = null)
    {
        $user = get_current_user_id();
        return self::locked($user, function () use ($user, $task_id, $ended_at) {
            return self::finish_unlocked($user, $task_id, $ended_at);
        });
    }

    private static function finish_unlocked($user, $task_id, $ended_at)
    {
        global $wpdb;
        $active = self::mine_on($user, $task_id, null);
        if (is_wp_error($active)) {
            return $active;
        }
        $e = $active['entry'];
        $task = self::task($task_id);
        if ($active['state'] === 'running') {
            $end = self::now();
            if ($ended_at !== null && $ended_at !== '') {
                $d = DateTime::createFromFormat('Y-m-d H:i:s', strlen((string) $ended_at) === 16 ? $ended_at . ':00' : (string) $ended_at, new DateTimeZone('UTC'));
                $d = $d ?: DateTime::createFromFormat('Y-m-d\TH:i:s', strlen((string) $ended_at) === 16 ? $ended_at . ':00' : (string) $ended_at, new DateTimeZone('UTC'));
                if (!$d) {
                    return new WP_Error('mlt_bad_request', 'زمان پایان باید به شکل YYYY-MM-DD HH:MM (UTC) باشد.', ['status' => 400]);
                }
                $ts = $d->getTimestamp();
                if ($ts < self::ts($e['started_at']) || $ts > time()) {
                    return new WP_Error('mlt_bad_request', 'زمان پایان باید بعد از شروع و تا همین الان باشد.', ['status' => 400]);
                }
                $end = gmdate('Y-m-d H:i:s', $ts);
            }
            $wpdb->update(self::table(), ['ended_at' => $end, 'is_final' => 1], ['id' => $e['id']]);
            $corrected = $ended_at !== null && $ended_at !== '';
        } else {
            if ($ended_at !== null && $ended_at !== '') {
                return new WP_Error('mlt_bad_request', 'تایمر پازشده است؛ زمان پایان همان لحظه‌ی پاز ثبت می‌شود.', ['status' => 400]);
            }
            $wpdb->update(self::table(), ['pause_reason' => null, 'is_final' => 1], ['id' => $e['id']]); // the pause was the end of the work
            $corrected = false;
        }
        MLT_REST::log_activity($task_id, 'timer_finish', sprintf('«%s»%s', $task['title'], $corrected ? ' (end time corrected)' : ''));
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $e['id']), ARRAY_A);
    }

    /** When a task is set to done, the setter's own timer on it is finished (no lock needed: same request). */
    public static function finish_for_task($user, $task_id)
    {
        $active = self::active($user);
        if ($active && (int) $active['entry']['task_id'] === (int) $task_id) {
            self::finish_unlocked($user, $task_id, null);
        }
    }

    /** The user's timer, if it is on this task and in the expected state ('running' / 'paused' / null = either). */
    private static function mine_on($user, $task_id, $state)
    {
        $active = self::active($user);
        if (!$active || (int) $active['entry']['task_id'] !== (int) $task_id) {
            return self::conflict('تایمر فعالی روی این تسک نداری.');
        }
        if ($state !== null && $active['state'] !== $state) {
            return self::conflict($state === 'running' ? 'تایمر الان پاز است.' : 'تایمر الان روشن است.');
        }
        return $active;
    }

    // ---- reading ----

    public static function present_entry(array $e)
    {
        $user = get_userdata((int) $e['user']);
        return [
            'id'           => (int) $e['id'],
            'task_id'      => (int) $e['task_id'],
            'user'         => $user ? $user->user_login : null,
            'agent'        => $e['agent'],
            'started_at'   => $e['started_at'],
            'ended_at'     => $e['ended_at'],
            'pause_reason' => $e['pause_reason'],
            'final'        => (bool) $e['is_final'],
        ];
    }

    /** The current user's timer for GET /timer. */
    public static function present_active($user)
    {
        $active = self::active($user);
        if (!$active) {
            return ['active' => false];
        }
        $task = self::task((int) $active['entry']['task_id']);
        return [
            'active'     => true,
            'state'      => $active['state'],
            'attention'  => self::attention($active), // 'long_pause' | 'overlong' | null
            'task'       => ['id' => (int) $task['id'], 'code' => $task['code'], 'title' => $task['title']],
            'entry'      => self::present_entry($active['entry']),
            'now'        => self::now(),
        ];
    }

    /**
     * Numbers for one list of entries (one task, one user): net work, total, pauses, pauses by reason.
     * Pause time is the gap between a stretch and the next one (to $now while the pause is still open).
     */
    public static function summarize(array $entries, $now = null)
    {
        $now = $now ?? time();
        $net = 0;
        $pause = 0;
        $by_reason = [];
        $first = null;
        $last_end = null;
        $open = false;
        foreach ($entries as $i => $e) {
            $start = self::ts($e['started_at']);
            $end = $e['ended_at'] === null ? $now : self::ts($e['ended_at']);
            $net += max(0, $end - $start);
            $first = $first ?? $start;
            $last_end = $end;
            if ($e['pause_reason'] !== null) {
                $next = isset($entries[$i + 1]) ? self::ts($entries[$i + 1]['started_at']) : ((int) $e['is_final'] ? $end : $now);
                $gap = max(0, $next - $end);
                $pause += $gap;
                $by_reason[$e['pause_reason']] = ($by_reason[$e['pause_reason']] ?? ['count' => 0, 'seconds' => 0]);
                $by_reason[$e['pause_reason']]['count']++;
                $by_reason[$e['pause_reason']]['seconds'] += $gap;
                $last_end = $next;
            }
        }
        return [
            'entries'       => count($entries),
            'net_seconds'   => $net,
            'total_seconds' => $first === null ? 0 : max(0, $last_end - $first),
            'pause_seconds' => $pause,
            'pause_count'   => array_sum(array_column($by_reason, 'count')),
            'by_reason'     => $by_reason,
        ];
    }

    public static function entries_of_task($task_id, $user = null)
    {
        global $wpdb;
        $sql = 'SELECT * FROM ' . self::table() . ' WHERE task_id = %d' . ($user !== null ? ' AND user = %d' : '') . ' ORDER BY id ASC';
        return $wpdb->get_results($wpdb->prepare($sql, $user !== null ? [$task_id, $user] : [$task_id]), ARRAY_A);
    }

    /** GET /tasks/{id}/time */
    public static function task_time($task_id)
    {
        $entries = self::entries_of_task($task_id);
        $by_user = [];
        foreach ($entries as $e) {
            $by_user[(int) $e['user']][] = $e;
        }
        $users = [];
        foreach ($by_user as $uid => $list) {
            $u = get_userdata($uid);
            $users[$u ? $u->user_login : '#' . $uid] = self::summarize($list);
        }
        return [
            'task_id' => (int) $task_id,
            'total'   => self::summarize($entries),
            'by_user' => $users,
            'entries' => array_map([__CLASS__, 'present_entry'], $entries),
        ];
    }

    /** Seconds of finished work the user put into one task (used for the score; a running stretch is not counted). */
    public static function net_seconds($task_id, $user)
    {
        return self::summarize(self::entries_of_task($task_id, $user))['net_seconds'];
    }
}
