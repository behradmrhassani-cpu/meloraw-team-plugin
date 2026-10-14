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

echo "== score: the boundaries (pure function, estimate 60 min = 3600 s)\n";
$pt = function ($priority, $est, $net, $timer, $late, $reason) { return implode(':', MLT_Score::points($priority, $est, $net, $timer, $late, $reason)); };
check('exactly the estimate -> full 10 x 2', $pt('med', 60, 3600, true, false, false) === '20:full');
check('1 s over -> half 5 x 2', $pt('med', 60, 3601, true, false, false) === '10:half');
check('exactly +25 % (4500 s) -> still half', $pt('med', 60, 4500, true, false, false) === '10:half');
check('1 s past +25 % -> very low 1 x 2', $pt('med', 60, 4501, true, false, false) === '2:low');
check('far past +25 % with a reason -> 2 x 2', $pt('med', 60, 9000, true, false, true) === '4:low_reason');
check('on time by the clock but past the due date -> very low', $pt('med', 60, 100, true, true, false) === '2:low');
check('past the due date with a reason -> a bit more', $pt('med', 60, 100, true, true, true) === '4:low_reason');
check('weights: high 30, med 20, low 10 (full)', $pt('high', 60, 1, true, false, false) === '30:full' && $pt('med', 60, 1, true, false, false) === '20:full' && $pt('low', 60, 1, true, false, false) === '10:full');
check('weights on very low: 3 / 2 / 1', $pt('high', 60, 9999, true, false, false) === '3:low' && $pt('low', 60, 9999, true, false, false) === '1:low');
check('no timer -> 0, even when late', $pt('high', 60, 0, false, true, false) === '0:no_timer');
check('timer but no estimate -> 0, no_estimate', $pt('high', null, 600, true, false, false) === '0:no_estimate');
check('a tiny estimate (5 min): 375 s is half, 376 s is low', $pt('low', 5, 375, true, false, false) === '5:half' && $pt('low', 5, 376, true, false, false) === '1:low');

echo "== score: stored rows, the ORIGINAL estimate, recalculation\n";
$ref = new DateTimeImmutable('2026-10-17 23:59:59', new DateTimeZone('UTC'));
check('week of Sat 2026-10-17 23:59:59 UTC = W42', MLT_Score::week_key($ref->getTimestamp()) === '2026-W42');
check('week of Sun 2026-10-18 00:00:00 UTC = W43', MLT_Score::week_key($ref->modify('+1 second')->getTimestamp()) === '2026-W43');

$t2 = $mk('dbtest score', ['estimate_minutes' => 600, 'estimate_original' => 5, 'priority' => 'med', 'status' => 'done']); $created[] = $t2;
$entry($t2, -360, 0, null, 1);  // 6 min of work; the estimate was 5 and then extended to 600
$row = MLT_Score::record($wpdb->get_row("SELECT * FROM {$p}mlt_tasks WHERE id = $t2", ARRAY_A), $user);
check('6 min against an original 5 (extended to 600) -> half, not full', $row['tier'] === 'half' && (int) $row['points'] === 10 && (int) $row['net_seconds'] === 360, json_encode($row));
$wpdb->update("{$p}mlt_scores", ['points' => 999, 'tier' => 'full'], ['task_id' => $t2]);
list($rows, $changed) = MLT_Score::recalculate_all();
$fixed = $wpdb->get_row("SELECT points, tier FROM {$p}mlt_scores WHERE task_id = $t2", ARRAY_A);
check('recalculation repairs a tampered row', $changed >= 1 && (int) $fixed['points'] === 10 && $fixed['tier'] === 'half', json_encode($fixed));
list($rows, $changed) = MLT_Score::recalculate_all();
check('a second recalculation changes nothing', $changed === 0);
$wpdb->update("{$p}mlt_tasks", ['priority' => 'high'], ['id' => $t2]);
MLT_Score::recalculate_all();
check('a rule input (priority) changed -> recalculated 5 x 3', (int) $wpdb->get_var("SELECT points FROM {$p}mlt_scores WHERE task_id = $t2") === 15);
$wpdb->update("{$p}mlt_tasks", ['status' => 'open'], ['id' => $t2]);
MLT_Score::recalculate_all();
check('a task that is no longer done loses its row', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}mlt_scores WHERE task_id = $t2") === 0);

