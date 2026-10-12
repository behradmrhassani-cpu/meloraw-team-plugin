<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Time and points report: net work time, pause time, number of pauses, pauses by reason ("interrupted" and
 * "other task" kept apart) and the comparison with the original estimate. Plain SVG / CSS, no library.
 * A person's numbers are visible to that person and to administrators.
 */
class MLT_TimeReport
{
    const SPEED_META = 'mlt_hide_speed';

    public static function init()
    {
        add_action('admin_post_mlt_report_action', [__CLASS__, 'handle_action']);
    }

    /** [first date, last date] ("Y-m-d") of a week key like 2026-W42, with the routines' week numbering (week starts on Sunday). */
    public static function week_range($week)
    {
        if (!preg_match('/^(\d{4})-W(\d{2})$/', $week, $m)) {
            return null;
        }
        $jan1 = new DateTimeImmutable($m[1] . '-01-01', new DateTimeZone('UTC'));
        $offset = ((int) $m[2] - 1) * 7 - (int) $jan1->format('w');
        $start = $jan1->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
        return [$start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d')];
    }

    public static function fmt($seconds)
    {
        $seconds = max(0, (int) $seconds);
        if ($seconds >= 3600) {
            return intdiv($seconds, 3600) . 'h ' . sprintf('%02d', intdiv($seconds % 3600, 60)) . 'm';
        }
        return $seconds >= 60 ? intdiv($seconds, 60) . 'm ' . sprintf('%02d', $seconds % 60) . 's' : $seconds . 's';
    }

    /** One horizontal bar as an inline SVG (no script, no library). */
    private static function bar($value, $max, $class, $label)
    {
        $w = $max > 0 ? min(100, round($value / $max * 100, 1)) : 0;
        return '<svg viewBox="0 0 100 10" preserveAspectRatio="none" role="img" aria-label="' . esc_attr($label) . '"><rect class="bar-bg" width="100" height="10" rx="2"></rect><rect class="' . esc_attr($class) . '" width="' . esc_attr($w) . '" height="10" rx="2"></rect></svg>';
    }

    /** Motivating label from net time / original estimate over the finished, timed tasks of the week. */
    public static function speed_label($ratio)
    {
        if ($ratio <= 1.0) {
            return '🐇 خرگوش — مطابق تخمین یا سریع‌تر';
        }
        if ($ratio <= 1.25) {
            return '🚶 نزدیک تخمین — کمی بیشتر از برنامه';
        }
        return '🐢 لاک‌پشت — این هفته کارها بیشتر از تخمین طول کشید';
    }

    public static function render()
    {
        if (!current_user_can(MLT_CAP)) {
            wp_die('دسترسی ندارید.');
        }
        global $wpdb;
        $p = $wpdb->prefix;
        $me = wp_get_current_user();

        $who = isset($_GET['user']) && is_string($_GET['user']) ? sanitize_user(wp_unslash($_GET['user']), true) : $me->user_login;
        $target = get_user_by('login', $who) ?: $me;
        if (!MLT_Score::can_see($target->ID)) {
            wp_die('فقط خودِ فرد و ادمین‌ها گزارش او را می‌بینند.', 'دسترسی ندارید', ['response' => 403]);
        }
        $own = (int) $target->ID === (int) $me->ID;
        $weeks = [];
        for ($i = 0; $i < 8; $i++) {
            $weeks[MLT_Score::week_key(time() - $i * 7 * DAY_IN_SECONDS)] = true;
        }
        $week = isset($_GET['week']) && is_string($_GET['week']) && preg_match('/^\d{4}-W\d{2}$/', $_GET['week']) ? $_GET['week'] : MLT_Score::week_key(time());
        $weeks[$week] = true;
        krsort($weeks);
        list($from, $to) = self::week_range($week) ?: [MLT_Time::today(), MLT_Time::today()];

        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$p}mlt_time_entries WHERE user = %d AND started_at >= %s AND started_at <= %s ORDER BY id ASC",
            $target->ID,
            $from . ' 00:00:00',
            $to . ' 23:59:59'
        ), ARRAY_A);
        $sum = MLT_Timer::summarize($entries);
        $scores = MLT_Score::rows($week, (int) $target->ID);
        $points = array_sum(array_map('intval', wp_list_pluck($scores, 'points')));

        // per task: all of this person's time on it (not only this week's), against the ORIGINAL estimate
        $task_ids = array_values(array_unique(array_map('intval', array_merge(wp_list_pluck($entries, 'task_id'), wp_list_pluck($scores, 'task_id')))));
        $tasks = [];
        if ($task_ids) {
            $in = implode(',', array_fill(0, count($task_ids), '%d'));
            foreach ($wpdb->get_results($wpdb->prepare("SELECT id, code, title, estimate_original, status FROM {$p}mlt_tasks WHERE id IN ($in)", $task_ids), ARRAY_A) as $t) {
                $tasks[(int) $t['id']] = $t;
            }
        }
        $rows = [];
        foreach ($task_ids as $tid) {
            if (!isset($tasks[$tid])) {
                continue;
            }
            $s = MLT_Timer::summarize(MLT_Timer::entries_of_task($tid, (int) $target->ID));
            $rows[] = $tasks[$tid] + ['net' => $s['net_seconds'], 'pause' => $s['pause_seconds'], 'total' => $s['total_seconds']];
        }
        usort($rows, function ($a, $b) {
            return $b['net'] <=> $a['net'];
        });
        $max_task = max([1] + array_map(function ($r) {
            return max($r['net'], (int) $r['estimate_original'] * 60);
        }, $rows));

        // speed over the finished tasks of the week that had a timer and an estimate
        $timed = array_filter($scores, function ($s) {
            return (int) $s['has_timer'] && $s['estimate_original'] !== null && (int) $s['estimate_original'] > 0;
        });
        $net_done = array_sum(array_map('intval', wp_list_pluck($timed, 'net_seconds')));
        $est_done = array_sum(array_map('intval', wp_list_pluck($timed, 'estimate_original'))) * 60;
        $show_speed = $own && $est_done > 0 && !get_user_meta($me->ID, self::SPEED_META, true);

        // points of the last weeks
        $history = [];
        foreach (array_keys($weeks) as $w) {
            $history[$w] = array_sum(array_map('intval', wp_list_pluck(MLT_Score::rows($w, (int) $target->ID), 'points')));
        }
        $max_points = max([1] + $history);
        $max_pause = max([1] + array_column($sum['by_reason'], 'seconds'));
        $page_url = function (array $args) {
            return esc_url(add_query_arg(['page' => MLT_Admin::TIME_SLUG] + $args, admin_url('admin.php')));
        };
        ?>
        <div class="mlt" dir="rtl">
            <?php MLT_Admin::topbar(); ?>
            <header class="mlt-head">
                <h1>زمان و امتیاز <span class="mlt-count"><?php echo esc_html($target->display_name); ?></span></h1>
                <form method="get" class="mlt-filters">
                    <input type="hidden" name="page" value="<?php echo esc_attr(MLT_Admin::TIME_SLUG); ?>">
                    <?php if (current_user_can('manage_options')) : ?>
                        <select name="user" aria-label="کاربر">
                            <?php foreach (MLT_Settings::team_accounts() as $a) : ?>
                                <option value="<?php echo esc_attr($a->user_login); ?>"<?php selected($a->ID, $target->ID); ?>><?php echo esc_html($a->display_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <select name="week" aria-label="هفته" dir="ltr">
                        <?php foreach (array_keys($weeks) as $w) : ?>
                            <option value="<?php echo esc_attr($w); ?>"<?php selected($w, $week); ?>><?php echo esc_html($w); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="mlt-btn mlt-btn--ghost">نمایش</button>
                </form>
            </header>
            <?php MLT_Admin::render_notice(); ?>
            <p class="mlt-hint" style="margin-bottom:20px">هفته <code dir="ltr"><?php echo esc_html($week); ?></code> از <code dir="ltr"><?php echo esc_html($from); ?></code> تا <code dir="ltr"><?php echo esc_html($to); ?></code> (UTC، هفته از یکشنبه شروع می‌شود). زمان‌ها از تایمر خودِ فرد آمده؛ تخمین‌ها هرگز از روی زمان واقعی عوض نمی‌شوند.</p>

            <div class="mlt-stats">
                <div class="mlt-stat"><b dir="ltr"><?php echo esc_html(self::fmt($sum['net_seconds'])); ?></b><span>زمان خالص کار</span></div>
                <div class="mlt-stat"><b dir="ltr"><?php echo esc_html(self::fmt($sum['pause_seconds'])); ?></b><span>زمان پاز</span></div>
                <div class="mlt-stat"><b><?php echo esc_html($sum['pause_count']); ?></b><span>تعداد پاز</span></div>
                <div class="mlt-stat"><b><?php echo esc_html(count($scores)); ?></b><span>تسک انجام‌شده</span></div>
                <div class="mlt-stat"><b><?php echo esc_html($points); ?></b><span>امتیاز هفته</span></div>
            </div>
            <?php if ($show_speed) : ?>
                <p class="mlt-speed"><?php echo esc_html(self::speed_label($net_done / $est_done)); ?> <span class="mlt-hint" dir="ltr">(<?php echo esc_html(self::fmt($net_done)); ?> / <?php echo esc_html(self::fmt($est_done)); ?>)</span></p>
            <?php endif; ?>

            <h2 class="mlt-sub">پاز بر اساس دلیل</h2>
            <?php if (!$sum['by_reason']) : ?>
                <p class="mlt-empty">در این هفته پازی ثبت نشده.</p>
            <?php else : ?>
                <div class="mlt-bars">
                    <?php foreach (MLT_Timer::REASONS as $key => $label) : $r = $sum['by_reason'][$key] ?? null; if (!$r) { continue; } ?>
                        <span><?php echo esc_html($label); ?></span>
                        <?php echo self::bar($r['seconds'], $max_pause, in_array($key, ['interrupted', 'other_task'], true) ? 'bar-b' : 'bar-a', $label); ?>
                        <small dir="ltr"><?php echo esc_html($r['count'] . '× · ' . self::fmt($r['seconds'])); ?></small>
                    <?php endforeach; ?>
                </div>
                <div class="mlt-legend"><span><i style="background:var(--gold)"></i>پازهای عادی</span><span><i style="background:var(--dim)"></i>قطع شدن و کار دیگر (جدا شمرده می‌شوند)</span></div>
            <?php endif; ?>

            <h2 class="mlt-sub">زمان خالص در برابر تخمین اولیه</h2>
            <?php if (!$rows) : ?>
                <p class="mlt-empty">در این هفته زمانی ثبت نشده.</p>
            <?php else : ?>
                <div class="mlt-bars">
                    <?php foreach ($rows as $r) : $est = $r['estimate_original'] === null ? null : (int) $r['estimate_original'] * 60; ?>
                        <span><a class="mlt-code-link" dir="ltr" href="<?php echo esc_url(add_query_arg(['page' => MLT_Admin::SLUG, 'view' => 'all', 'comments' => (int) $r['id']], admin_url('admin.php')) . '#mlt-t-' . (int) $r['id']); ?>"><?php echo esc_html($r['code'] ?: '#' . $r['id']); ?></a> <?php echo esc_html(wp_trim_words($r['title'], 5)); ?></span>
                        <span>
                            <?php echo self::bar($r['net'], $max_task, 'bar-a', 'زمان خالص'); ?>
                            <?php if ($est !== null) : echo self::bar($est, $max_task, 'bar-b', 'تخمین اولیه'); endif; ?>
                        </span>
                        <small dir="ltr"><?php echo esc_html(self::fmt($r['net']) . ($est !== null ? ' / ' . self::fmt($est) : ' / —')); ?></small>
                    <?php endforeach; ?>
                </div>
                <div class="mlt-legend"><span><i style="background:var(--gold)"></i>زمان خالص</span><span><i style="background:var(--dim)"></i>تخمین اولیه</span></div>
            <?php endif; ?>

            <h2 class="mlt-sub">امتیاز در هفته‌های اخیر</h2>
            <div class="mlt-bars">
                <?php foreach ($history as $w => $pts) : ?>
                    <span dir="ltr"><?php echo esc_html($w); ?></span>
                    <?php echo self::bar($pts, $max_points, 'bar-a', $w); ?>
                    <small><?php echo esc_html($pts); ?></small>
                <?php endforeach; ?>
            </div>

            <h2 class="mlt-sub">تسک‌های انجام‌شده‌ی این هفته</h2>
            <?php if (!$scores) : ?>
                <p class="mlt-empty">تسکی با امتیاز ثبت نشده.</p>
            <?php else : ?>
                <div class="mlt-tablewrap"><table class="mlt-table">
                    <thead><tr><th>کد</th><th>عنوان</th><th>زمان خالص</th><th>تخمین اولیه</th><th>سررسید</th><th>امتیاز</th></tr></thead>
                    <tbody>
                        <?php foreach ($scores as $s) : ?>
                            <tr>
                                <td dir="ltr"><?php echo esc_html($s['code']); ?></td>
                                <td><?php echo esc_html($s['title']); ?><?php echo (int) $s['late'] ? ' <span class="mlt-badge mlt-badge--late">با تأخیر</span>' : ''; ?><?php echo $s['late_reason'] ? ' <span class="mlt-hint">— ' . esc_html($s['late_reason']) . '</span>' : ''; ?></td>
                                <td dir="ltr"><?php echo (int) $s['has_timer'] ? esc_html(self::fmt($s['net_seconds'])) : '—'; ?></td>
                                <td dir="ltr"><?php echo $s['estimate_original'] === null ? '—' : esc_html(MLT_Board::minutes($s['estimate_original'])); ?></td>
                                <td dir="ltr"><?php echo esc_html($s['due_date'] ?? '—'); ?></td>
                                <td><?php echo (int) $s['has_timer'] ? '+' . esc_html((int) $s['points']) . ' <span class="mlt-hint">' . esc_html(MLT_Score::TIERS[$s['tier']] ?? '') . '</span>' : '<span class="mlt-badge mlt-badge--notimer">بدون زمان‌سنج</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>

            <?php if (current_user_can('manage_options')) : ?>
                <h2 class="mlt-sub">امتیاز این هفته‌ی همه‌ی اعضا</h2>
                <?php $team = []; foreach (MLT_Score::rows($week) as $s) { $uid = (int) $s['user']; $team[$uid] = ($team[$uid] ?? 0) + (int) $s['points']; } ?>
                <div class="mlt-bars">
                    <?php foreach (MLT_Settings::team_accounts() as $a) : $pts = $team[$a->ID] ?? 0; ?>
                        <span><?php echo esc_html($a->display_name); ?></span>
                        <?php echo self::bar($pts, max([1] + $team), 'bar-a', $a->display_name); ?>
                        <small><?php echo esc_html($pts); ?></small>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="mlt-rtools" style="margin-top:24px">
                <?php if ($own) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mlt-inline">
                        <input type="hidden" name="action" value="mlt_report_action">
                        <input type="hidden" name="do" value="<?php echo get_user_meta($me->ID, self::SPEED_META, true) ? 'speed_show' : 'speed_hide'; ?>">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_report_action')); ?>">
                        <button type="submit" class="mlt-btn mlt-btn--ghost mlt-btn--sm"><?php echo get_user_meta($me->ID, self::SPEED_META, true) ? 'نمایش برچسب سرعت' : 'خاموش کردن برچسب سرعت'; ?></button>
                    </form>
                <?php endif; ?>
                <?php if (current_user_can('manage_options')) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mlt-inline">
                        <input type="hidden" name="action" value="mlt_report_action">
                        <input type="hidden" name="do" value="recalc">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_report_action')); ?>">
                        <button type="submit" class="mlt-btn mlt-btn--ghost mlt-btn--sm" onclick="return confirm('همه‌ی امتیازها از روی داده‌ی تسک و زمان‌ها دوباره حساب می‌شود. ادامه؟');">بازمحاسبه‌ی امتیازها</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public static function handle_action()
    {
        MLT_Admin::verify('mlt_report_action');
        $do = isset($_POST['do']) && is_string($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $uid = get_current_user_id();
        if ($do === 'speed_hide') {
            update_user_meta($uid, self::SPEED_META, 1);
            $msg = 'برچسب سرعت خاموش شد.';
        } elseif ($do === 'speed_show') {
            delete_user_meta($uid, self::SPEED_META);
            $msg = 'برچسب سرعت روشن شد.';
        } elseif ($do === 'recalc' && current_user_can('manage_options')) {
            list($rows, $changed) = MLT_Score::recalculate_all();
            $msg = sprintf('امتیازها دوباره حساب شد (%d ردیف، %d تغییر).', $rows, $changed);
        } else {
            MLT_Admin::redirect_with(['type' => 'error', 'msg' => 'انجام نشد: عمل نامعتبر است.'], MLT_Admin::TIME_SLUG, [], '');
        }
        MLT_Admin::redirect_with(['type' => 'ok', 'msg' => $msg], MLT_Admin::TIME_SLUG, [], '');
    }
}
