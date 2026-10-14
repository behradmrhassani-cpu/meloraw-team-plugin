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

$GLOBALS['failed'] = 0; // wp eval-file runs this file inside a function, so a plain $failed here would not be the global one check() counts in
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

echo "== database time zone: the plugin must not depend on it (3.5 h, -5 h and +13 h zones)\n";
$near = function ($value, $ts = null) { return abs((new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->getTimestamp() - ($ts ?? time())) <= 5; };
$call = function ($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/meloraw-tasks/v1' . $route);
    foreach ($body as $k => $v) { $r->set_param($k, $v); }
    $res = rest_do_request($r);
    return [$res->get_status(), $res->get_data()];
};
$orig_zone = $wpdb->get_var('SELECT @@session.time_zone');
foreach (['+03:30', '-05:00', '+13:00'] as $zone) {
    $wpdb->query($wpdb->prepare('SET time_zone = %s', $zone));
    $off = (int) $wpdb->get_var('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())');
    check("[$zone] the session really is in that zone (NOW() - UTC_TIMESTAMP() = $off s)", $off !== 0);

    // the health check: green, mentions the zone, and is not fooled by UNIX_TIMESTAMP(UTC_TIMESTAMP())
    $wrong = abs((int) $wpdb->get_var('SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP())') - time());
    $h = MLT_Health::run('clock');
    check("[$zone] the old way of measuring would have said {$wrong} s apart (the false alarm)", $wrong > 3000);
    check("[$zone] clock check is " . $h[0] . ' (not red) and names the database zone', $h[0] === 'ok' && strpos($h[1], $zone) !== false, json_encode($h, JSON_UNESCAPED_UNICODE));

    // task, timer, comment, done: every stored time is the UTC now
    [$c, $t] = $call('POST', '/tasks', ['title' => "dbtest zone $zone", 'owner' => 'you', 'estimate_minutes' => 30, 'due_date' => gmdate('Y-m-d')]);
    $id = (int) ($t['id'] ?? 0); $created[] = $id;
    check("[$zone] task created ($c)", $c === 201 && $id > 0);
    $row = $wpdb->get_row("SELECT created_at, updated_at FROM {$p}mlt_tasks WHERE id = $id", ARRAY_A);
    check("[$zone] tasks.created_at/updated_at are UTC", $near($row['created_at']) && $near($row['updated_at']), json_encode($row));
    [$c] = $call('POST', "/tasks/$id/timer/start");
    $e = $wpdb->get_row("SELECT started_at FROM {$p}mlt_time_entries WHERE task_id = $id ORDER BY id DESC", ARRAY_A);
    check("[$zone] timer started_at is UTC ($c)", $c === 200 && $near($e['started_at']), json_encode($e));
    [$c] = $call('POST', "/tasks/$id/timer/pause", ['reason' => 'food']);
    [$c2] = $call('POST', "/tasks/$id/timer/resume");
    [$c3, $fin] = $call('POST', "/tasks/$id/timer/finish");
    $e = $wpdb->get_results("SELECT started_at, ended_at FROM {$p}mlt_time_entries WHERE task_id = $id", ARRAY_A);
    $ok = count($e) === 2;
    foreach ($e as $x) { $ok = $ok && $near($x['started_at']) && $near($x['ended_at']); }
    check("[$zone] pause/resume/finish: entries are UTC and the total is seconds, not hours ($c/$c2/$c3)", $ok && ($fin['summary']['net_seconds'] ?? 99999) < 60, json_encode([$e, $fin['summary'] ?? null]));
    [$c, $cm] = $call('POST', "/tasks/$id/comments", ['body' => 'dbtest comment']);
    check("[$zone] comment created_at is UTC", $c === 201 && $near($wpdb->get_var("SELECT created_at FROM {$p}mlt_comments WHERE id = " . (int) $cm['id'])));
    [$c] = $call('PATCH', "/tasks/$id", ['status' => 'done']);
    $sc = $wpdb->get_row("SELECT completed_at, late, tier FROM {$p}mlt_scores WHERE task_id = $id", ARRAY_A);
    check("[$zone] score: completed_at is UTC and a task due today is not late ($c)", $sc && $near($sc['completed_at']) && (int) $sc['late'] === 0, json_encode($sc));
    $log = $wpdb->get_results("SELECT created_at FROM {$p}mlt_activity_log WHERE task_id = $id", ARRAY_A);
    $okl = count($log) >= 4;
    foreach ($log as $l) { $okl = $okl && $near($l['created_at']); }
    check("[$zone] activity log: " . count($log) . ' rows, all UTC', $okl, json_encode($log));
    $today = gmdate('Y-m-d');
    $hit = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}mlt_activity_log WHERE task_id = %d AND created_at >= %s AND created_at <= %s", $id, $today . ' 00:00:00', $today . ' 23:59:59'));
    check("[$zone] the log's day filter (UTC day bounds) finds them", $hit === count($log) || gmdate('H') === '00' || gmdate('H') === '23');
}