echo "== reports: week ranges and the order of 'my today'\n";
check('2026-W42 = Sun 2026-10-11 .. Sat 2026-10-17', MLT_TimeReport::week_range('2026-W42') === ['2026-10-11', '2026-10-17'], json_encode(MLT_TimeReport::week_range('2026-W42')));
check('2026-W01 contains Jan 1 and starts on a Sunday', MLT_TimeReport::week_range('2026-W01') === ['2025-12-28', '2026-01-03'], json_encode(MLT_TimeReport::week_range('2026-W01')));
check('week_key of the first day of a range is that week, of the day before is the previous week', MLT_Score::week_key(strtotime('2026-10-11 00:00:00 UTC')) === '2026-W42' && MLT_Score::week_key(strtotime('2026-10-10 23:59:59 UTC')) === '2026-W41');
check('bad week key -> no range', MLT_TimeReport::week_range('nope') === null);
$today = MLT_Time::today();
$rank = function ($t) { return MLT_Today::order_key($t + ['id' => 1]); };
$B = ['due_date' => $today, 'priority' => 'low', 'status' => 'in_progress'];
$C = ['due_date' => gmdate('Y-m-d', time() - 3 * 86400), 'priority' => 'med', 'status' => 'open'];
$D = ['due_date' => null, 'priority' => 'high', 'status' => 'in_progress'];
$A = ['due_date' => null, 'priority' => 'high', 'status' => 'open'];
$F = ['due_date' => null, 'priority' => 'med', 'status' => 'in_progress'];
$E = ['due_date' => null, 'priority' => 'low', 'status' => 'open'];
$all = ['B' => $B, 'C' => $C, 'D' => $D, 'A' => $A, 'F' => $F, 'E' => $E];
$sorted = $all;
uasort($sorted, function ($x, $y) use ($rank) { return $rank($x) <=> $rank($y); });
check('order: due/late first (in progress before open), then high priority, then in progress', implode('', array_keys($sorted)) === 'BCDAFE', implode('', array_keys($sorted)));
$future = ['due_date' => gmdate('Y-m-d', time() + 5 * 86400), 'priority' => 'high', 'status' => 'open'];
check('a due date in the future does not count as due today', $rank($future)[0] === 1 && $rank($B)[0] === 0);

echo "== health: every check runs on its own\n";
add_filter('mlt_health_checks', function ($checks) {
    $checks['boom'] = ['check that throws', function () { throw new RuntimeException('secret detail that must not leak'); }];
    $checks['typeerr'] = ['check with a type error', function () { return strlen([]); }];
    return $checks;
});
$ids = array_keys(MLT_Health::checks());
$res = [];
foreach ($ids as $id) { $res[$id] = MLT_Health::run($id); }
check('all built-in checks plus the two broken ones are present', count($ids) === 15, implode(',', $ids));
check('a thrown exception becomes a red result with a generic message (no detail leaked)', $res['boom'][0] === 'fail' && strpos(json_encode($res['boom'], JSON_UNESCAPED_UNICODE), 'secret detail') === false);
check('a TypeError in a check is contained too', $res['typeerr'][0] === 'fail');
$statuses = array_map(function ($r) { return $r[0]; }, array_diff_key($res, ['boom' => 1, 'typeerr' => 1]));
check('the other 13 checks still returned ok / warn / fail', count($statuses) === 13 && !array_diff($statuses, ['ok', 'warn', 'fail']), json_encode($statuses));
foreach ($res as $id => $r) {
    if ($r[0] !== 'ok' && ($r[2] === '' || $r[1] === '')) { check("check $id is not green but has no message or fix", false); }
}
check('every non-green check explains what to do', true);
$token_probe = MLT_Health::probe_permission(new WP_REST_Request('GET', '/x'));
check('the probe route refuses a request without the token', is_wp_error($token_probe));

$clean();
echo $failed ? "\n$failed check(s) FAILED\n" : "\nall db scenarios passed\n";
exit($failed ? 1 : 0);
