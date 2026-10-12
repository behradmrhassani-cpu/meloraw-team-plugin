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
        wp_enqueue_style('mlt-admin', plugins_url('assets/admin.css', MLT_PLUGIN_FILE), [], MLT_VERSION);
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

        $added  = isset($_GET['added']) ? absint($_GET['added']) : 0;
        $notice = get_transient(self::notice_key());
        delete_transient(self::notice_key());

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
                            <article class="mlt-card mlt-status--<?php echo esc_attr($t['status']); ?><?php echo (int) $t['id'] === $added ? ' mlt-card--new' : ''; ?>">
                                <h3><?php echo esc_html($t['title']); ?></h3>
                                <?php if ($t['description'] !== '') : ?>
                                    <p><?php echo esc_html(wp_trim_words($t['description'], 30)); ?></p>
                                <?php endif; ?>
                                <div class="mlt-meta">
                                    <span class="mlt-tag mlt-owner--<?php echo esc_attr($t['owner']); ?>"><?php echo esc_html(self::label('owner', $t['owner'])); ?></span>
                                    <span class="mlt-tag mlt-prio--<?php echo esc_attr($t['priority']); ?>"><?php echo esc_html(self::label('priority', $t['priority'])); ?></span>
                                    <span class="mlt-tag mlt-st"><?php echo esc_html(self::label('status', $t['status'])); ?></span>
                                </div>
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

    /** Saves through the REST endpoint so validation and sanitizing live in one place. */
    public static function handle_add_task()
    {
        if (!current_user_can(MLT_CAP)) {
            wp_die('دسترسی ندارید.', '', ['response' => 403]);
        }
        check_admin_referer('mlt_add_task');

        $body = [];
        foreach (['title', 'description', 'owner', 'priority', 'bucket', 'status'] as $field) {
            if (isset($_POST[$field])) {
                $body[$field] = (string) wp_unslash($_POST[$field]);
            }
        }
        $request = new WP_REST_Request('POST', '/' . MLT_REST::NS . '/tasks');
        $request->set_body_params($body);
        $response = rest_do_request($request);
        $data = $response->get_data();

        $args = ['page' => self::SLUG];
        if ($response->is_error()) {
            $notice = ['type' => 'error', 'msg' => 'ذخیره نشد: ' . ($data['message'] ?? '')];
        } else {
            $notice = ['type' => 'ok', 'msg' => 'تسک «' . $data['title'] . '» ساخته شد.'];
            $args['added'] = $data['id'];
        }
        set_transient(self::notice_key(), $notice, MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private static function notice_key()
    {
        return 'mlt_notice_' . get_current_user_id();
    }

    private static function select($field, $all, $current)
    {
        echo '<select name="' . esc_attr($field) . '">';
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
