<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Reports: flagged comments, and turning one of them into a task. */
class MLT_Reports
{
    const TITLE_MAX = 100;

    private static function flagged()
    {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_results(
            "SELECT c.*, t.title AS task_title FROM {$p}mlt_comments c INNER JOIN {$p}mlt_tasks t ON t.id = c.task_id WHERE c.flagged = 1 ORDER BY c.id DESC",
            ARRAY_A
        );
    }

    /**
     * Creates a task from a flagged comment (source_comment_id), takes the flag off and logs it.
     * The flag is taken off first with a conditional UPDATE, so two clicks cannot make two tasks.
     * @return array|WP_Error ['id' => new task id, 'title' => its title]
     */
    public static function convert($task_id, $cid)
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, t.title AS task_title, t.owner AS task_owner FROM {$p}mlt_comments c INNER JOIN {$p}mlt_tasks t ON t.id = c.task_id WHERE c.id = %d AND c.task_id = %d",
            $cid,
            $task_id
        ), ARRAY_A);
        if (!$c) {
            return new WP_Error('mlt_not_found', 'پیدا نشد.', ['status' => 404]);
        }
        if ($wpdb->query($wpdb->prepare("UPDATE {$p}mlt_comments SET flagged = 0 WHERE id = %d AND flagged = 1", $cid)) !== 1) {
            return new WP_Error('mlt_not_flagged', 'این کامنت پرچم‌دار نیست (شاید همین الان تبدیل شده).', ['status' => 409]);
        }

        // title = first line (cut at TITLE_MAX); the whole comment goes into the description
        $body = trim($c['body']);
        $lines = preg_split('/\R/u', $body) ?: [$body];
        $first = trim($lines[0]);
        $title = sanitize_text_field(mb_substr($first, 0, self::TITLE_MAX) . (mb_strlen($first) > self::TITLE_MAX ? '…' : ''));
        $title = $title !== '' ? $title : 'کامنت #' . $cid;

        $now = current_time('mysql', true);
        $user = get_current_user_id();
        $inserted = $wpdb->insert("{$p}mlt_tasks", [
            'title'             => $title,
            'description'       => $body !== $title ? $body : '',
            'owner'             => $c['task_owner'], // same owner as the task the comment was on
            'kind'              => 'new',
            'source_comment_id' => $cid,
            'created_by'        => $user,
            'created_at'        => $now,
            'updated_by'        => $user,
            'updated_at'        => $now,
        ]);
        if (!$inserted) {
            $wpdb->update("{$p}mlt_comments", ['flagged' => 1], ['id' => $cid]); // give the flag back
            return new WP_Error('mlt_db_error', 'ذخیره انجام نشد.', ['status' => 500]);
        }
        $new_id = $wpdb->insert_id;
        // no title here: it is the first line of the comment, and comment text must not reach the log
        MLT_REST::log_activity($new_id, 'comment_convert', sprintf('تسک #%d از کامنت #%d روی «%s»', $new_id, $cid, $c['task_title']));
        return ['id' => $new_id, 'title' => $title];
    }

    public static function handle_convert()
    {
        $task_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $cid = isset($_POST['cid']) ? absint($_POST['cid']) : 0;
        MLT_Admin::verify('mlt_convert_comment_' . $cid);
        $result = self::convert($task_id, $cid);
        $notice = is_wp_error($result)
            ? ['type' => 'error', 'msg' => 'تبدیل انجام نشد: ' . $result->get_error_message()]
            : ['type' => 'ok', 'msg' => 'تسک «' . $result['title'] . '» ساخته شد و پرچم کامنت برداشته شد.'];
        MLT_Admin::redirect_with($notice, MLT_Admin::REPORTS_SLUG, [], '');
    }

    public static function render()
    {
        if (!current_user_can(MLT_CAP)) {
            wp_die('دسترسی ندارید.');
        }
        $rows = self::flagged();
        cache_users(array_unique(array_map('intval', wp_list_pluck($rows, 'author'))));
        ?>
        <div class="mlt" dir="rtl">
            <header class="mlt-head">
                <h1>گزارش‌ها</h1>
            </header>
            <?php MLT_Admin::render_notice(); ?>
            <h2 class="mlt-sub">کامنت‌های پرچم‌دار <span class="mlt-count"><?php echo esc_html(count($rows)); ?></span></h2>
            <p class="mlt-hint">با «تبدیل به تسک»، از کامنت یک تسک جدید ساخته می‌شود (با همان مسئولِ تسکی که کامنت روی آن بوده) و پرچم کامنت برداشته می‌شود. زمان‌ها به وقت <code dir="ltr"><?php echo esc_html(MLT_Time::ZONE); ?></code> است.</p>
            <?php if (!$rows) : ?>
                <p class="mlt-empty">کامنت پرچم‌داری نیست.</p>
            <?php endif; ?>
            <div class="mlt-flaglist">
                <?php foreach ($rows as $c) : ?>
                    <?php $author = get_userdata((int) $c['author']); ?>
                    <article id="mlt-c-<?php echo (int) $c['id']; ?>" class="mlt-card mlt-comment--flagged">
                        <h3>
                            <a href="<?php echo esc_url(add_query_arg(['page' => MLT_Admin::SLUG, 'comments' => (int) $c['task_id']], admin_url('admin.php')) . '#mlt-t-' . (int) $c['task_id']); ?>"><?php echo esc_html($c['task_title']); ?></a>
                        </h3>
                        <div class="mlt-c-head">
                            <strong><?php echo esc_html($author ? $author->display_name : 'کاربر حذف‌شده'); ?></strong>
                            <?php if ($c['agent'] !== null && $c['agent'] !== '') : ?>
                                <span class="mlt-tag"><?php echo esc_html($c['agent']); ?></span>
                            <?php endif; ?>
                            <time><?php echo esc_html(MLT_Time::format($c['created_at'])); ?></time>
                        </div>
                        <div class="mlt-c-body"><?php echo MLT_Admin::comment_html($c['body']); ?></div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mlt-convert">
                            <input type="hidden" name="action" value="mlt_convert_comment">
                            <input type="hidden" name="id" value="<?php echo (int) $c['task_id']; ?>">
                            <input type="hidden" name="cid" value="<?php echo (int) $c['id']; ?>">
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_convert_comment_' . (int) $c['id'])); ?>">
                            <button type="submit" class="mlt-btn">تبدیل به تسک</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
