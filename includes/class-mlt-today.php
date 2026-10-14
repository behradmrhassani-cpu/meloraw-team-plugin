<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * "My today": the signed-in person's open tasks in a fixed order - late or due today first, then high priority,
 * then the ones already in progress. The first one to three are the main focus (80 % of the effort), the rest get 20 %.
 */
class MLT_Today
{
    const FOCUS = 3;

    /** Order key: [late/due today first, high priority first, in progress first, earlier due date, newer id]. */
    public static function order_key(array $t)
    {
        $today = MLT_Time::today();
        return [
            $t['due_date'] !== null && $t['due_date'] <= $today ? 0 : 1,
            $t['priority'] === 'high' ? 0 : 1,
            $t['status'] === 'in_progress' ? 0 : 1,
            $t['due_date'] ?? '9999-99-99',
            -(int) $t['id'],
        ];
    }

    /** My open board tasks, in order. */
    public static function tasks($slug)
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$p}mlt_tasks WHERE kind IN ('phase','new') AND status <> 'done' AND id IN (SELECT task_id FROM {$p}mlt_task_assignees WHERE who = %s)",
            $slug
        ), ARRAY_A);
        usort($rows, function ($a, $b) {
            return self::order_key($a) <=> self::order_key($b);
        });
        return $rows;
    }

    public static function render()
    {
        if (!current_user_can(MLT_CAP)) {
            wp_die('دسترسی ندارید.');
        }
        $me = MLT_Identity::current();
        ?>
        <div class="mlt" dir="rtl">
            <?php MLT_Admin::topbar(); ?>
            <header class="mlt-head">
                <h1>امروز من <span class="mlt-count" dir="ltr"><?php echo esc_html(MLT_Time::now()->format('D Y-m-d')); ?> UTC</span></h1>
            </header>
            <?php MLT_Admin::render_notice(); ?>
            <?php if (!$me) : ?>
                <div class="mlt-notice mlt-notice--warn">حساب شما هنوز به هیچ‌یک از اعضای تیم وصل نشده است، برای همین «امروز من» ساخته نمی‌شود. ادمین باید در «تنظیمات ← هویت اعضا» حساب شما را وصل کند. تا آن موقع می‌توانی <a href="<?php echo esc_url(add_query_arg(['page' => MLT_Admin::SLUG, 'view' => 'all'], admin_url('admin.php'))); ?>">همه‌ی تسک‌ها</a> را ببینی.</div>
            </div>
            <?php
            return;
        endif;
        $rows = self::tasks($me);
        $ctx = MLT_Board::context($rows);
        $ctx['back'] = MLT_Admin::TODAY_SLUG;
        $focus = array_slice($rows, 0, self::FOCUS);
        $rest = array_slice($rows, self::FOCUS);
        ?>
            <?php if (!$rows) : ?>
                <p class="mlt-empty">تسک بازی برای تو نیست. 🎉</p>
            <?php endif; ?>
            <?php if ($focus) : ?>
                <section class="mlt-focus">
                    <h2>تمرکز اصلی <span class="mlt-count"><?php echo esc_html(count($focus)); ?></span></h2>
                    <p class="mlt-hint">حدود ۸۰٪ توجهت را بده به این‌ها: اول دیرشده یا سررسیدِ امروز، بعد اولویت بالا، بعد آنچه در حال انجام است.</p>
                    <div class="mlt-today-grid">
                        <?php foreach ($focus as $t) : ?>
                            <?php MLT_Board::card($t, $ctx); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
            <?php if ($rest) : ?>
                <section class="mlt-focus">
                    <h2>بقیه <span class="mlt-count"><?php echo esc_html(count($rest)); ?></span></h2>
                    <p class="mlt-hint">حدود ۲۰٪ باقی‌مانده.</p>
                    <div class="mlt-today-grid">
                        <?php foreach ($rest as $t) : ?>
                            <?php MLT_Board::card($t, $ctx); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
        <?php
    }
}
