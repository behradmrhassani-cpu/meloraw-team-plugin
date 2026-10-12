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
            <div class="mlt-board">
                <?php foreach ($columns as $bucket => $tasks) : ?>
                    <section class="mlt-col mlt-col--<?php echo esc_attr($bucket); ?>">
                        <h2><?php echo esc_html(self::LABELS['bucket'][$bucket]); ?> <span class="mlt-count"><?php echo esc_html(number_format_i18n(count($tasks))); ?></span></h2>
                        <?php if (!$tasks) : ?>
                            <p class="mlt-empty">تسکی نیست.</p>
                        <?php endif; ?>
                        <?php foreach ($tasks as $t) : ?>
                            <article class="mlt-card mlt-status--<?php echo esc_attr($t['status']); ?>">
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

    private static function select($field, $all, $current)
    {
        echo '<select name="' . esc_attr($field) . '"><option value="">' . esc_html($all) . '</option>';
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
