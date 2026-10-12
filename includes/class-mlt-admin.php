<?php
if (!defined('ABSPATH')) {
    exit;
}

class MLT_Admin
{
    const SLUG = 'meloraw-tasks';
    const IMPORT_SLUG = 'meloraw-tasks-import';
    const ROUTINES_SLUG = 'meloraw-tasks-routines';
    const IDEAS_SLUG = 'meloraw-tasks-ideas';
    const REPORTS_SLUG = 'meloraw-tasks-reports';
    const LOG_SLUG = 'meloraw-tasks-log';
    const SETTINGS_SLUG = 'meloraw-tasks-settings';
    const TODAY_SLUG = 'meloraw-tasks-today';
    const TIME_SLUG = 'meloraw-tasks-time';
    const PAGES = [self::SLUG, self::TODAY_SLUG, self::TIME_SLUG, self::IMPORT_SLUG, self::ROUTINES_SLUG, self::IDEAS_SLUG, self::REPORTS_SLUG, self::LOG_SLUG, self::SETTINGS_SLUG];

    const LABELS = [
        'status'   => ['open' => 'انجام نشده', 'in_progress' => 'در حال انجام', 'done' => 'انجام‌شده'],
        'bucket'   => ['blocker' => 'مانع', 'boost' => 'تقویت', 'normal' => 'عادی'],
        'priority' => ['high' => 'بالا', 'med' => 'متوسط', 'low' => 'پایین'],
        'owner'    => ['you' => 'محمد', 'maral' => 'مارال', 'chakra' => 'چاکرا', 'auto' => 'اتوماسیون'],
    ];

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'send_headers']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_mlt_add_task', [__CLASS__, 'handle_add_task']);
        add_action('admin_post_mlt_delete_task', [__CLASS__, 'handle_delete_task']);
        add_action('admin_post_mlt_edit_task', [__CLASS__, 'handle_edit_task']);
        add_action('admin_post_mlt_extend', [__CLASS__, 'handle_extend']);
        add_action('admin_post_mlt_set_status', [__CLASS__, 'handle_set_status']);
        add_action('admin_post_mlt_add_comment', [__CLASS__, 'handle_add_comment']);
        add_action('admin_post_mlt_import', [__CLASS__, 'handle_import']);
        add_action('admin_post_mlt_template', [__CLASS__, 'handle_template']);
        add_action('admin_post_mlt_routine_status', [__CLASS__, 'handle_routine_status']);
        add_action('admin_post_mlt_routine_template', [__CLASS__, 'handle_routine_template']);
        add_action('admin_post_mlt_idea_status', [__CLASS__, 'handle_idea_status']);
        add_action('admin_post_mlt_convert_comment', ['MLT_Reports', 'handle_convert']);
        add_action('admin_post_mlt_save_settings', ['MLT_Settings', 'handle_save']);
        add_action('admin_post_mlt_flag_comment', [__CLASS__, 'handle_flag_comment']);
    }

    public static function menu()
    {
        add_menu_page('تسک‌های تیم', 'تسک‌های تیم', MLT_CAP, self::SLUG, [__CLASS__, 'render_board'], 'dashicons-list-view', 3);
        add_submenu_page(self::SLUG, 'برد تسک‌ها', 'برد', MLT_CAP, self::SLUG, [__CLASS__, 'render_board']);
        add_submenu_page(self::SLUG, 'امروز من', 'امروز من', MLT_CAP, self::TODAY_SLUG, ['MLT_Today', 'render']);
        add_submenu_page(self::SLUG, 'زمان و امتیاز', 'زمان و امتیاز', MLT_CAP, self::TIME_SLUG, ['MLT_TimeReport', 'render']);
        add_submenu_page(self::SLUG, 'روتین‌ها', 'روتین‌ها', MLT_CAP, self::ROUTINES_SLUG, [__CLASS__, 'render_routines']);
        add_submenu_page(self::SLUG, 'بانک ایده‌ها', 'بانک ایده‌ها', MLT_CAP, self::IDEAS_SLUG, [__CLASS__, 'render_ideas']);
        add_submenu_page(self::SLUG, 'گزارش‌ها', 'گزارش‌ها', MLT_CAP, self::REPORTS_SLUG, ['MLT_Reports', 'render']);
        add_submenu_page(self::SLUG, 'لاگ فعالیت', 'لاگ', MLT_CAP, self::LOG_SLUG, ['MLT_Log', 'render']);
        add_submenu_page(self::SLUG, 'ایمپورت از فایل قدیمی', 'ایمپورت', MLT_CAP, self::IMPORT_SLUG, [__CLASS__, 'render_import']);
        add_submenu_page(self::SLUG, 'تنظیمات', 'تنظیمات', MLT_Settings::CAP, self::SETTINGS_SLUG, ['MLT_Settings', 'render']);
    }

    /** The plugin's own pages (admin.php?page=…) and its form handlers (admin-post.php?action=mlt_…): no indexing, no caching. */
    public static function send_headers()
    {
        global $pagenow;
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        $ours = ($pagenow === 'admin.php' && in_array($page, self::PAGES, true)) || ($pagenow === 'admin-post.php' && strpos($action, 'mlt_') === 0);
        if ($ours && !headers_sent()) {
            foreach (MLT_REST::NO_CACHE_HEADERS as $name => $value) {
                header($name . ': ' . $value);
            }
            // wp_die() (e.g. a failed nonce) calls nocache_headers() later and would replace Cache-Control
            add_filter('nocache_headers', function ($headers) {
                return MLT_REST::NO_CACHE_HEADERS + $headers;
            });
        }
    }

    public static function assets($hook)
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!in_array($page, self::PAGES, true)) {
            return;
        }
        // Version by file mtime so browser/LiteSpeed/Cloudflare caches refetch the CSS after every deploy.
        $ver = filemtime(MLT_PLUGIN_DIR . 'assets/admin.css') ?: MLT_VERSION;
        wp_enqueue_style('mlt-admin', plugins_url('assets/admin.css', MLT_PLUGIN_FILE), [], $ver);
        wp_enqueue_script('mlt-admin', plugins_url('assets/admin.js', MLT_PLUGIN_FILE), [], filemtime(MLT_PLUGIN_DIR . 'assets/admin.js') ?: MLT_VERSION, true);
    }

    /** Everything above the page content: clock bar, announcement, timer bar, hints (see MLT_Panel). */
    public static function topbar()
    {
        MLT_Panel::render();
    }

    public static function render_board()
    {
        MLT_Board::render();
    }

    public static function render_import()
    {
        if (!current_user_can(MLT_CAP)) {
            wp_die('دسترسی ندارید.');
        }
        $notice = get_transient(self::notice_key());
        $report = get_transient(self::report_key());
        delete_transient(self::notice_key());
        delete_transient(self::report_key());
        ?>
        <div class="mlt" dir="rtl">
            <?php MLT_Admin::topbar(); ?>
            <header class="mlt-head">
                <h1>ایمپورت از فایل JSON</h1>
            </header>
            <?php if ($notice) : ?>
                <div class="mlt-notice mlt-notice--<?php echo esc_attr($notice['type']); ?>"><?php echo esc_html($notice['msg']); ?></div>
            <?php endif; ?>
            <?php if ($report) : ?>
                <?php self::render_report($report); ?>
            <?php endif; ?>
            <?php self::render_templates(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="mlt-import">
                <input type="hidden" name="action" value="mlt_import">
                <?php wp_nonce_field('mlt_import'); ?>
                <p class="mlt-hint">فایلی به قالب افزونه (قالب را از پایین دانلود کن) یا فایل قدیمی <code dir="ltr">tasks-export.json</code> را انتخاب کن (حداکثر ۵ مگابایت، هر بخش تا ۵۰۰۰ ردیف). تسک‌ها، روتین‌ها و ایده‌ها وارد می‌شوند؛ کامنت و پرچم هرگز وارد نمی‌شوند. مورد تکراری (بر اساس شناسه، یا عنوان اگر شناسه نداشته باشد) دوباره ساخته نمی‌شود، پس اجرای دوباره بی‌خطر است. با «فقط بررسی کن» همه‌ی خطاها با شماره‌ی ردیف نشان داده می‌شوند و چیزی ذخیره نمی‌شود. کد تسک (MH-1) هنگام ایمپورت ساخته می‌شود و در فایل نیست. فایل روی سرور ذخیره نمی‌شود.</p>
                <label class="mlt-field">فایل JSON
                    <input type="file" name="file" accept=".json,application/json" required>
                </label>
                <label class="mlt-check"><input type="checkbox" name="dry_run" value="1"> فقط بررسی کن، چیزی ذخیره نشود</label>
                <div><button type="submit" class="mlt-btn">شروع ایمپورت</button></div>
            </form>
        </div>
        <?php
    }

    /** Download buttons for the empty and the sample template of each type. Read-only GET links with a nonce. */
    private static function render_templates()
    {
        $labels = ['tasks' => 'تسک‌ها', 'routines' => 'روتین‌ها', 'ideas' => 'ایده‌ها', 'full' => 'کامل (هر سه)'];
        ?>
        <div class="mlt-report mlt-templates">
            <h2>دانلود قالب</h2>
            <p class="mlt-hint">قالب خالی فقط ساختار است؛ قالب نمونه‌دار چند ردیف ساختگی دارد که بدون خطا وارد می‌شود. این فایل‌ها را می‌توانی به ایجنتت بدهی (متن آماده: <code dir="ltr">docs/import-template/agent-prompt-import.md</code>). فهرست کامل فیلدها: <code dir="ltr">GET /schema</code>.</p>
            <table>
                <?php foreach ($labels as $type => $label) : ?>
                    <tr>
                        <th><?php echo esc_html($label); ?></th>
                        <?php foreach (['empty' => 'قالب خالی', 'sample' => 'قالب نمونه‌دار'] as $variant => $vlabel) : ?>
                            <td><a class="mlt-btn mlt-btn--ghost mlt-btn--sm" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'mlt_template', 'type' => $type, 'variant' => $variant], admin_url('admin-post.php')), 'mlt_template')); ?>" download="<?php echo esc_attr(MLT_Schema::template_filename($type, $variant)); ?>"><?php echo esc_html($vlabel); ?></a></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php
    }

    /** admin-post.php?action=mlt_template&type=…&variant=…&_wpnonce=… : sends the template as a JSON file. */
    public static function handle_template()
    {
        if (!current_user_can(MLT_CAP)) {
            wp_die('دسترسی ندارید.', 'خطا', ['response' => 403]);
        }
        $nonce = isset($_GET['_wpnonce']) && is_string($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'mlt_template')) {
            wp_die('اعتبار این لینک تمام شده است. صفحه‌ی ایمپورت را دوباره باز کن.', 'خطا', ['response' => 403]);
        }
        $type = isset($_GET['type']) && is_string($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
        $variant = isset($_GET['variant']) && is_string($_GET['variant']) ? sanitize_key(wp_unslash($_GET['variant'])) : '';
        if (!in_array($type, MLT_Schema::TYPES, true) || !in_array($variant, ['empty', 'sample'], true)) {
            wp_die('نوع یا حالت قالب نامعتبر است.', 'خطا', ['response' => 400]);
        }
        $body = MLT_Schema::template_json($type, $variant);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . MLT_Schema::template_filename($type, $variant) . '"');
        header('X-Content-Type-Options: nosniff');
        echo $body; // JSON built from code constants only
        exit;
    }

    private static function render_report(array $report)
    {
        $total = ['created' => 0, 'duplicate' => 0, 'invalid' => 0];
        ?>
        <div class="mlt-report">
            <h2><?php echo $report['dry'] ? 'نتیجه‌ی بررسی (چیزی ذخیره نشد)' : 'نتیجه‌ی ایمپورت'; ?> <span class="mlt-count"><?php echo ($report['mode'] ?? 'legacy') === 'template' ? 'قالب افزونه' : 'فایل قدیمی'; ?></span></h2>
            <table>
                <thead>
                    <tr>
                        <th>بخش</th>
                        <th><?php echo $report['dry'] ? 'ساخته می‌شد' : 'ساخته شد'; ?></th>
                        <th>تکراری (رد شد)</th>
                        <th>نامعتبر (رد شد)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['sections'] as $section => $n) : ?>
                        <?php foreach ($total as $key => $sum) { $total[$key] += $n[$key]; } ?>
                        <tr>
                            <td><?php echo esc_html(MLT_Importer::SECTIONS[$section]); ?></td>
                            <td><?php echo esc_html($n['created']); ?></td>
                            <td><?php echo esc_html($n['duplicate']); ?></td>
                            <td><?php echo esc_html($n['invalid']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="mlt-total">
                        <td>جمع</td>
                        <td><?php echo esc_html($total['created']); ?></td>
                        <td><?php echo esc_html($total['duplicate']); ?></td>
                        <td><?php echo esc_html($total['invalid']); ?></td>
                    </tr>
                </tbody>
            </table>
            <p class="mlt-hint">کامنت‌های نادیده‌گرفته‌شده: <?php echo esc_html($report['ignored_comments']); ?></p>
            <?php if ($report['reasons']) : ?>
                <h3>ردیف‌های نامعتبر<?php echo $total['invalid'] > count($report['reasons']) ? ' (' . esc_html(count($report['reasons'])) . ' مورد اول)' : ''; ?></h3>
                <ul class="mlt-reasons">
                    <?php foreach ($report['reasons'] as $r) : ?>
                        <li><?php echo esc_html(MLT_Importer::SECTIONS[$r['section']] ?? $r['section']); ?> · ردیف <code dir="ltr"><?php echo esc_html($r['row']); ?></code><?php echo $r['id'] !== '' ? ' (' . esc_html($r['id']) . ')' : ''; ?> — <?php echo esc_html($r['reason']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($report['warnings'])) : ?>
                <h3>هشدارها (ردیف رد نشد)</h3>
                <ul class="mlt-reasons">
                    <?php foreach ($report['warnings'] as $w) : ?>
                        <li><?php echo esc_html(MLT_Importer::SECTIONS[$w['section']] ?? $w['section']); ?> · ردیف <code dir="ltr"><?php echo esc_html($w['row']); ?></code> — <?php echo esc_html($w['message']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_import()
    {
        self::verify('mlt_import');

        $file = isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : null;
        $error = null;
        if ($file && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $error = 'حجم فایل بیشتر از حد مجاز است.';
        } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $error = 'فایلی دریافت نشد. دوباره امتحان کن.';
        } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'json') {
            $error = 'فقط فایل با پسوند json قبول است.';
        } elseif ($file['size'] > MLT_Importer::MAX_BYTES) {
            $error = 'حجم فایل بیشتر از ۵ مگابایت است.';
        } else {
            // read straight from PHP's temp upload; the file is never moved or stored
            $result = MLT_Importer::run(file_get_contents($file['tmp_name']), !empty($_POST['dry_run']));
            if (is_wp_error($result)) {
                $error = $result->get_error_message();
            } else {
                set_transient(self::report_key(), $result, 10 * MINUTE_IN_SECONDS);
            }
        }
        if ($error) {
            set_transient(self::notice_key(), ['type' => 'error', 'msg' => 'ایمپورت انجام نشد: ' . $error], MINUTE_IN_SECONDS);
        }
        wp_safe_redirect(add_query_arg('page', self::IMPORT_SLUG, admin_url('admin.php')));
        exit;
    }

    private static function report_key()
    {
        return 'mlt_import_report_' . get_current_user_id();
    }

    // Every handler checks capability + nonce, then goes through the REST endpoint so
    // validation, sanitizing and activity logging live in one place.

    public static function handle_add_task()
    {
        self::verify('mlt_add_task');
        self::call_and_redirect('POST', '/tasks', self::posted(['title', 'description', 'owner', 'priority', 'bucket', 'status', 'estimate_minutes', 'due_date', 'done_criteria', 'assignees']), function ($d) {
            list($suffix, $type) = self::notify_from_post($d['id']);
            return ['تسک «' . $d['title'] . '» ساخته شد' . $suffix . '.', ['added' => $d['id']], $type];
        });
    }

    public static function handle_delete_task()
    {
        $id = self::posted_id();
        self::verify('mlt_delete_task_' . $id);
        self::call_and_redirect('DELETE', '/tasks/' . $id, [], function () {
            return ['تسک حذف شد.', []];
        });
    }

    public static function handle_edit_task()
    {
        $id = self::posted_id();
        self::verify('mlt_edit_task_' . $id);
        $body = self::posted(['title', 'description', 'owner', 'priority', 'bucket', 'due_date', 'done_criteria', 'estimate_minutes', 'assignees']);
        if (($body['estimate_minutes'] ?? '') === '') {
            unset($body['estimate_minutes']); // the field is only offered while the task has no estimate
        }
        if (!isset($body['assignees']) && !empty($_POST['assignees_marker'])) {
            $body['assignees'] = []; // every box was unticked: only the owner stays
        }
        self::call_and_redirect('PATCH', '/tasks/' . $id, $body, function () use ($id) {
            return ['تسک ویرایش شد.', ['edited' => $id]];
        });
    }

    /** Request to raise the fixed estimate: new total + a reason of at least 10 characters. */
    public static function handle_extend()
    {
        $id = self::posted_id();
        self::verify('mlt_extend_' . $id);
        self::call_and_redirect('POST', '/tasks/' . $id . '/extend', self::posted(['estimate_minutes', 'reason']), function () use ($id) {
            return ['زمان تقریبی تمدید شد و دلیلش ثبت شد.', ['edited' => $id]];
        });
    }

    public static function handle_set_status()
    {
        self::patch('mlt_set_status', ['status', 'late_reason'], 'وضعیت تغییر کرد', true);
    }

    private static function patch($action, array $fields, $ok_msg, $may_notify = false)
    {
        $id = self::posted_id();
        self::verify($action . '_' . $id);
        self::call_and_redirect('PATCH', '/tasks/' . $id, self::posted($fields), function () use ($ok_msg, $id, $may_notify) {
            list($suffix, $type) = $may_notify ? self::notify_from_post($id) : ['', 'ok'];
            return [$ok_msg . $suffix . '.', ['edited' => $id], $type];
        });
    }

    public static function handle_add_comment()
    {
        $id = self::posted_id();
        self::verify('mlt_add_comment_' . $id);
        self::call_and_redirect('POST', '/tasks/' . $id . '/comments', self::posted(['body']), function () use ($id) {
            list($suffix, $type) = self::notify_from_post($id);
            return ['کامنت ثبت شد' . $suffix . '.', [], $type];
        }, ['comments' => $id]);
    }

    public static function handle_flag_comment()
    {
        $id = self::posted_id();
        $cid = isset($_POST['cid']) ? absint($_POST['cid']) : 0;
        self::verify('mlt_flag_comment_' . $cid);
        self::call_and_redirect('POST', '/tasks/' . $id . '/comments/' . $cid . '/flag', [], function () {
            return ['پرچم زده شد.', []];
        }, ['comments' => $id]);
    }

    /** Comment text, escaped first; each URL is then isolated as LTR so its trailing "/" does not jump to the wrong end of an RTL line. */
    public static function comment_html($text)
    {
        return preg_replace('~https?://[^\s<]+~u', '<bdi dir="ltr">$0</bdi>', esc_html($text));
    }

    public static function posted_id()
    {
        return isset($_POST['id']) ? absint($_POST['id']) : 0;
    }

    public static function posted(array $fields)
    {
        $body = [];
        foreach ($fields as $field) {
            if (isset($_POST[$field]) && is_array($_POST[$field])) {
                $body[$field] = array_values(array_filter(array_map('sanitize_key', array_map('strval', (array) wp_unslash($_POST[$field])))));
            } elseif (isset($_POST[$field])) {
                $body[$field] = (string) wp_unslash($_POST[$field]);
            }
        }
        return $body;
    }

    /** Capability + nonce check with Persian failure pages (core's nonce-failure page is English). */
    public static function verify($action, $cap = MLT_CAP)
    {
        if (!current_user_can($cap)) {
            wp_die('دسترسی ندارید.', 'خطا', ['response' => 403]);
        }
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_die('اعتبار این صفحه تمام شده است. صفحه را دوباره باز کن و دوباره امتحان کن.', 'خطا', ['response' => 403]);
        }
    }

    /**
     * If the form's "send to Telegram" box was ticked, sends the text from the form (and only that) through
     * POST /tasks/{id}/notify, after the change itself has been saved. Returns [text to append to the notice, notice type].
     */
    public static function notify_from_post($task_id)
    {
        if (empty($_POST['notify'])) {
            return ['', 'ok'];
        }
        $request = new WP_REST_Request('POST', '/' . MLT_REST::NS . '/tasks/' . (int) $task_id . '/notify');
        $request->set_body_params(['text' => isset($_POST['notify_text']) && is_string($_POST['notify_text']) ? wp_unslash($_POST['notify_text']) : '']);
        $response = rest_do_request($request);
        if ($response->is_error()) {
            $data = $response->get_data();
            return [' — اما ' . rtrim((string) ($data['message'] ?? 'ارسال به تلگرام انجام نشد.'), '.'), 'warn'];
        }
        return [' و به تلگرام ارسال شد', 'ok'];
    }

    /**
     * Checkbox "📣 ارسال به تلگرام" (off by default) with the text field it reveals. assets/admin.js pre-fills the text
     * (title, owner, status, sender, link) and keeps it in step with the form until the sender edits it.
     * $inline: lay the pieces out directly in the card's action row.
     */
    public static function notify_block($key, $event, array $data, $inline = false)
    {
        $data += ['title' => '', 'owner' => '', 'status' => '', 'sender' => wp_get_current_user()->display_name];
        $attrs = '';
        foreach (['event' => $event] + $data as $name => $value) {
            $attrs .= ' data-' . $name . '="' . esc_attr($value) . '"';
        }
        $id = 'mlt-n-' . $key;
        ?>
        <div class="mlt-notify<?php echo $inline ? ' mlt-notify--inline' : ''; ?>"<?php echo $attrs; ?>>
            <input type="checkbox" name="notify" value="1" id="<?php echo esc_attr($id); ?>" class="mlt-notify-toggle">
            <label for="<?php echo esc_attr($id); ?>" class="mlt-notify-label">📣 ارسال به تلگرام</label>
            <div class="mlt-notify-body">
                <textarea name="notify_text" rows="6" maxlength="4096" dir="auto" aria-label="متن پیام تلگرام"></textarea>
                <p class="mlt-hint">فقط همین متن ارسال می‌شود؛ می‌توانی ویرایشش کنی.</p>
                <?php if ($inline) : ?>
                    <button type="submit" class="mlt-btn">ذخیره و ارسال</button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /** $on_ok($data) returns [success message, extra redirect args]; $always is added to the redirect either way. */
    public static function call_and_redirect($method, $route, array $body, callable $on_ok, array $always = [], $page = self::SLUG, $anchor = '')
    {
        $request = new WP_REST_Request($method, '/' . MLT_REST::NS . $route);
        $request->set_body_params($body);
        $response = rest_do_request($request);
        $data = $response->get_data();

        $args = $always;
        if ($response->is_error()) {
            $notice = ['type' => 'error', 'msg' => 'انجام نشد: ' . ($data['message'] ?? '')];
        } else {
            list($msg, $extra, $type) = array_pad($on_ok($data), 3, 'ok'); // 'warn': saved, but the Telegram step did not work
            $notice = ['type' => $type, 'msg' => $msg];
            $args += $extra;
        }
        self::redirect_with($notice, $page, $args, $anchor);
    }

    /** $anchor ("#id") brings the browser back to the item that was changed. */
    public static function redirect_with(array $notice, $page, array $args, $anchor)
    {
        set_transient(self::notice_key(), $notice, MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page' => $page] + $args, admin_url('admin.php')) . $anchor);
        exit;
    }

    // ---- routines and idea bank ----

    public static function render_routines()
    {
        if (!current_user_can(MLT_CAP)) {
            wp_die('دسترسی ندارید.');
        }
        $only_incomplete = isset($_GET['incomplete']) && $_GET['incomplete'] === '1';
        $groups = [];
        foreach (MLT_Routines::FREQS as $freq => $label) {
            $groups[$freq] = ['label' => $label, 'period' => MLT_Routines::period_key($freq), 'items' => []];
        }
        $stopped = [];
        foreach (MLT_Routines::views() as $v) {
            if ($only_incomplete && !$v['incomplete']) {
                continue;
            }
            if ($v['stopped']) {
                $stopped[] = $v;
            } else {
                $groups[$v['frequency']]['items'][] = $v;
            }
        }
        $now = MLT_Time::now();
        ?>
        <div class="mlt" dir="rtl">
            <?php self::topbar(); ?>
            <header class="mlt-head">
                <h1>روتین‌ها</h1>
                <nav class="mlt-seg">
                    <a href="<?php echo esc_url(add_query_arg('page', self::ROUTINES_SLUG, admin_url('admin.php'))); ?>" class="<?php echo $only_incomplete ? '' : 'is-on'; ?>">همه</a>
                    <a href="<?php echo esc_url(add_query_arg(['page' => self::ROUTINES_SLUG, 'incomplete' => '1'], admin_url('admin.php'))); ?>" class="<?php echo $only_incomplete ? 'is-on' : ''; ?>">فقط ناقص</a>
                </nav>
            </header>
            <?php self::render_notice(); ?>
            <p class="mlt-hint" style="margin-bottom:24px">
                <code dir="ltr">UTC+0</code> — همان زمان انتشار پست‌های سایت. اکنون <code dir="ltr"><?php echo esc_html($now->format('Y-m-d H:i')); ?> UTC</code>.
                با عوض‌شدن روز، هفته یا ماه (به وقت UTC) وضعیت روتین‌ها خودکار از نو شروع می‌شود.
            </p>
            <?php foreach ($groups as $g) : ?>
                <?php $done = count(array_filter($g['items'], function ($v) { return $v['status'] === 'done'; })); ?>
                <section class="mlt-rsec">
                    <h2>
                        <?php echo esc_html($g['label']); ?>
                        <code dir="ltr"><?php echo esc_html($g['period']); ?></code>
                        <span class="mlt-count"><?php echo esc_html($done . ' از ' . count($g['items']) . ' انجام‌شده'); ?></span>
                    </h2>
                    <?php if (!$g['items']) : ?>
                        <p class="mlt-empty"><?php echo $only_incomplete ? 'روتین ناقصی نیست.' : 'روتینی تعریف نشده. فایل قدیمی را از «ایمپورت» وارد کن.'; ?></p>
                    <?php endif; ?>
                    <div class="mlt-rgrid">
                        <?php foreach ($g['items'] as $v) : ?>
                            <?php self::routine_card($v); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            <?php if ($stopped) : ?>
                <section class="mlt-rsec mlt-stopped">
                    <h2>متوقف‌شده <span class="mlt-count"><?php echo esc_html(count($stopped)); ?></span></h2>
                    <div class="mlt-rgrid">
                        <?php foreach ($stopped as $v) : ?>
                            <?php self::routine_card($v); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function routine_card(array $v)
    {
        $key = $v['key'];
        $post_url = esc_url(admin_url('admin-post.php'));
        ?>
        <article id="mlt-r-<?php echo esc_attr($key); ?>" class="mlt-card mlt-status--<?php echo esc_attr($v['status']); ?>">
            <div class="mlt-card-top">
                <?php if ($v['incomplete']) : ?><span class="mlt-badge mlt-badge--incomplete" title="زمان تقریبی ثبت نشده">ناقص</span><?php endif; ?>
                <?php if ($v['stopped']) : ?><span class="mlt-badge">متوقف</span><?php endif; ?>
            </div>
            <h3><?php echo esc_html($v['title']); ?></h3>
            <?php if ($v['description'] !== '') : ?>
                <p class="mlt-desc"><?php echo esc_html(wp_trim_words($v['description'], 30)); ?></p>
            <?php endif; ?>
            <div class="mlt-meta">
                <span class="mlt-chip is-main"><?php echo esc_html(self::label('owner', $v['owner'])); ?></span>
                <?php if ($v['estimate_minutes'] !== null) : ?><span class="mlt-fact" dir="ltr" title="زمان تقریبی">⏱ <?php echo esc_html(MLT_Board::minutes($v['estimate_minutes'])); ?></span><?php endif; ?>
                <?php if ($v['recurrence_until'] !== null) : ?><span class="mlt-fact" dir="ltr" title="آخرین روز اجرا (UTC)">🏁 <?php echo esc_html($v['recurrence_until']); ?></span><?php endif; ?>
            </div>
            <?php if ($v['done_criteria']) : ?>
                <p class="mlt-criteria"><strong>معیار انجام:</strong> <?php echo esc_html($v['done_criteria']); ?></p>
            <?php endif; ?>
            <?php if (!$v['stopped']) : ?>
                <?php self::tri_form('mlt_routine_status', ['key' => $v['key'], 'period_key' => $v['period_key']], 'mlt_routine_' . $v['key'] . '_' . $v['period_key'], $v['status']); ?>
            <?php endif; ?>
            <div class="mlt-rtools">
                <button type="button" class="mlt-link-btn" onclick="this.closest('.mlt-card').classList.toggle('mlt-editing')">قالب روتین</button>
                <form method="post" action="<?php echo $post_url; ?>" class="mlt-inline">
                    <input type="hidden" name="action" value="mlt_routine_template">
                    <input type="hidden" name="key" value="<?php echo esc_attr($key); ?>">
                    <input type="hidden" name="do" value="<?php echo $v['stopped'] ? 'resume' : 'stop'; ?>">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_routine_tpl_' . $key)); ?>">
                    <button type="submit" class="mlt-link-btn<?php echo $v['stopped'] ? '' : ' mlt-link-btn--danger'; ?>"<?php echo $v['stopped'] ? '' : ' onclick="return confirm(\'این روتین از امروز اجرا نمی‌شود (هر وقت خواستی دوباره راهش بینداز). مطمئنی؟\');"'; ?>><?php echo $v['stopped'] ? 'ادامه‌ی روتین' : 'متوقف کردن روتین'; ?></button>
                </form>
            </div>
            <div class="mlt-editpanel">
                <form method="post" action="<?php echo $post_url; ?>" class="mlt-form mlt-edit-form">
                    <input type="hidden" name="action" value="mlt_routine_template">
                    <input type="hidden" name="key" value="<?php echo esc_attr($key); ?>">
                    <input type="hidden" name="do" value="save">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_routine_tpl_' . $key)); ?>">
                    <label class="mlt-field">زمان تقریبی (دقیقه)
                        <input type="number" name="estimate_minutes" min="<?php echo (int) MLT_Taskmeta::ESTIMATE_MIN; ?>" max="<?php echo (int) MLT_Taskmeta::ESTIMATE_MAX; ?>" step="1" value="<?php echo esc_attr((string) $v['estimate_minutes']); ?>">
                    </label>
                    <label class="mlt-field">پایان روتین (اختیاری، UTC)
                        <input type="date" name="recurrence_until" dir="ltr" value="<?php echo esc_attr((string) $v['recurrence_until']); ?>">
                    </label>
                    <label class="mlt-field mlt-field--wide">معیار انجام (اختیاری، تا ۲۰۰۰ نویسه)
                        <textarea name="done_criteria" rows="2" maxlength="<?php echo (int) MLT_Taskmeta::CRITERIA_MAX; ?>"><?php echo esc_textarea((string) $v['done_criteria']); ?></textarea>
                    </label>
                    <div class="mlt-field--wide"><button type="submit" class="mlt-btn">ذخیره</button></div>
                </form>
            </div>
        </article>
        <?php
    }

    /** Idea bank: tasks of kind "idea", grouped by type then category (type/category/tag live in meta). */
    public static function render_ideas()
    {
        if (!current_user_can(MLT_CAP)) {
            wp_die('دسترسی ندارید.');
        }
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}mlt_tasks WHERE kind = 'idea' ORDER BY id ASC", ARRAY_A);

        $types = ['track' => 'ترک‌ها', 'sfx' => 'افکت‌های صوتی (SFX)'];
        $groups = [];
        foreach ($rows as $r) {
            $meta = json_decode((string) $r['meta'], true);
            $meta = is_array($meta) ? $meta : [];
            $type = isset($meta['type']) && is_string($meta['type']) && $meta['type'] !== '' ? $meta['type'] : '—';
            $cat = isset($meta['category']) && is_string($meta['category']) && $meta['category'] !== '' ? $meta['category'] : 'بدون دسته';
            $r['tag'] = isset($meta['tag']) && is_string($meta['tag']) ? $meta['tag'] : '';
            $groups[$type][$cat][] = $r;
        }
        $order = array_keys($types);
        uksort($groups, function ($a, $b) use ($order) {
            $pa = array_search($a, $order, true);
            $pb = array_search($b, $order, true);
            return (($pa === false ? 99 : $pa) <=> ($pb === false ? 99 : $pb)) ?: strcmp($a, $b);
        });
        $count_done = function (array $items) {
            return count(array_filter($items, function ($r) { return $r['status'] === 'done'; }));
        };
        ?>
        <div class="mlt" dir="rtl">
            <?php MLT_Admin::topbar(); ?>
            <header class="mlt-head">
                <h1>بانک ایده‌ها</h1>
            </header>
            <?php self::render_notice(); ?>
            <?php if (!$groups) : ?>
                <p class="mlt-empty">ایده‌ای نیست. فایل قدیمی را از «ایمپورت» وارد کن.</p>
            <?php endif; ?>
            <?php foreach ($groups as $type => $cats) : ?>
                <?php $all = array_merge(...array_values($cats)); ?>
                <section class="mlt-rsec">
                    <h2>
                        <?php echo esc_html($types[$type] ?? $type); ?>
                        <span class="mlt-count"><?php echo esc_html($count_done($all) . ' از ' . count($all) . ' انجام‌شده'); ?></span>
                    </h2>
                    <div class="mlt-rgrid">
                        <?php foreach ($cats as $cat => $items) : ?>
                            <section class="mlt-icat">
                                <h3>
                                    <?php echo esc_html($cat); ?>
                                    <span class="mlt-count"><?php echo esc_html($count_done($items) . ' از ' . count($items)); ?></span>
                                </h3>
                                <ul>
                                    <?php foreach ($items as $r) : ?>
                                        <li id="mlt-i-<?php echo (int) $r['id']; ?>" class="mlt-idea mlt-status--<?php echo esc_attr($r['status']); ?>">
                                            <div class="mlt-idea-head">
                                                <span class="mlt-idea-title"><?php echo esc_html($r['title']); ?></span>
                                                <?php if ($r['tag'] !== '') : ?>
                                                    <span class="mlt-tag"><?php echo esc_html($r['tag']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php self::tri_form('mlt_idea_status', ['id' => (int) $r['id']], 'mlt_idea_' . (int) $r['id'], $r['status']); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </section>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /** Three-state control: one button per status; the current one is disabled and highlighted. */
    private static function tri_form($action, array $fields, $nonce_action, $current)
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mlt-tri">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <?php foreach ($fields as $name => $value) : ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
            <?php endforeach; ?>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce($nonce_action)); ?>">
            <?php foreach (self::LABELS['status'] as $value => $label) : ?>
                <button type="submit" name="status" value="<?php echo esc_attr($value); ?>" class="mlt-tri-<?php echo esc_attr($value); ?>"<?php echo $value === $current ? ' disabled aria-pressed="true"' : ''; ?>><?php echo esc_html($label); ?></button>
            <?php endforeach; ?>
        </form>
        <?php
    }

    public static function render_notice()
    {
        $notice = get_transient(self::notice_key());
        delete_transient(self::notice_key());
        if ($notice) {
            echo '<div class="mlt-notice mlt-notice--' . esc_attr($notice['type']) . '">' . esc_html($notice['msg']) . '</div>';
        }
    }

    public static function handle_routine_status()
    {
        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        $period = isset($_POST['period_key']) ? sanitize_text_field(wp_unslash($_POST['period_key'])) : '';
        self::verify('mlt_routine_' . $key . '_' . $period);
        // period_key travels with the form so a page left open across midnight cannot tick the next period
        self::call_and_redirect('PATCH', '/routines/' . $key, self::posted(['status']) + ['period_key' => $period], function () {
            return ['وضعیت روتین تغییر کرد.', []];
        }, [], self::ROUTINES_SLUG, '#mlt-r-' . preg_replace('/[^A-Za-z0-9._-]/', '', $key));
    }

    /** Template of a routine (estimate, done criterion, end date) or stop / resume it. */
    public static function handle_routine_template()
    {
        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        self::verify('mlt_routine_tpl_' . $key);
        $do = isset($_POST['do']) && is_string($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : 'save';
        if ($do === 'stop' || $do === 'resume') {
            $body = ['stopped' => $do === 'stop']; // stop / resume is its own action
        } else {
            $body = self::posted(['estimate_minutes', 'done_criteria', 'recurrence_until']);
            if (($body['estimate_minutes'] ?? '') === '') {
                unset($body['estimate_minutes']);
            }
        }
        $stopping = isset($body['stopped']) ? $body['stopped'] : null;
        self::call_and_redirect('PATCH', '/routines/' . $key, $body, function () use ($stopping) {
            return [$stopping === null ? 'قالب روتین ذخیره شد.' : ($stopping ? 'روتین متوقف شد.' : 'روتین دوباره فعال شد.'), []];
        }, [], self::ROUTINES_SLUG, '#mlt-r-' . preg_replace('/[^A-Za-z0-9._-]/', '', $key));
    }

    /** Ideas are tasks of kind "idea": the state change goes through PATCH /tasks/{id} (and is logged there). */
    public static function handle_idea_status()
    {
        global $wpdb;
        $id = self::posted_id();
        self::verify('mlt_idea_' . $id);
        $kind = $wpdb->get_var($wpdb->prepare("SELECT kind FROM {$wpdb->prefix}mlt_tasks WHERE id = %d", $id));
        if ($kind !== 'idea') {
            self::redirect_with(['type' => 'error', 'msg' => 'انجام نشد: ایده پیدا نشد.'], self::IDEAS_SLUG, [], '');
        }
        self::call_and_redirect('PATCH', '/tasks/' . $id, self::posted(['status']), function () {
            return ['وضعیت ایده تغییر کرد.', []];
        }, [], self::IDEAS_SLUG, '#mlt-i-' . $id);
    }

    public static function notice_key()
    {
        return 'mlt_notice_' . get_current_user_id();
    }

    /** $attrs is for static, code-defined attributes only (never user input). */
    public static function select($field, $all, $current, $attrs = '', array $labels = null)
    {
        echo '<select name="' . esc_attr($field) . '"' . ($attrs ? ' ' . $attrs : '') . '>';
        if ($all !== null) {
            echo '<option value="">' . esc_html($all) . '</option>';
        }
        foreach ($labels ?? self::LABELS[$field] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public static function label($field, $value)
    {
        return self::LABELS[$field][$value] ?? $value;
    }
}
