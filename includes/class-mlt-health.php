<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Health check" page (administrators with manage_options). Each check runs on its own, so one failure never stops
 * the others, and returns green / yellow / red with a Persian explanation and what to do. Nothing is sent to Telegram
 * unless an administrator clicks the test button (nonce + capability); the token is never shown.
 */
class MLT_Health
{
    const CAP = 'manage_options';
    const PROBE_ROUTE = '/health/probe';
    const PROBE_TTL = 60;

    public static function init()
    {
        add_action('admin_post_mlt_health_telegram', [__CLASS__, 'handle_telegram_test']);
        add_action('rest_api_init', [__CLASS__, 'register_probe']);
    }

    // ---- the Authorization-header probe ----

    public static function register_probe()
    {
        register_rest_route(MLT_REST::NS, self::PROBE_ROUTE, [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'probe_callback'],
            'permission_callback' => [__CLASS__, 'probe_permission'],
            'show_in_index'       => false,
        ]);
    }

    public static function is_probe_route($route)
    {
        return $route === '/' . MLT_REST::NS . self::PROBE_ROUTE;
    }

    /** Only the health page itself can call it: it knows a one-minute random token that it stored just before. */
    public static function probe_permission(WP_REST_Request $request)
    {
        $expected = get_transient('mlt_health_probe');
        $given = (string) $request->get_header('x_mlt_probe');
        if (is_string($expected) && $expected !== '' && hash_equals($expected, $given)) {
            return true;
        }
        return new WP_Error('mlt_forbidden', 'دسترسی ندارید.', ['status' => rest_authorization_required_code()]);
    }

    /** Reports whether an Authorization header reached PHP (some LiteSpeed / CGI setups drop it). Nothing else. */
    public static function probe_callback()
    {
        $seen = !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        return ['authorization_seen' => (bool) $seen];
    }

    private static $probe = null;

    /** One loopback request to this site's own protected API route; the result serves two checks. */
    private static function probe()
    {
        if (self::$probe !== null) {
            return self::$probe;
        }
        $token = wp_generate_password(32, false);
        set_transient('mlt_health_probe', $token, self::PROBE_TTL);
        $response = wp_remote_get(rest_url(MLT_REST::NS . self::PROBE_ROUTE), [
            'timeout'     => 8,
            'redirection' => 0,
            'sslverify'   => (bool) apply_filters('https_local_ssl_verify', false),
            'headers'     => ['Authorization' => 'Bearer ' . $token, 'X-MLT-Probe' => $token],
        ]);
        delete_transient('mlt_health_probe');
        if (is_wp_error($response)) {
            return self::$probe = ['error' => 'loopback'];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        return self::$probe = [
            'code'    => $code,
            'seen'    => is_array($json) && array_key_exists('authorization_seen', $json) ? (bool) $json['authorization_seen'] : null,
            'headers' => [
                'x-robots-tag'              => (string) wp_remote_retrieve_header($response, 'x-robots-tag'),
                'cache-control'             => (string) wp_remote_retrieve_header($response, 'cache-control'),
                'x-litespeed-cache-control' => (string) wp_remote_retrieve_header($response, 'x-litespeed-cache-control'),
            ],
        ];
    }

    // ---- checks ----

    private static function ok($message, array $details = [])
    {
        return ['ok', $message, '', $details];
    }

    private static function warn($message, $fix, array $details = [])
    {
        return ['warn', $message, $fix, $details];
    }

    private static function fail($message, $fix, array $details = [])
    {
        return ['fail', $message, $fix, $details];
    }

    /** id => [title, callable]. Each callable returns [status, message, fix, details]. */
    public static function checks()
    {
        return apply_filters('mlt_health_checks', self::builtin_checks()); // a filter so a test can add a check that throws
    }

    private static function builtin_checks()
    {
        return [
            'schema'    => ['جدول‌ها و نسخه‌ی schema', [__CLASS__, 'check_schema']],
            'php'       => ['نسخه‌ی PHP', [__CLASS__, 'check_php']],
            'db'        => ['نسخه‌ی MySQL / MariaDB', [__CLASS__, 'check_db']],
            'innodb'    => ['موتور InnoDB و توابع JSON', [__CLASS__, 'check_innodb']],
            'https'     => ['HTTPS', [__CLASS__, 'check_https']],
            'apppass'   => ['Application Passwords', [__CLASS__, 'check_apppass']],
            'authz'     => ['هدر Authorization به PHP می‌رسد', [__CLASS__, 'check_authz']],
            'headers'   => ['هدرهای امنیتی و کش روی پاسخ API', [__CLASS__, 'check_headers']],
            'clock'     => ['ساعت سرور و UTC', [__CLASS__, 'check_clock']],
            'identity'  => ['نگاشت حساب‌ها به اعضا', [__CLASS__, 'check_identity']],
            'telegram'  => ['تنظیمات تلگرام', [__CLASS__, 'check_telegram']],
            'counts'    => ['شمارنده‌ی تسک‌ها، روتین‌ها و ایده‌ها', [__CLASS__, 'check_counts']],
            'agents'    => ['Application Passwordهای ایجنت‌ها', [__CLASS__, 'check_agents']],
        ];
    }

    /** Runs one check in isolation: an exception becomes a red result, never a stopped page. */
    public static function run($id)
    {
        $checks = self::checks();
        try {
            $r = call_user_func($checks[$id][1]);
        } catch (Throwable $e) {
            $r = self::fail('این چک با خطای داخلی تمام شد.', 'لاگ خطای PHP سرور را ببین؛ بقیه‌ی چک‌ها از این خطا تأثیر نمی‌گیرند.');
        }
        return $r + [3 => []];
    }

    public static function table_names()
    {
        global $wpdb;
        $p = $wpdb->prefix;
        return array_map(function ($t) use ($p) { return $p . $t; }, ['mlt_tasks', 'mlt_comments', 'mlt_activity_log', 'mlt_task_assignees', 'mlt_counters', 'mlt_estimate_extensions', 'mlt_time_entries', 'mlt_scores']);
    }

    public static function check_schema()
    {
        global $wpdb;
        $missing = array_values(array_filter(self::table_names(), function ($t) use ($wpdb) {
            return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t;
        }));
        $version = (string) get_option('mlt_db_version');
        if ($missing) {
            return self::fail('این جدول‌ها نیستند: ' . implode('، ', $missing), 'افزونه را غیرفعال و دوباره فعال کن (جدول‌ها با فعال‌سازی ساخته می‌شوند). اگر باز هم نبود، کاربر دیتابیس اجازه‌ی CREATE TABLE ندارد.');
        }
        if ($version !== MLT_Install::DB_VERSION) {
            return self::fail('نسخه‌ی schema ' . ($version ?: 'ثبت نشده') . ' است ولی باید ' . MLT_Install::DB_VERSION . ' باشد.', 'افزونه را غیرفعال و دوباره فعال کن تا ارتقا اجرا شود.');
        }
        return self::ok('هر ' . count(self::table_names()) . ' جدول هست و نسخه‌ی schema ' . $version . ' است.');
    }

    public static function check_php()
    {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            return self::fail('PHP ' . PHP_VERSION . ' است؛ افزونه حداقل ۷.۴ می‌خواهد.', 'از هاست بخواه PHP را به ۷.۴ یا بالاتر ببرد (۸.۱ و بالاتر بهتر است).');
        }
        return self::ok('PHP ' . PHP_VERSION);
    }

    public static function check_db()
    {
        global $wpdb;
        $info = (string) $wpdb->db_server_info();
        $maria = stripos($info, 'mariadb') !== false;
        $version = (string) $wpdb->db_version();
        $need = $maria ? '10.2.7' : '5.7.8';
        if (version_compare($version, $need, '<')) {
            return self::fail(($maria ? 'MariaDB ' : 'MySQL ') . $version . ' است؛ حداقل ' . $need . ' لازم است (ستون JSON و تراکنش).', 'از هاست بخواه نسخه‌ی دیتابیس را ارتقا دهد.');
        }
        return self::ok(($maria ? 'MariaDB ' : 'MySQL ') . $version);
    }

    public static function check_innodb()
    {
        global $wpdb;
        $details = [];
        $bad = [];
        foreach (self::table_names() as $t) {
            $engine = (string) $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $t));
            if ($engine !== '' && strcasecmp($engine, 'InnoDB') !== 0) {
                $bad[] = $t . ' (' . $engine . ')';
            }
        }
        $json = $wpdb->get_var("SELECT JSON_VALID('{}')");
        if ($bad) {
            return self::fail('این جدول‌ها InnoDB نیستند: ' . implode('، ', $bad) . ' — ایمپورت تراکنشی کار نمی‌کند.', 'موتور جدول را به InnoDB ببر (ALTER TABLE … ENGINE=InnoDB) یا از هاست بخواه.');
        }
        if ((string) $json !== '1') {
            return self::fail('تابع JSON در دیتابیس کار نمی‌کند.', 'نسخه‌ی دیتابیس را ارتقا بده؛ روتین‌ها و ایده‌ها از ستون JSON استفاده می‌کنند.');
        }
        return self::ok('همه‌ی جدول‌ها InnoDB‌اند و توابع JSON کار می‌کنند.');
    }

    public static function check_https()
    {
        $home = (string) get_option('home');
        $site = (string) get_option('siteurl');
        if (!is_ssl()) {
            return self::fail('این صفحه با HTTPS باز نشده است.', 'سایت را با HTTPS اجرا کن. بدون آن Application Password کار نمی‌کند و رمزها در شبکه لو می‌روند. (روی سرور آزمایشی محلی این چک قرمز است.)');
        }
        if (strpos($home, 'https://') !== 0 || strpos($site, 'https://') !== 0) {
            return self::warn('صفحه با HTTPS باز شده ولی آدرس سایت در تنظیمات https نیست.', 'در «تنظیمات ← عمومی» هر دو آدرس را به https تغییر بده.');
        }
        return self::ok('HTTPS فعال است.');
    }

    public static function check_apppass()
    {
        if (!wp_is_application_passwords_available()) {
            return self::fail('Application Passwords در این سایت خاموش است.', 'معمولاً به‌خاطر HTTP یا یک افزونه‌ی امنیتی است. سایت را روی HTTPS ببر و اگر افزونه‌ای (مثل Wordfence یا Disable REST) آن را بسته، اجازه بده. هر کاربر در «پروفایل ← Application Passwords» رمز می‌سازد.');
        }
        return self::ok('Application Passwords فعال است.');
    }

    public static function check_authz()
    {
        $p = self::probe();
        if (isset($p['error'])) {
            return self::warn('نشد از داخل سرور به خود سایت درخواست بزنم (loopback بسته است)، پس این چک انجام نشد.', 'از بیرون با curl بزن: curl -u "کاربر:رمز" https://سایت/wp-json/meloraw-tasks/v1/me . اگر ۴۰۱ گرفتی با رمز درست، همین مشکل هدر Authorization است.');
        }
        if ($p['seen'] === null) {
            return self::warn('پاسخ آزمایشی (کد ' . $p['code'] . ') قابل خواندن نبود.', 'کش یا فایروال ممکن است جلوی درخواست داخلی را گرفته باشد؛ /wp-json/meloraw-tasks/ را از کش و فایروال مستثنا کن (docs/cache-exclusions.md).');
        }
        if (!$p['seen']) {
            return self::fail('هدر Authorization به PHP نمی‌رسد؛ ایجنت‌ها همیشه ۴۰۱ می‌گیرند.', "در .htaccess بالای بلوک وردپرس بگذار:\nRewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n(روی LiteSpeed هم همین؛ جزئیات در docs/cache-exclusions.md).");
        }
        return self::ok('هدر Authorization به PHP می‌رسد.');
    }

    public static function check_headers()
    {
        $p = self::probe();
        if (isset($p['error'])) {
            return self::warn('درخواست داخلی به API ممکن نبود، پس هدرها بررسی نشد.', 'هدرها را از بیرون ببین: curl -sI https://سایت/wp-json/meloraw-tasks/v1/tasks');
        }
        $h = $p['headers'];
        $missing = [];
        if (stripos($h['x-robots-tag'], 'noindex') === false) {
            $missing[] = 'X-Robots-Tag: noindex';
        }
        if (stripos($h['cache-control'], 'no-store') === false) {
            $missing[] = 'Cache-Control: no-store';
        }
        if (stripos($h['x-litespeed-cache-control'], 'no-cache') === false) {
            $missing[] = 'X-LiteSpeed-Cache-Control: no-cache';
        }
        if ($missing) {
            return self::fail('این هدرها روی پاسخ API دیده نمی‌شوند: ' . implode('، ', $missing), 'احتمالاً CDN یا افزونه‌ی کش هدر را حذف می‌کند. استثناهای docs/cache-exclusions.md را برای /wp-json/meloraw-tasks/ اعمال کن.');
        }
        return self::ok('X-Robots-Tag، Cache-Control: no-store و X-LiteSpeed-Cache-Control روی پاسخ API هستند.');
    }

    public static function check_clock()
    {
        global $wpdb;
        $db = (int) $wpdb->get_var('SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP())');
        $diff = abs($db - time());
        $now = MLT_Time::now();
        if ($now->format('P') !== '+00:00') {
            return self::fail('ساعت افزونه روی UTC نیست.', 'این یک باگ افزونه است؛ MLT_Time باید UTC باشد.');
        }
        if ($diff > 5) {
            return self::fail('ساعت PHP و دیتابیس ' . $diff . ' ثانیه اختلاف دارند.', 'ساعت سرور را با NTP هماهنگ کن (زمان‌سنج و دوره‌ی روتین‌ها به آن وابسته‌اند).');
        }
        $tz = (string) get_option('timezone_string') ?: 'UTC' . sprintf('%+g', (float) get_option('gmt_offset'));
        return self::ok('اکنون ' . $now->format('Y-m-d H:i:s') . ' UTC (اختلاف PHP و دیتابیس ' . $diff . ' ثانیه). نوار ساعت پنل از همین ساعت شروع می‌شود. تنظیم منطقه‌ی زمانی وردپرس (' . $tz . ') دست‌نخورده است و افزونه از آن استفاده نمی‌کند.');
    }

    public static function check_identity()
    {
        $map = MLT_Identity::map();
        $rows = [];
        $missing = [];
        foreach (['you', 'maral', 'chakra'] as $slug) {
            $uids = array_keys(array_filter($map, function ($s) use ($slug) { return $s === $slug; }));
            $names = array_map(function ($id) { $u = get_userdata($id); return $u ? $u->user_login : '#' . $id; }, $uids);
            $rows[] = [MLT_Admin::LABELS['owner'][$slug] . ' (' . MLT_Identity::prefix($slug) . ')', $names ? implode('، ', $names) : '— وصل نشده'];
            if (!$names) {
                $missing[] = MLT_Admin::LABELS['owner'][$slug];
            }
        }
        $auto = array_keys(array_filter($map, function ($s) { return $s === 'auto'; }));
        $rows[] = [MLT_Admin::LABELS['owner']['auto'] . ' (' . MLT_Identity::prefix('auto') . ')', $auto ? 'وصل است' : 'وصل نشده (اختیاری)'];
        if ($missing) {
            return self::fail('این اعضا به هیچ حسابی وصل نیستند: ' . implode('، ', $missing), 'در «تنظیمات ← هویت اعضا» هر حساب را به عضوش وصل کن؛ بدون آن برد «من» و «امروز من» و کد تسک درست کار نمی‌کند.', $rows);
        }
        return self::ok('محمد، مارال و چاکرا هر کدام به یک حساب وصل‌اند.', $rows);
    }

    public static function check_telegram()
    {
        $token = MLT_Settings::value('telegram_bot_token');
        $chat = MLT_Settings::value('telegram_chat_id');
        $thread = MLT_Settings::value('telegram_thread_id');
        $token_ok = (bool) preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/', $token);
        $details = [
            ['توکن ربات', $token === '' ? 'تنظیم نشده' : ($token_ok ? 'تنظیم شده و قالبش درست است (نمایش داده نمی‌شود)' : 'تنظیم شده ولی قالبش درست نیست')],
            ['chat id', $chat === '' ? 'تنظیم نشده' : 'تنظیم شده'],
            ['thread id', $thread === '' ? 'ندارد (اختیاری)' : 'تنظیم شده'],
        ];
        if ($token === '' && $chat === '') {
            return self::warn('تلگرام تنظیم نشده است (فقط برای ارسال دستی لازم است).', 'در «تنظیمات» یا با ثابت‌های MLT_TELEGRAM_* در wp-config.php توکن و chat id را بگذار. هیچ چیزی خودکار به تلگرام نمی‌رود.', $details);
        }
        if (!$token_ok || $chat === '') {
            return self::fail('تنظیمات تلگرام ناقص یا نامعتبر است.', 'توکن (قالب 123456789:ABC…) و chat id هر دو لازم‌اند.', $details);
        }
        return self::ok('توکن و chat id تنظیم شده‌اند. با دکمه‌ی «ارسال پیام آزمایشی» (پایین) مسیر ارسال را امتحان کن.', $details);
    }

    public static function check_counts()
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $tasks = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}mlt_tasks WHERE kind IN ('phase','new')");
        $incomplete = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}mlt_tasks WHERE kind IN ('phase','new') AND estimate_minutes IS NULL");
        $routines = count(MLT_Routines::views());
        $ideas = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}mlt_tasks WHERE kind = 'idea'");
        $details = [['تسک‌ها', (string) $tasks . ($incomplete ? ' (' . $incomplete . ' ناقص)' : '')], ['روتین‌ها', (string) $routines], ['ایده‌ها', (string) $ideas]];
        if ($tasks + $routines + $ideas === 0) {
            return self::warn('هنوز هیچ تسک، روتین یا ایده‌ای نیست.', 'از «ایمپورت» قالب نمونه یا فایل خودت را وارد کن، یا تسک دستی بساز.', $details);
        }
        return self::ok($tasks . ' تسک، ' . $routines . ' روتین و ' . $ideas . ' ایده. بعد از ایمپورت این اعداد را با فایل مقایسه کن.', $details);
    }

    public static function check_agents()
    {
        $rows = [];
        $any = false;
        foreach (MLT_Settings::team_accounts() as $user) {
            $names = [];
            foreach (WP_Application_Passwords::get_user_application_passwords($user->ID) as $item) { // the stored hash is never read or shown
                if (MLT_Agents::is_agent_name($item['name'] ?? '')) {
                    $names[] = sanitize_text_field($item['name']) . ' — ساخته‌شده ' . gmdate('Y-m-d', (int) ($item['created'] ?? 0)) . '، آخرین استفاده ' . (!empty($item['last_used']) ? gmdate('Y-m-d H:i', (int) $item['last_used']) . ' UTC' : 'هرگز');
                }
            }
            $any = $any || (bool) $names;
            $rows[] = [$user->display_name . ' (' . $user->user_login . ')', $names ? implode('؛ ', $names) : 'هیچ رمز Claude-… یا Hermes-… ندارد'];
        }
        if (!$any) {
            return self::warn('هیچ Application Password با نام Claude-… یا Hermes-… ساخته نشده است.', 'هر نفر در «پروفایل ← Application Passwords» برای هر ایجنت یک رمز با نامی مثل Claude-mh یا Hermes-maral بسازد (اسم باید با Claude- یا Hermes- شروع شود تا فقط روی API این افزونه کار کند). خودِ رمز هیچ‌جا نمایش داده نمی‌شود.', $rows);
        }
        return self::ok('رمزهای ایجنت‌ها (فقط نام و تاریخ؛ خودِ رمز نمایش داده نمی‌شود):', $rows);
    }

    // ---- page ----

    private static function badge($status)
    {
        $map = ['ok' => ['✅', 'سبز'], 'warn' => ['⚠️', 'زرد'], 'fail' => ['❌', 'قرمز']];
        return $map[$status];
    }

    public static function render()
    {
        if (!current_user_can(self::CAP)) {
            wp_die('دسترسی ندارید.', 'خطا', ['response' => 403]);
        }
        $results = [];
        foreach (array_keys(self::checks()) as $id) {
            $results[$id] = self::run($id);
        }
        $count = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($results as $r) {
            $count[$r[0]]++;
        }
        ?>
        <div class="mlt" dir="rtl">
            <?php MLT_Admin::topbar(); ?>
            <header class="mlt-head">
                <h1>بررسی سلامت</h1>
                <a class="mlt-btn mlt-btn--ghost" href="<?php echo esc_url(add_query_arg('page', MLT_Admin::HEALTH_SLUG, admin_url('admin.php'))); ?>">اجرای دوباره</a>
            </header>
            <?php MLT_Admin::render_notice(); ?>
            <p class="mlt-hint" style="margin-bottom:18px">هر چک جدا اجرا می‌شود؛ خطای یکی بقیه را متوقف نمی‌کند. هیچ پیامی به تلگرام نمی‌رود مگر با دکمه‌ی آزمایشی پایین.</p>
            <div class="mlt-stats">
                <div class="mlt-stat"><b><?php echo esc_html($count['ok']); ?></b><span>✅ سبز</span></div>
                <div class="mlt-stat"><b><?php echo esc_html($count['warn']); ?></b><span>⚠️ زرد</span></div>
                <div class="mlt-stat"><b><?php echo esc_html($count['fail']); ?></b><span>❌ قرمز</span></div>
            </div>
            <div class="mlt-health">
                <?php foreach ($results as $id => $r) : list($status, $message, $fix, $details) = $r; list($icon, $word) = self::badge($status); ?>
                    <section class="mlt-check-card mlt-check--<?php echo esc_attr($status); ?>" id="mlt-check-<?php echo esc_attr($id); ?>" data-status="<?php echo esc_attr($status); ?>">
                        <h3><span class="mlt-check-icon" aria-hidden="true"><?php echo esc_html($icon); ?></span> <?php echo esc_html(self::checks()[$id][0]); ?> <span class="mlt-badge"><?php echo esc_html($word); ?></span></h3>
                        <p class="mlt-check-msg"><?php echo esc_html($message); ?></p>
                        <?php if ($details) : ?>
                            <table class="mlt-check-table">
                                <?php foreach ($details as $d) : ?>
                                    <tr><th><?php echo esc_html($d[0]); ?></th><td><?php echo esc_html($d[1]); ?></td></tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endif; ?>
                        <?php if ($fix !== '') : ?>
                            <p class="mlt-check-fix"><strong>راه حل:</strong> <?php echo nl2br(esc_html($fix)); ?></p>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>

            <h2 class="mlt-sub">ارسال پیام آزمایشی به تلگرام</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mlt-import" onsubmit="return confirm('یک پیام آزمایشی به chat id تنظیم‌شده ارسال می‌شود. مطمئنی؟');">
                <input type="hidden" name="action" value="mlt_health_telegram">
                <?php wp_nonce_field('mlt_health_telegram'); ?>
                <p class="mlt-hint">فقط با کلیک تو و فقط به همان chat id و thread id ذخیره‌شده در تنظیمات می‌رود. <strong>برای تست از یک گروه آزمایشی استفاده کن، نه گروه اصلی تیم.</strong> متن پیام ثابت است (بدون هیچ داده‌ی تسک). نتیجه (موفق یا خطا با دلیل کوتاه، بدون توکن) نمایش داده و در لاگ ثبت می‌شود.</p>
                <div><button type="submit" class="mlt-btn">📣 ارسال پیام آزمایشی</button></div>
            </form>
        </div>
        <?php
    }

    public static function handle_telegram_test()
    {
        MLT_Admin::verify('mlt_health_telegram', self::CAP);
        $user = wp_get_current_user();
        $text = '🧪 پیام آزمایشی «بررسی سلامت» افزونه‌ی تسک‌های MELORAW' . "\n" . 'فرستنده: ' . $user->display_name . "\n" . 'زمان: ' . MLT_Time::now()->format('Y-m-d H:i') . ' UTC' . "\n" . 'اگر این پیام را در گروه آزمایشی می‌بینی، مسیر ارسال درست است.';
        $result = MLT_Telegram::deliver($text, null); // logs telegram_notify / telegram_error (user, agent), never the text or the token
        if ($result === true) {
            MLT_Admin::redirect_with(['type' => 'ok', 'msg' => 'پیام آزمایشی ارسال شد (در لاگ ثبت شد).'], MLT_Admin::HEALTH_SLUG, [], '');
        }
        MLT_Admin::redirect_with(['type' => 'error', 'msg' => 'ارسال نشد: ' . $result->get_error_message()], MLT_Admin::HEALTH_SLUG, [], '');
    }
}
