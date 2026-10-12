<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activity log page (read only) and the masking applied to log text.
 * The log never holds comment text; mask() is the second line of defence so a token or password
 * pasted somewhere (e.g. into a task title) is neither stored in the log nor shown on this page.
 */
class MLT_Log
{
    const PER_PAGE = 50;

    const ACTIONS = [
        'task_create'     => 'ساخت تسک',
        'task_edit'       => 'ویرایش تسک',
        'status_change'   => 'تغییر وضعیت',
        'task_delete'     => 'حذف تسک',
        'comment_add'     => 'کامنت',
        'comment_flag'    => 'پرچم کامنت',
        'comment_convert' => 'تبدیل کامنت به تسک',
        'routine_status'  => 'وضعیت روتین',
        'import'          => 'ایمپورت',
        'export'          => 'خروجی JSON',
        'settings_update' => 'تنظیمات',
        'telegram_notify' => 'ارسال به تلگرام',
        'telegram_error'  => 'خطای تلگرام',
    ];

    /** Replaces anything that looks like a token, key or password with [***]. */
    public static function mask($text)
    {
        $text = preg_replace([
            '/\b\d{5,15}:[A-Za-z0-9_-]{30,}/',                // Telegram bot token
            '/\bAIza[0-9A-Za-z_-]{20,}/',                      // Google API key
            '/\bsk-[A-Za-z0-9_-]{16,}/',                       // sk-... keys
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i',           // Authorization header value
        ], '[***]', (string) $text);
        // WordPress Application Password: 6 groups of 4. Real ones are random mixed case with digits, so ordinary
        // words ("this will test over many very") are kept: it needs a digit or a capital inside a group.
        $text = preg_replace_callback('/\b(?:[A-Za-z0-9]{4} ){5}[A-Za-z0-9]{4}\b/', function ($m) {
            return preg_match('/\d|(?<=[A-Za-z0-9])[A-Z]/', $m[0]) ? '[***]' : $m[0];
        }, $text);
        return preg_replace('/\b(pass(?:word)?|passwd|secret|token|api[_-]?key|authorization)(\s*[:=]\s*)[^\s»]+/i', '$1$2[***]', $text); // stops at » so the summary's own quoting survives
    }

    /** Filters from the query string; anything invalid is ignored. */
    private static function filters()
    {
        $get = function ($name) {
            return isset($_GET[$name]) && is_string($_GET[$name]) ? sanitize_text_field(wp_unslash($_GET[$name])) : '';
        };
        $date = function ($value) {
            return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
        };
        $action = $get('action');
        return [
            'user'   => absint($get('user')),
            'action' => preg_match('/^[a-z_]{1,40}$/', $action) ? $action : '',
            'from'   => $date($get('from')),
            'to'     => $date($get('to')),
        ];
    }

