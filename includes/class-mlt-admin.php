<?php
if (!defined('ABSPATH')) {
    exit;
}

class MLT_Admin
{
    const SLUG = 'meloraw-tasks';

    const LABELS = [
        'status'   => ['open' => 'انجام نشده', 'in_progress' => 'در حال انجام', 'done' => 'انجام‌شده'],
        'bucket'   => ['blocker' => 'مانع', 'boost' => 'تقویت', 'normal' => 'عادی'],
        'priority' => ['high' => 'بالا', 'med' => 'متوسط', 'low' => 'پایین'],
        'owner'    => ['you' => 'محمد', 'maral' => 'مارال', 'chakra' => 'چاکرا', 'auto' => 'اتوماسیون'],
    ];

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_mlt_add_task', [__CLASS__, 'handle_add_task']);
        add_action('admin_post_mlt_delete_task', [__CLASS__, 'handle_delete_task']);
        add_action('admin_post_mlt_edit_task', [__CLASS__, 'handle_edit_task']);
        add_action('admin_post_mlt_set_status', [__CLASS__, 'handle_set_status']);
        add_action('admin_post_mlt_add_comment', [__CLASS__, 'handle_add_comment']);
        add_action('admin_post_mlt_flag_comment', [__CLASS__, 'handle_flag_comment']);
    }

    public static function menu()
    {
        add_menu_page('تسک‌های تیم', 'تسک‌های تیم', MLT_CAP, self::SLUG, [__CLASS__, 'render_board'], 'dashicons-list-view', 3);
    }

    public static function assets($hook)
    {
        if ($hook !== 'toplevel_page_' . self::SLUG) {
            return;
        }
        // Version by file mtime so browser/LiteSpeed/Cloudflare caches refetch the CSS after every deploy.
        $ver = filemtime(MLT_PLUGIN_DIR . 'assets/admin.css') ?: MLT_VERSION;
        wp_enqueue_style('mlt-admin', plugins_url('assets/admin.css', MLT_PLUGIN_FILE), [], $ver);
    }

    public static function render_board()
    {
        if (!current_user_can(MLT_CAP)) {
            wp_die('دسترسی ندارید.');
        }
        global $wpdb;

        $owner  = isset($_GET['owner']) ? sanitize_key(wp_unslash($_GET['owner'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $owner  = isset(self::LABELS['owner'][$owner]) ? $owner : '';
        $status = isset(self::LABELS['status'][$status]) ? $status : '';

        $where = ["kind IN ('phase','new')"];
        $params = [];
        if ($owner) {
            $where[] = 'owner = %s';
            $params[] = $owner;
        }
        if ($status) {
            $where[] = 'status = %s';
            $params[] = $status;
        }
        $sql = "SELECT * FROM {$wpdb->prefix}mlt_tasks WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC';
        $rows = $wpdb->get_results($params ? $wpdb->prepare($sql, $params) : $sql, ARRAY_A);

        $highlight = isset($_GET['added']) ? absint($_GET['added']) : (isset($_GET['edited']) ? absint($_GET['edited']) : 0);
        $notice = get_transient(self::notice_key());
        delete_transient(self::notice_key());

        $open = isset($_GET['comments']) ? absint($_GET['comments']) : 0;
        $comments = [];
        if ($rows) {
            $ids = array_map('intval', wp_list_pluck($rows, 'id'));
            $found = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mlt_comments WHERE task_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ') ORDER BY id ASC',
                $ids
            ), ARRAY_A);
            cache_users(array_unique(array_map('intval', wp_list_pluck($found, 'author'))));
            foreach ($found as $c) {
                $comments[(int) $c['task_id']][] = $c;
            }
        }

        $columns = array_fill_keys(array_keys(self::LABELS['bucket']), []);
        foreach ($rows as $row) {
            if (isset($columns[$row['bucket']])) {
                $columns[$row['bucket']][] = $row;
            }
        }
        ?>
        <div class="mlt" dir="rtl">
            <header class="mlt-head">
                <h1>تسک‌های تیم MELORAW</h1>
                <form method="get" class="mlt-filters">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                    <?php self::select('owner', 'همه‌ی مسئول‌ها', $owner); ?>
                    <?php self::select('status', 'همه‌ی وضعیت‌ها', $status); ?>
                    <button class="mlt-btn">فیلتر</button>
                </form>
            </header>
            <?php if ($notice) : ?>
                <div class="mlt-notice mlt-notice--<?php echo esc_attr($notice['type']); ?>"><?php echo esc_html($notice['msg']); ?></div>
            <?php endif; ?>
            <?php self::render_add_form(); ?>
            <div class="mlt-board">
                <?php foreach ($columns as $bucket => $tasks) : ?>
                    <section class="mlt-col mlt-col--<?php echo esc_attr($bucket); ?>">
                        <h2><?php echo esc_html(self::LABELS['bucket'][$bucket]); ?> <span class="mlt-count"><?php echo esc_html(number_format_i18n(count($tasks))); ?></span></h2>
                        <?php if (!$tasks) : ?>
                            <p class="mlt-empty">تسکی نیست.</p>
                        <?php endif; ?>
                        <?php foreach ($tasks as $t) : ?>
                            <article class="mlt-card mlt-status--<?php echo esc_attr($t['status']); ?><?php echo (int) $t['id'] === $highlight ? ' mlt-card--new' : ''; ?><?php echo (int) $t['id'] === $open ? ' mlt-commenting' : ''; ?>">
                                <h3><?php echo esc_html($t['title']); ?></h3>
                                <?php if ($t['description'] !== '') : ?>
                                    <p><?php echo esc_html(wp_trim_words($t['description'], 30)); ?></p>
                                <?php endif; ?>
                                <div class="mlt-meta">
                                    <span class="mlt-tag mlt-owner--<?php echo esc_attr($t['owner']); ?>"><?php echo esc_html(self::label('owner', $t['owner'])); ?></span>
                                    <span class="mlt-tag mlt-prio--<?php echo esc_attr($t['priority']); ?>"><?php echo esc_html(self::label('priority', $t['priority'])); ?></span>
                                    <span class="mlt-tag mlt-st"><?php echo esc_html(self::label('status', $t['status'])); ?></span>
                                </div>
                                <?php if (current_user_can(MLT_CAP)) : ?>
                                    <?php
                                    $tid = (int) $t['id'];
                                    $post_url = esc_url(admin_url('admin-post.php'));
                                    $list = $comments[$tid] ?? [];
                                    $flagged = count(array_filter($list, function ($c) { return (int) $c['flagged']; }));
                                    ?>
                                    <div class="mlt-actions">
                                        <button type="button" class="mlt-edit-btn" onclick="this.closest('.mlt-card').classList.toggle('mlt-editing')">ویرایش</button>
                                        <button type="button" class="mlt-comments-btn<?php echo $flagged ? ' mlt-has-flag' : ''; ?>" onclick="this.closest('.mlt-card').classList.toggle('mlt-commenting')">کامنت‌ها (<?php echo esc_html(number_format_i18n(count($list))); ?>)</button>
                                        <form method="post" action="<?php echo $post_url; ?>" class="mlt-status">
                                            <input type="hidden" name="action" value="mlt_set_status">
                                            <input type="hidden" name="id" value="<?php echo $tid; ?>">
                                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_set_status_' . $tid)); ?>">
                                            <?php self::select('status', null, $t['status'], 'onchange="this.form.submit()" aria-label="تغییر وضعیت"'); ?>
                                        </form>
                                        <form method="post" action="<?php echo $post_url; ?>" class="mlt-del" onsubmit="return confirm('این تسک و کامنت‌هایش برای همیشه حذف می‌شود. مطمئنی؟');">
                                            <input type="hidden" name="action" value="mlt_delete_task">
                                            <input type="hidden" name="id" value="<?php echo $tid; ?>">
                                            <?php wp_nonce_field('mlt_delete_task_' . $tid); ?>
                                            <button type="submit" class="mlt-del-btn">حذف</button>
                                        </form>
                                    </div>
                                    <form method="post" action="<?php echo $post_url; ?>" class="mlt-form mlt-edit-form">
                                        <input type="hidden" name="action" value="mlt_edit_task">
                                        <input type="hidden" name="id" value="<?php echo $tid; ?>">
                                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_edit_task_' . $tid)); ?>">
                                        <label class="mlt-field mlt-field--wide">عنوان
                                            <input type="text" name="title" required maxlength="255" value="<?php echo esc_attr($t['title']); ?>">
                                        </label>
                                        <label class="mlt-field mlt-field--wide">توضیح
                                            <textarea name="description" rows="3"><?php echo esc_textarea($t['description']); ?></textarea>
                                        </label>
                                        <label class="mlt-field">مسئول <?php self::select('owner', null, $t['owner']); ?></label>
                                        <label class="mlt-field">اولویت <?php self::select('priority', null, $t['priority']); ?></label>
                                        <label class="mlt-field">دسته <?php self::select('bucket', null, $t['bucket']); ?></label>
                                        <div class="mlt-field--wide">
                                            <button type="submit" class="mlt-btn">ذخیره</button>
                                            <button type="button" class="mlt-cancel-btn" onclick="this.closest('.mlt-card').classList.remove('mlt-editing')">انصراف</button>
                                        </div>
                                    </form>
                                    <div class="mlt-comments">
                                        <?php foreach ($list as $c) : $author = get_userdata((int) $c['author']); ?>
                                            <div class="mlt-comment<?php echo (int) $c['flagged'] ? ' mlt-comment--flagged' : ''; ?>">
                                                <div class="mlt-c-head">
                                                    <strong><?php echo esc_html($author ? $author->display_name : 'کاربر حذف‌شده'); ?></strong>
                                                    <?php if ($c['agent'] !== null && $c['agent'] !== '') : ?>
                                                        <span class="mlt-tag"><?php echo esc_html($c['agent']); ?></span>
                                                    <?php endif; ?>
                                                    <time><?php echo esc_html(wp_date('Y/m/d H:i', strtotime($c['created_at'] . ' UTC'))); ?></time>
                                                    <?php if ((int) $c['flagged']) : ?>
                                                        <span class="mlt-flagged">پرچم‌دار</span>
                                                    <?php else : ?>
                                                        <form method="post" action="<?php echo $post_url; ?>" class="mlt-flag">
                                                            <input type="hidden" name="action" value="mlt_flag_comment">
                                                            <input type="hidden" name="id" value="<?php echo $tid; ?>">
                                                            <input type="hidden" name="cid" value="<?php echo (int) $c['id']; ?>">
                                                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_flag_comment_' . (int) $c['id'])); ?>">
                                                            <button type="submit" class="mlt-flag-btn">پرچم</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="mlt-c-body"><?php echo self::comment_html($c['body']); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (!$list) : ?>
                                            <p class="mlt-empty">هنوز کامنتی نیست.</p>
                                        <?php endif; ?>
                                        <form method="post" action="<?php echo $post_url; ?>" class="mlt-comment-form">
                                            <input type="hidden" name="action" value="mlt_add_comment">
                                            <input type="hidden" name="id" value="<?php echo $tid; ?>">
                                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_add_comment_' . $tid)); ?>">
                                            <textarea name="body" rows="2" required maxlength="10000" placeholder="کامنت جدید (فقط متن)"></textarea>
                                            <button type="submit" class="mlt-btn">ثبت کامنت</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private static function render_add_form()
    {
        ?>
        <details class="mlt-add">
            <summary class="mlt-btn">+ افزودن تسک</summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mlt-form">
                <input type="hidden" name="action" value="mlt_add_task">
                <?php wp_nonce_field('mlt_add_task'); ?>
                <label class="mlt-field mlt-field--wide">عنوان
                    <input type="text" name="title" required maxlength="255">
                </label>
                <label class="mlt-field mlt-field--wide">توضیح
                    <textarea name="description" rows="3"></textarea>
                </label>
                <label class="mlt-field">مسئول <?php self::select('owner', null, 'you'); ?></label>
                <label class="mlt-field">اولویت <?php self::select('priority', null, 'med'); ?></label>
                <label class="mlt-field">دسته <?php self::select('bucket', null, 'normal'); ?></label>
                <label class="mlt-field">وضعیت <?php self::select('status', null, 'open'); ?></label>
                <div class="mlt-field--wide"><button type="submit" class="mlt-btn">ذخیره</button></div>
            </form>
        </details>
        <?php
    }

    // Every handler checks capability + nonce, then goes through the REST endpoint so
    // validation, sanitizing and activity logging live in one place.

    public static function handle_add_task()
    {
        self::verify('mlt_add_task');
        self::call_and_redirect('POST', '/tasks', self::posted(['title', 'description', 'owner', 'priority', 'bucket', 'status']), function ($d) {
            return ['تسک «' . $d['title'] . '» ساخته شد.', ['added' => $d['id']]];
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
        self::patch('mlt_edit_task', ['title', 'description', 'owner', 'priority', 'bucket'], 'تسک ویرایش شد.');
    }

    public static function handle_set_status()
    {
        self::patch('mlt_set_status', ['status'], 'وضعیت تغییر کرد.');
    }

    private static function patch($action, array $fields, $ok_msg)
    {
        $id = self::posted_id();
        self::verify($action . '_' . $id);
        self::call_and_redirect('PATCH', '/tasks/' . $id, self::posted($fields), function () use ($ok_msg, $id) {
            return [$ok_msg, ['edited' => $id]];
        });
    }

    public static function handle_add_comment()
    {
        $id = self::posted_id();
        self::verify('mlt_add_comment_' . $id);
        self::call_and_redirect('POST', '/tasks/' . $id . '/comments', self::posted(['body']), function () {
            return ['کامنت ثبت شد.', []];
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
    private static function comment_html($text)
    {
        return preg_replace('~https?://[^\s<]+~u', '<bdi dir="ltr">$0</bdi>', esc_html($text));
    }

    private static function posted_id()
    {
        return isset($_POST['id']) ? absint($_POST['id']) : 0;
    }

    private static function posted(array $fields)
    {
        $body = [];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $body[$field] = (string) wp_unslash($_POST[$field]);
            }
        }
        return $body;
    }

    /** Capability + nonce check with Persian failure pages (core's nonce-failure page is English). */
    private static function verify($action)
    {
        if (!current_user_can(MLT_CAP)) {
            wp_die('دسترسی ندارید.', 'خطا', ['response' => 403]);
        }
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_die('اعتبار این صفحه تمام شده است. صفحه را دوباره باز کن و دوباره امتحان کن.', 'خطا', ['response' => 403]);
        }
    }

    /** $on_ok($data) returns [success message, extra redirect args]; $always is added to the redirect either way. */
    private static function call_and_redirect($method, $route, array $body, callable $on_ok, array $always = [])
    {
        $request = new WP_REST_Request($method, '/' . MLT_REST::NS . $route);
        $request->set_body_params($body);
        $response = rest_do_request($request);
        $data = $response->get_data();

        $args = ['page' => self::SLUG] + $always;
        if ($response->is_error()) {
            $notice = ['type' => 'error', 'msg' => 'انجام نشد: ' . ($data['message'] ?? '')];
        } else {
            list($msg, $extra) = $on_ok($data);
            $notice = ['type' => 'ok', 'msg' => $msg];
            $args += $extra;
        }
        set_transient(self::notice_key(), $notice, MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private static function notice_key()
    {
        return 'mlt_notice_' . get_current_user_id();
    }

    /** $attrs is for static, code-defined attributes only (never user input). */
    private static function select($field, $all, $current, $attrs = '')
    {
        echo '<select name="' . esc_attr($field) . '"' . ($attrs ? ' ' . $attrs : '') . '>';
        if ($all !== null) {
            echo '<option value="">' . esc_html($all) . '</option>';
        }
        foreach (self::LABELS[$field] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    private static function label($field, $value)
    {
        return self::LABELS[$field][$value] ?? $value;
    }
}
