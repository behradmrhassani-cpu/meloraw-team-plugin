<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * What sits above the content of every plugin page: the UTC date/clock bar, the admin's announcement, the
 * timer bar (pause / resume / finish, and the two questions the panel asks), the peak-hour hint and the
 * dismissible "why we log time" note. Also the handlers of those little forms.
 */
class MLT_Panel
{
    const WHY_META = 'mlt_why_hidden';
    const ACK_META = 'mlt_timer_ack';
    const ACK_SECONDS = 14400; // "I am still working" silences the overlong question for 4 hours
    const WHY_PAGES = [MLT_Admin::SLUG, MLT_Admin::ROUTINES_SLUG, MLT_Admin::TODAY_SLUG];

    const WHY_TEXT = 'چرا زمان کارهامون رو ثبت می‌کنیم؟ این زمان‌سنج برای کنترل شما نیست. برای اینه که بفهمیم هر کار واقعاً چقدر طول می‌کشه، تیم چقدر و با چه سرعتی رشد می‌کنه و فردا چه کارهایی از دستمون برمیاد. هر کس موظفه زمان کار خودش رو دقیق ثبت کنه، چون نتیجه‌ی همین عددهاست که برنامه‌ریزی و ظرفیت آینده‌ی همه‌ی ما رو می‌سازه. امتیاز مثبت و منفی هم فقط برای این هست که ذهن‌مون به زمان حساس بمونه و کارها وسط ده تا کار دیگه گم نشن. دقیق ثبت کن، پاز رو بزن، دلیلش رو بنویس؛ اینجوری هم زمان خودت درست حساب می‌شه، هم عددهای تیم قابل اعتماد می‌مونه.';

    public static function init()
    {
        add_action('admin_post_mlt_timer', [__CLASS__, 'handle_timer']);
        add_action('admin_post_mlt_banner', [__CLASS__, 'handle_banner']);
    }

    private static function page()
    {
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return in_array($page, MLT_Admin::PAGES, true) ? $page : MLT_Admin::SLUG;
    }

    /** A small POST form (nonce + where to come back to). $fields are hidden inputs. */
    private static function form($action, array $fields, $nonce_action, $inner, $class = '')
    {
        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"<?php echo $class ? ' class="' . esc_attr($class) . '"' : ''; ?>>
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="back" value="<?php echo esc_attr(self::page()); ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce($nonce_action)); ?>">
            <?php foreach ($fields as $name => $value) : ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
            <?php endforeach; ?>
            <?php echo $inner; // built below from escaped pieces only ?>
        </form>
        <?php
        return ob_get_clean();
    }

    // ---- the chrome ----

    public static function render()
    {
        $now = MLT_Time::now();
        $user = get_current_user_id();
        $page = self::page();
        $why_hidden = (bool) get_user_meta($user, self::WHY_META, true);
        ?>
        <div class="mlt-topbar" data-now="<?php echo esc_attr($now->getTimestamp() * 1000); ?>">
            <span class="mlt-clock-date" dir="ltr"><?php echo esc_html($now->format('D Y-m-d')); ?></span>
            <span class="mlt-clock-time" dir="ltr"><?php echo esc_html($now->format('H:i')); ?> UTC</span>
            <span class="mlt-clock-note">همه‌ی زمان‌ها میلادی و UTC+0 هستند — همان زمان انتشار پست‌های سایت</span>
            <?php echo self::form('mlt_banner', ['do' => 'show'], 'mlt_banner', '<button type="submit" class="mlt-linkbtn">چرا زمان ثبت می‌کنیم؟</button>', 'mlt-why-link'); ?>
        </div>
        <?php
        self::announcement();
        self::timer_bar();
        self::peak_hint();
        if (!$why_hidden && in_array($page, self::WHY_PAGES, true)) :
            ?>
            <aside class="mlt-why" id="mlt-why">
                <p><?php echo esc_html(self::WHY_TEXT); ?></p>
                <?php echo self::form('mlt_banner', ['do' => 'hide'], 'mlt_banner', '<button type="submit" class="mlt-x" aria-label="بستن">×</button>', 'mlt-why-close'); ?>
            </aside>
            <?php
        endif;
    }