    public static function render()
    {
        if (!current_user_can(MLT_CAP)) {
            wp_die('دسترسی ندارید.');
        }
        global $wpdb;
        $t = $wpdb->prefix . 'mlt_activity_log';
        $f = self::filters();

        $where = [];
        $args = [];
        if ($f['user']) {
            $where[] = 'user = %d';
            $args[] = $f['user'];
        }
        if ($f['action'] !== '') {
            $where[] = 'action = %s';
            $args[] = $f['action'];
        }
        if ($f['from'] !== '') {
            $where[] = 'created_at >= %s';
            $args[] = MLT_Time::day_bounds($f['from'])[0]; // the day filter is a Tehran day, like the times shown
        }
        if ($f['to'] !== '') {
            $where[] = 'created_at <= %s';
            $args[] = MLT_Time::day_bounds($f['to'])[1];
        }
        $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t$clause", $args)) : $wpdb->get_var("SELECT COUNT(*) FROM $t"));
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $paged = min($pages, max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1)); // negative or junk -> page 1
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t$clause ORDER BY id DESC LIMIT %d OFFSET %d",
            array_merge($args, [self::PER_PAGE, ($paged - 1) * self::PER_PAGE])
        ), ARRAY_A);

        $user_ids = array_map('intval', $wpdb->get_col("SELECT DISTINCT user FROM $t ORDER BY user"));
        $actions = $wpdb->get_col("SELECT DISTINCT action FROM $t ORDER BY action");
        cache_users(array_unique(array_merge($user_ids, array_map('intval', wp_list_pluck($rows, 'user')))));
        $name = function ($id) {
            $u = get_userdata((int) $id);
            return $u ? $u->display_name : 'کاربر حذف‌شده';
        };
        $base = ['page' => MLT_Admin::LOG_SLUG] + array_filter($f);
        $link = function ($page) use ($base) {
            return esc_url(add_query_arg(['paged' => $page] + $base, admin_url('admin.php')));
        };
        ?>
        <div class="mlt" dir="rtl">
            <header class="mlt-head">
                <h1>لاگ فعالیت</h1>
            </header>
            <form method="get" class="mlt-filters mlt-logfilters">
                <input type="hidden" name="page" value="<?php echo esc_attr(MLT_Admin::LOG_SLUG); ?>">
                <select name="user" aria-label="کاربر">
                    <option value="">همه‌ی کاربرها</option>
                    <?php foreach ($user_ids as $id) : ?>
                        <option value="<?php echo (int) $id; ?>"<?php selected($f['user'], $id); ?>><?php echo esc_html($name($id)); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="action" aria-label="نوع عمل">
                    <option value="">همه‌ی عمل‌ها</option>
                    <?php foreach ($actions as $a) : ?>
                        <option value="<?php echo esc_attr($a); ?>"<?php selected($f['action'], $a); ?>><?php echo esc_html(self::ACTIONS[$a] ?? $a); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="mlt-date">از <input type="date" name="from" value="<?php echo esc_attr($f['from']); ?>"></label>
                <label class="mlt-date">تا <input type="date" name="to" value="<?php echo esc_attr($f['to']); ?>"></label>
                <button class="mlt-btn">فیلتر</button>
                <a class="mlt-link" href="<?php echo esc_url(add_query_arg('page', MLT_Admin::LOG_SLUG, admin_url('admin.php'))); ?>">پاک‌کردن فیلتر</a>
            </form>
            <p class="mlt-hint"><?php echo esc_html($total); ?> ردیف · تاریخ و زمان (و فیلتر روز) به وقت <code dir="ltr"><?php echo esc_html(MLT_Time::ZONE); ?></code> · متن کامنت‌ها و هر چیزی شبیه توکن یا رمز در لاگ نمایش داده نمی‌شود.</p>
            <div class="mlt-tablewrap">
                <table class="mlt-table">
                    <thead>
                        <tr><th>زمان (<span dir="ltr"><?php echo esc_html(MLT_Time::ZONE); ?></span>)</th><th>کاربر</th><th>ایجنت</th><th>عمل</th><th>تسک</th><th>شرح</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows) : ?>
                            <tr><td colspan="6" class="mlt-empty">ردیفی پیدا نشد.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r) : ?>
                            <tr>
                                <td dir="ltr"><?php echo esc_html(MLT_Time::format($r['created_at'], 'Y/m/d H:i:s')); ?></td>
                                <td><?php echo esc_html($name($r['user'])); ?></td>
                                <td dir="ltr"><?php echo $r['agent'] !== null && $r['agent'] !== '' ? esc_html(self::mask($r['agent'])) : '—'; ?></td>
                                <td><?php echo esc_html(self::ACTIONS[$r['action']] ?? self::mask($r['action'])); ?></td>
                                <td dir="ltr"><?php echo $r['task_id'] !== null ? '#' . esc_html($r['task_id']) : '—'; ?></td>
                                <td><?php echo esc_html(self::mask($r['summary'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pages > 1) : ?>
                <nav class="mlt-pager">
                    <?php if ($paged > 1) : ?><a class="mlt-btn" href="<?php echo $link($paged - 1); ?>">قبلی</a><?php endif; ?>
                    <span>صفحه <?php echo esc_html($paged); ?> از <?php echo esc_html($pages); ?></span>
                    <?php if ($paged < $pages) : ?><a class="mlt-btn" href="<?php echo $link($paged + 1); ?>">بعدی</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
        <?php
    }
}