// UTC midnight boundaries with a fixed clock, while the session is in a zone where it is already "tomorrow"
$wpdb->query("SET time_zone = '+03:30'");
$late_night = new DateTimeImmutable('2026-10-12 23:00:00', new DateTimeZone('UTC'));   // 2026-10-13 02:30 in Tehran
check('routine: 23:00 UTC is still the 12th (daily key)', MLT_Routines::period_key('daily', $late_night) === '2026-10-12');
$month_end = new DateTimeImmutable('2026-10-31 23:30:00', new DateTimeZone('UTC'));    // 2026-11-01 in Tehran
check('routine: 31 Oct 23:30 UTC is still October (monthly key)', MLT_Routines::period_key('monthly', $month_end) === '2026-10');
$sun_night = new DateTimeImmutable('2026-10-11 22:00:00', new DateTimeZone('UTC'));    // Sunday 22:00 UTC, Monday 01:30 in Tehran
check('score week key follows the UTC week, not the database zone', MLT_Score::week_key($sun_night->getTimestamp()) === MLT_Routines::period_key('weekly', $sun_night));
$ref = new ReflectionMethod('MLT_Score', 'is_late'); $ref->setAccessible(true);
check('score: done at 22:00 UTC on the due day is not late (it would be late in Tehran)', $ref->invoke(null, '2026-10-12', (new DateTimeImmutable('2026-10-12 22:00:00', new DateTimeZone('UTC')))->getTimestamp()) === false);
check('score: done at 00:00:01 UTC the next day is late', $ref->invoke(null, '2026-10-12', (new DateTimeImmutable('2026-10-13 00:00:01', new DateTimeZone('UTC')))->getTimestamp()) === true);
check('clock result: a real PHP-vs-database difference is only a warning, never red', MLT_Health::clock_result(12600, 0, '+00:00')[0] === 'warn' && MLT_Health::clock_result(3, 12600, '+00:00')[0] === 'ok' && MLT_Health::clock_result(0, 0, '+03:30')[0] === 'fail');

// negative control: a row written with the database clock IS off by the zone, so these checks can fail
$wpdb->query("INSERT INTO {$p}mlt_comments (task_id, body, author, flagged, created_at) VALUES (0, 'dbtest control', 0, 0, NOW())");
$ctl = $wpdb->get_var("SELECT created_at FROM {$p}mlt_comments WHERE body = 'dbtest control' ORDER BY id DESC LIMIT 1");
check('negative control: NOW() under +03:30 is ~12600 s away from UTC (the tests above would catch it)', !$near($ctl) && abs((new DateTimeImmutable($ctl, new DateTimeZone('UTC')))->getTimestamp() - time() - 12600) <= 5, $ctl);
$wpdb->query("DELETE FROM {$p}mlt_comments WHERE body = 'dbtest control'");
$wpdb->query($wpdb->prepare('SET time_zone = %s', $orig_zone));

$clean();
$failed = $GLOBALS['failed'];
echo $failed ? "\n$failed check(s) FAILED\n" : "\nall db scenarios passed\n";
exit($failed ? 1 : 0);