    private static function announcement()
    {
        $a = MLT_Settings::option('announcement');
        if (!is_array($a) || empty($a['active']) || empty($a['text']) || (!empty($a['expires']) && $a['expires'] < MLT_Time::today())) {
            return;
        }
        echo '<div class="mlt-announce" role="status"><strong>اعلان تیم:</strong> ' . esc_html($a['text']) . '</div>';
    }

    private static function peak_hint()
    {
        $slug = MLT_Identity::current();
        $hours = MLT_Settings::option('peak_hours');
        if ($slug && is_array($hours) && isset($hours[$slug]) && (int) $hours[$slug] === (int) MLT_Time::now()->format('G')) {
            echo '<div class="mlt-peak" role="status">⚡ الان ساعت اوج انرژی توست (' . esc_html(sprintf('%02d:00', (int) $hours[$slug])) . ' UTC). وقت کار روی «تمرکز اصلی» است.</div>';
        }
    }

    private static function timer_bar()
    {
        $user = get_current_user_id();
        $active = MLT_Timer::active($user);
        if (!$active) {
            return;
        }
        global $wpdb;
        $e = $active['entry'];
        $task = $wpdb->get_row($wpdb->prepare("SELECT id, code, title FROM {$wpdb->prefix}mlt_tasks WHERE id = %d", $e['task_id']), ARRAY_A);
        if (!$task) {
            return;
        }
        $sum = MLT_Timer::summarize(MLT_Timer::entries_of_task((int) $task['id'], $user));
        $running = $active['state'] === 'running';
        $attention = MLT_Timer::attention($active);
        $acked = (int) get_user_meta($user, self::ACK_META, true);
        $id = (int) $task['id'];
        $link = add_query_arg(['page' => MLT_Admin::SLUG, 'view' => 'all', 'comments' => $id], admin_url('admin.php')) . '#mlt-t-' . $id;
        ?>
        <div class="mlt-timerbar mlt-timerbar--<?php echo esc_attr($active['state']); ?>">
            <div class="mlt-timer-main">
                <span class="mlt-timer-state"><?php echo $running ? '⏱ روشن' : '⏸ پاز'; ?></span>
                <a class="mlt-code-link" href="<?php echo esc_url($link); ?>" dir="ltr"><?php echo esc_html($task['code'] ?: '#' . $id); ?></a>
                <span class="mlt-timer-title"><?php echo esc_html($task['title']); ?></span>
                <span class="mlt-timer-net" dir="ltr" data-seconds="<?php echo esc_attr($sum['net_seconds']); ?>" data-running="<?php echo $running ? '1' : '0'; ?>"><?php echo esc_html(self::hms($sum['net_seconds'])); ?></span>
                <span class="mlt-timer-hint">زمان خالص این تسک</span>
            </div>
            <div class="mlt-timer-actions">
                <?php if ($running) : ?>
                    <?php
                    $options = '<option value="">دلیل پاز…</option>';
                    foreach (MLT_Timer::REASONS as $key => $label) {
                        $options .= '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
                    }
                    echo self::form('mlt_timer', ['do' => 'pause', 'id' => $id], 'mlt_timer',
                        '<select name="reason" required aria-label="دلیل پاز" title="' . esc_attr(MLT_Timer::WHY) . '">' . $options . '</select>'
                        . '<span class="mlt-tip" tabindex="0" title="' . esc_attr(MLT_Timer::WHY) . '" aria-label="' . esc_attr(MLT_Timer::WHY) . '">؟</span>'
                        . '<button type="submit" class="mlt-btn mlt-btn--ghost">پاز</button>', 'mlt-inline');
                    ?>
                <?php else : ?>
                    <?php echo self::form('mlt_timer', ['do' => 'resume', 'id' => $id], 'mlt_timer', '<button type="submit" class="mlt-btn">ادامه</button>', 'mlt-inline'); ?>
                <?php endif; ?>
                <?php echo self::form('mlt_timer', ['do' => 'finish', 'id' => $id], 'mlt_timer', '<button type="submit" class="mlt-btn mlt-btn--ghost">پایان تایمر</button>', 'mlt-inline'); ?>
            </div>
            <?php if ($attention === 'long_pause') : ?>
                <div class="mlt-ask">
                    <span>بیش از ۳۰ دقیقه است که پاز هستی. ادامه می‌دی؟</span>
                    <?php echo self::form('mlt_timer', ['do' => 'resume', 'id' => $id], 'mlt_timer', '<button type="submit" class="mlt-btn">بله، ادامه</button>', 'mlt-inline'); ?>
                    <?php echo self::form('mlt_timer', ['do' => 'finish', 'id' => $id], 'mlt_timer', '<button type="submit" class="mlt-btn mlt-btn--ghost">نه، تمامش کن</button>', 'mlt-inline'); ?>
                </div>
            <?php elseif ($attention === 'overlong' && time() - $acked > self::ACK_SECONDS) : ?>
                <?php
                $min = gmdate('Y-m-d\TH:i', (new DateTimeImmutable($e['started_at'], new DateTimeZone('UTC')))->getTimestamp());
                $max = gmdate('Y-m-d\TH:i');
                ?>
                <div class="mlt-ask">
                    <span>این تایمر خیلی وقته روشنه (بیش از ۴ ساعت یا از نیمه‌شب UTC گذشته). کی تموم کردی؟ <span dir="ltr">(UTC)</span></span>
                    <?php echo self::form('mlt_timer', ['do' => 'finish', 'id' => $id], 'mlt_timer',
                        '<input type="datetime-local" name="ended_at" required min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" dir="ltr">'
                        . '<button type="submit" class="mlt-btn">ثبت پایان</button>', 'mlt-inline'); ?>
                    <?php echo self::form('mlt_timer', ['do' => 'ack', 'id' => $id], 'mlt_timer', '<button type="submit" class="mlt-btn mlt-btn--ghost">هنوز کار می‌کنم</button>', 'mlt-inline'); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function hms($seconds)
    {
        $seconds = max(0, (int) $seconds);
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    // ---- handlers ----

    private static function back($msg, $type = 'ok')
    {
        $back = isset($_POST['back']) && is_string($_POST['back']) ? sanitize_key(wp_unslash($_POST['back'])) : '';
        MLT_Admin::redirect_with(['type' => $type, 'msg' => $msg], in_array($back, MLT_Admin::PAGES, true) ? $back : MLT_Admin::SLUG, [], '');
    }

    public static function handle_timer()
    {
        MLT_Admin::verify('mlt_timer');
        $do = isset($_POST['do']) && is_string($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $user = get_current_user_id();
        $ok = ['start' => 'تایمر روشن شد.', 'pause' => 'تایمر پاز شد.', 'resume' => 'تایمر ادامه پیدا کرد.', 'finish' => 'تایمر تمام شد.', 'ack' => 'باشه، ۴ ساعت دیگر دوباره می‌پرسم.'];
        if (!isset($ok[$do])) {
            self::back('انجام نشد: عمل نامعتبر است.', 'error');
        }
        if ($do === 'ack') {
            update_user_meta($user, self::ACK_META, time());
            self::back($ok[$do]);
        }
        if ($do === 'start') {
            $result = MLT_Timer::start($id);
        } elseif ($do === 'pause') {
            $result = MLT_Timer::pause($id, isset($_POST['reason']) && is_string($_POST['reason']) ? sanitize_key(wp_unslash($_POST['reason'])) : '');
        } elseif ($do === 'resume') {
            $result = MLT_Timer::resume($id);
        } else {
            $ended = isset($_POST['ended_at']) && is_string($_POST['ended_at']) ? str_replace('T', ' ', sanitize_text_field(wp_unslash($_POST['ended_at']))) : null;
            $result = MLT_Timer::finish($id, $ended);
        }
        self::back(is_wp_error($result) ? 'انجام نشد: ' . $result->get_error_message() : $ok[$do], is_wp_error($result) ? 'error' : 'ok');
    }

    public static function handle_banner()
    {
        MLT_Admin::verify('mlt_banner');
        $do = isset($_POST['do']) && is_string($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        if ($do === 'hide') {
            update_user_meta(get_current_user_id(), self::WHY_META, 1); // remembered per user; it does not come back by itself
        } elseif ($do === 'show') {
            delete_user_meta(get_current_user_id(), self::WHY_META);
        }
        $back = isset($_POST['back']) && is_string($_POST['back']) ? sanitize_key(wp_unslash($_POST['back'])) : '';
        wp_safe_redirect(add_query_arg('page', in_array($back, MLT_Admin::PAGES, true) ? $back : MLT_Admin::SLUG, admin_url('admin.php')));
        exit;
    }
}
