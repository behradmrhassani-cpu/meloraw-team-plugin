<?php
/**
 * Scenarios that need fabricated rows or clock values, so they run inside WordPress on a TEST site:
 *
 *   MLT_ALLOW_DB_TESTS=1 wp eval-file tests/db-scenarios.php --user=<admin login>
 *
 * It creates and deletes its own tasks (titles start with "dbtest") and never touches other rows.
 * Refuses to run without MLT_ALLOW_DB_TESTS=1.
 */
if (!defined('ABSPATH') || getenv('MLT_ALLOW_DB_TESTS') !== '1') {
    fwrite(STDERR, "Refusing to run: set MLT_ALLOW_DB_TESTS=1 (test sites only).\n");
    exit(2);
}

$failed = 0;
function check($label, $ok, $detail = '')
{
    global $failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . ($ok ? '' : ' ' . $detail) . "\n";
    $failed += $ok ? 0 : 1;
}
function utc($offset_seconds)
{
    return gmdate('Y-m-d H:i:s', time() + $offset_seconds);
}

global $wpdb;
$p = $wpdb->prefix;
$user = get_current_user_id();
check('running as a real user', $user > 0);

$mk = function ($title, array $extra = []) use ($wpdb, $p, $user) {
    $wpdb->insert("{$p}mlt_tasks", $extra + ['title' => $title, 'description' => '', 'owner' => 'you', 'kind' => 'new', 'estimate_minutes' => 60, 'estimate_original' => 60, 'created_by' => $user, 'created_at' => utc(0), 'updated_by' => $user, 'updated_at' => utc(0)]);
    $id = $wpdb->insert_id;
    MLT_Taskmeta::set_assignees($id, [], 'you');
    MLT_Taskmeta::assign_code($id, 'you');
    return $id;
};
$entry = function ($task, $start, $end, $reason = null, $final = 0) use ($wpdb, $p, $user) {
    $wpdb->insert("{$p}mlt_time_entries", ['task_id' => $task, 'user' => $user, 'started_at' => utc($start), 'ended_at' => $end === null ? null : utc($end), 'pause_reason' => $reason, 'is_final' => $final]);
};
$created = [];
$clean = function () use (&$created, $wpdb, $p) {
    foreach ($created as $id) {
        foreach (['mlt_comments', 'mlt_task_assignees', 'mlt_estimate_extensions', 'mlt_time_entries', 'mlt_scores'] as $t) {
            $wpdb->delete($p . $t, ['task_id' => $id]);
        }
        $wpdb->delete("{$p}mlt_tasks", ['id' => $id]);
    }
};

echo "== timer: when the panel should ask\n";
$wpdb->query($wpdb->prepare("DELETE FROM {$p}mlt_time_entries WHERE user = %d AND is_final = 0", $user));
$t1 = $mk('dbtest timer'); $created[] = $t1;

$entry($t1, -5 * 3600, null);                       // running for 5 hours
$a = MLT_Timer::active($user);
check('running 5 h -> overlong', $a['state'] === 'running' && MLT_Timer::attention($a) === 'overlong');
$wpdb->query("DELETE FROM {$p}mlt_time_entries WHERE task_id = $t1");

$entry($t1, -3600, null);                           // running for 1 hour: fine (unless it crossed midnight UTC)
$a = MLT_Timer::active($user);
$crossed = gmdate('Y-m-d', time() - 3600) !== gmdate('Y-m-d');
check('running 1 h -> ' . ($crossed ? 'overlong (crossed UTC midnight)' : 'no question'), MLT_Timer::attention($a) === ($crossed ? 'overlong' : null));
$wpdb->query("DELETE FROM {$p}mlt_time_entries WHERE task_id = $t1");

$entry($t1, -2 * 86400, null);                      // started two days ago: crossed midnight
check('started 2 days ago -> overlong (midnight)', MLT_Timer::attention(MLT_Timer::active($user)) === 'overlong');
$wpdb->query("DELETE FROM {$p}mlt_time_entries WHERE task_id = $t1");

$entry($t1, -3600, -2400, 'food');                  // paused 40 minutes ago
$a = MLT_Timer::active($user);
check('paused 40 min -> long_pause, not stopped', $a['state'] === 'paused' && MLT_Timer::attention($a) === 'long_pause');
$wpdb->query("DELETE FROM {$p}mlt_time_entries WHERE task_id = $t1");

$entry($t1, -3600, -1500, 'food');                  // paused 25 minutes ago
check('paused 25 min -> no question', MLT_Timer::attention(MLT_Timer::active($user)) === null);
$wpdb->query("DELETE FROM {$p}mlt_time_entries WHERE task_id = $t1");

echo "== timer: the arithmetic (fixed clock)\n";
$now = time();
$base = gmdate('Y-m-d H:i:s', $now - 10000);
$at = function ($sec) use ($now) { return gmdate('Y-m-d H:i:s', $now - 10000 + $sec); };
$rows = [
    ['started_at' => $at(0),    'ended_at' => $at(600),  'pause_reason' => 'food',        'is_final' => 0],  // 10 min work, pause 5 min
    ['started_at' => $at(900),  'ended_at' => $at(1500), 'pause_reason' => 'interrupted', 'is_final' => 0],  // 10 min work, pause 10 min
    ['started_at' => $at(2100), 'ended_at' => $at(2400), 'pause_reason' => 'other_task',   'is_final' => 0],  //  5 min work, pause 20 min
    ['started_at' => $at(3600), 'ended_at' => $at(4200), 'pause_reason' => null,           'is_final' => 1],  // 10 min work, end
];
$s = MLT_Timer::summarize($rows, $now);
check('net = 35 min', $s['net_seconds'] === 2100, json_encode($s));
check('total = first start .. last end = 70 min', $s['total_seconds'] === 4200, json_encode($s));
check('pause = total - net = 35 min', $s['pause_seconds'] === 2100 && $s['total_seconds'] - $s['net_seconds'] === $s['pause_seconds'], json_encode($s));
check('3 pauses, each reason apart (interrupted and other_task separate)', $s['pause_count'] === 3 && $s['by_reason']['interrupted']['seconds'] === 600 && $s['by_reason']['other_task']['seconds'] === 1200 && $s['by_reason']['food']['seconds'] === 300, json_encode($s['by_reason']));
$open = array_slice($rows, 0, 1);
$s = MLT_Timer::summarize($open, $now);
check('an open pause counts up to now', $s['pause_seconds'] === 10000 - 600 && $s['total_seconds'] === 10000, json_encode($s));

$clean();
echo $failed ? "\n$failed check(s) FAILED\n" : "\nall db scenarios passed\n";
exit($failed ? 1 : 0);
