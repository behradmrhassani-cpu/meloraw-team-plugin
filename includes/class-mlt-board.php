<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The board: tasks of the signed-in person by default (a switch shows everybody's), two columns (open / in
 * progress) and the finished ones folded below. A card shows the readable code (with a copy button), the labels
 * ("late", "incomplete"), the assignees, the fixed estimate and the due date, and carries the actions: start the
 * timer, change the status, edit, extend the estimate, comments, hand over to an agent, delete.
 */
class MLT_Board
{
    const LIST_STATUSES = ['open', 'in_progress'];

    // ---- page ----

    public static function render()
    {
        if (!current_user_can(MLT_CAP)) {
            wp_die('دسترسی ندارید.');
        }
        global $wpdb;
        $p = $wpdb->prefix;
        $me = MLT_Identity::current();
        $get = function ($name) {
            return isset($_GET[$name]) && is_string($_GET[$name]) ? sanitize_key(wp_unslash($_GET[$name])) : '';
        };

        $view = $get('view') === 'all' || !$me ? 'all' : 'mine';
        $assignee = in_array($get('assignee'), MLT_Identity::slugs(), true) ? $get('assignee') : '';
        $status = isset(MLT_Admin::LABELS['status'][$get('status')]) ? $get('status') : '';
        $incomplete = $get('incomplete') === '1';

        $where = ["kind IN ('phase','new')"];
        $params = [];
        $who = $view === 'mine' ? $me : $assignee; // server-side filter: only that person's tasks
        if ($who) {
            $where[] = "id IN (SELECT task_id FROM {$p}mlt_task_assignees WHERE who = %s)";
            $params[] = $who;
        }
        if ($status) {
            $where[] = 'status = %s';
            $params[] = $status;
        }
        if ($incomplete) {
            $where[] = 'estimate_minutes IS NULL';
        }
        $sql = "SELECT * FROM {$p}mlt_tasks WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC';
        $rows = $wpdb->get_results($params ? $wpdb->prepare($sql, $params) : $sql, ARRAY_A);

        $ctx = self::context($rows);
        $ctx['highlight'] = isset($_GET['added']) ? absint($_GET['added']) : (isset($_GET['edited']) ? absint($_GET['edited']) : 0);
        $ctx['open'] = isset($_GET['comments']) ? absint($_GET['comments']) : 0;

        $cols = ['open' => [], 'in_progress' => [], 'done' => []];
        foreach ($rows as $row) {
            $cols[$row['status']][] = $row;
        }
        foreach ($cols as &$list) {
            usort($list, [__CLASS__, 'compare']);
        }
        unset($list);

        $base = ['page' => MLT_Admin::SLUG];
        $link = function (array $args) use ($base) {
            return esc_url(add_query_arg($args + $base, admin_url('admin.php')));
        };
        ?>
        <div class="mlt" dir="rtl">
            <?php MLT_Admin::topbar(); ?>
            <header class="mlt-head">
                <h1>تسک‌های تیم MELORAW</h1>
                <?php if ($me) : ?>
                    <nav class="mlt-seg" aria-label="نمای برد">
                        <a href="<?php echo $link(['view' => 'mine']); ?>" class="<?php echo $view === 'mine' ? 'is-on' : ''; ?>">تسک‌های من</a>
                        <a href="<?php echo $link(['view' => 'all']); ?>" class="<?php echo $view === 'all' ? 'is-on' : ''; ?>">همه</a>
                    </nav>
                <?php endif; ?>
                <form method="get" class="mlt-filters">
                    <input type="hidden" name="page" value="<?php echo esc_attr(MLT_Admin::SLUG); ?>">
                    <input type="hidden" name="view" value="<?php echo esc_attr($view); ?>">
                    <?php if ($view === 'all') : ?>
                        <?php MLT_Admin::select('assignee', 'همه‌ی مسئول‌ها', $assignee, '', MLT_Admin::LABELS['owner']); ?>
                    <?php endif; ?>
                    <?php MLT_Admin::select('status', 'همه‌ی وضعیت‌ها', $status); ?>
                    <label class="mlt-check"><input type="checkbox" name="incomplete" value="1"<?php checked($incomplete); ?>> فقط ناقص</label>
                    <button class="mlt-btn mlt-btn--ghost">فیلتر</button>
                </form>
            </header>
            <?php MLT_Admin::render_notice(); ?>
            <?php if (!$me) : ?>
                <div class="mlt-notice mlt-notice--warn">حساب شما هنوز به هیچ‌یک از اعضای تیم وصل نشده است، برای همین همه‌ی تسک‌ها نشان داده می‌شود. ادمین باید در «تنظیمات ← هویت اعضا» حساب شما را وصل کند.</div>
            <?php endif; ?>
            <?php self::add_form($me); ?>
            <div class="mlt-board">
                <?php foreach (self::LIST_STATUSES as $st) : ?>
                    <section class="mlt-col mlt-col--<?php echo esc_attr($st); ?>">
                        <h2><?php echo esc_html(MLT_Admin::LABELS['status'][$st]); ?> <span class="mlt-count"><?php echo esc_html(count($cols[$st])); ?></span></h2>
                        <?php if (!$cols[$st]) : ?>
                            <p class="mlt-empty">تسکی نیست.</p>
                        <?php endif; ?>
                        <?php foreach ($cols[$st] as $t) : ?>
                            <?php self::card($t, $ctx); ?>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>
            <details class="mlt-done"<?php echo $status === 'done' || $ctx['highlight'] && isset($ctx['done_ids'][$ctx['highlight']]) ? ' open' : ''; ?>>
                <summary>انجام‌شده <span class="mlt-count"><?php echo esc_html(count($cols['done'])); ?></span></summary>
                <div class="mlt-done-grid">
                    <?php foreach ($cols['done'] as $t) : ?>
                        <?php self::card($t, $ctx); ?>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
        <?php
    }

    /** Everything the cards of these rows need, fetched in a few queries. */
    public static function context(array $rows)
    {
        global $wpdb;
        $ids = array_map('intval', wp_list_pluck($rows, 'id'));
        $ctx = ['assignees' => MLT_Taskmeta::assignees_for($ids), 'comments' => [], 'scores' => [], 'done_ids' => [], 'highlight' => 0, 'open' => 0];
        $active = MLT_Timer::active(get_current_user_id());
        $ctx['active_task'] = $active ? (int) $active['entry']['task_id'] : 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'done') {
                $ctx['done_ids'][(int) $r['id']] = true;
            }
        }
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '%d'));
            $found = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mlt_comments WHERE task_id IN ($in) ORDER BY id ASC", $ids), ARRAY_A);
            cache_users(array_unique(array_map('intval', wp_list_pluck($found, 'author'))));
            foreach ($found as $c) {
                $ctx['comments'][(int) $c['task_id']][] = $c;
            }
            $scores = $wpdb->get_results($wpdb->prepare("SELECT task_id, points, tier, late FROM {$wpdb->prefix}mlt_scores WHERE task_id IN ($in)", $ids), ARRAY_A);
            foreach ($scores as $s) {
                $ctx['scores'][(int) $s['task_id']] = $s;
            }
        }
        return $ctx;
    }

    /** Late first, then high priority, then the earlier due date, then the newer task. */
    public static function compare(array $a, array $b)
    {
        $late = function ($t) {
            return $t['due_date'] !== null && $t['due_date'] < MLT_Time::today() ? 0 : 1;
        };
        $prio = function ($t) {
            return -(MLT_Score::WEIGHT[$t['priority']] ?? 1);
        };
        return [$late($a), $prio($a), $a['due_date'] ?? '9999-99-99', -(int) $a['id']] <=> [$late($b), $prio($b), $b['due_date'] ?? '9999-99-99', -(int) $b['id']];
    }

    // ---- card ----

    public static function minutes($m)
    {
        $m = (int) $m;
        return $m >= 60 ? ($m % 60 ? intdiv($m, 60) . 'h ' . ($m % 60) . 'm' : intdiv($m, 60) . 'h') : $m . 'm';
    }

    public static function card(array $t, array $ctx)
    {
        $tid = (int) $t['id'];
        $late = $t['due_date'] !== null && $t['status'] !== 'done' && $t['due_date'] < MLT_Time::today();
        $incomplete = $t['estimate_minutes'] === null;
        $assignees = $ctx['assignees'][$tid] ?? [$t['owner']];
        $list = $ctx['comments'][$tid] ?? [];
        $flagged = count(array_filter($list, function ($c) { return (int) $c['flagged']; }));
        $score = $ctx['scores'][$tid] ?? null;
        $post_url = esc_url(admin_url('admin-post.php'));
        $classes = 'mlt-card mlt-status--' . $t['status'] . ($late ? ' is-late' : '')
            . ($tid === $ctx['highlight'] ? ' mlt-card--new' : '') . ($tid === $ctx['open'] ? ' mlt-commenting' : '');
        ?>
        <article id="mlt-t-<?php echo $tid; ?>" class="<?php echo esc_attr($classes); ?>" data-criteria="<?php echo esc_attr((string) $t['done_criteria']); ?>" data-late="<?php echo $late ? '1' : '0'; ?>">
            <div class="mlt-card-top">
                <?php if ($t['code']) : ?>
                    <button type="button" class="mlt-code" data-copy="<?php echo esc_attr($t['code']); ?>" title="کپی کد تسک" dir="ltr"><?php echo esc_html($t['code']); ?></button>
                <?php endif; ?>
                <?php if ($late) : ?><span class="mlt-badge mlt-badge--late">دیر شده</span><?php endif; ?>
                <?php if ($incomplete) : ?><span class="mlt-badge mlt-badge--incomplete" title="زمان تقریبی ثبت نشده">ناقص</span><?php endif; ?>
                <?php if ($score) : ?>
                    <?php if ($score['tier'] === 'no_timer') : ?>
                        <span class="mlt-badge mlt-badge--notimer">بدون زمان‌سنج</span>
                    <?php else : ?>
                        <span class="mlt-badge mlt-badge--score" title="<?php echo esc_attr(MLT_Score::TIERS[$score['tier']] ?? ''); ?>">+<?php echo esc_html((int) $score['points']); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
                <span class="mlt-prio mlt-prio--<?php echo esc_attr($t['priority']); ?>" title="اولویت <?php echo esc_attr(MLT_Admin::label('priority', $t['priority'])); ?>">● <?php echo esc_html(MLT_Admin::label('priority', $t['priority'])); ?></span>
            </div>
            <h3><?php echo esc_html($t['title']); ?></h3>
            <?php if ($t['description'] !== '') : ?>
                <p class="mlt-desc"><?php echo esc_html(wp_trim_words($t['description'], 24)); ?></p>
            <?php endif; ?>
            <?php if ($t['status'] === 'done' && $t['done_criteria']) : ?>
                <p class="mlt-criteria"><strong>معیار انجام:</strong> <?php echo esc_html($t['done_criteria']); ?></p>
            <?php endif; ?>
            <div class="mlt-meta">
                <?php foreach ($assignees as $who) : ?>
                    <span class="mlt-chip<?php echo $who === $t['owner'] ? ' is-main' : ''; ?>" title="<?php echo $who === $t['owner'] ? 'مسئول اصلی' : 'مسئول'; ?>"><?php echo esc_html(MLT_Admin::label('owner', $who)); ?></span>
                <?php endforeach; ?>
                <?php if (!$incomplete) : ?>
                    <span class="mlt-fact" dir="ltr" title="زمان تقریبی (ثابت)">⏱ <?php echo esc_html(self::minutes($t['estimate_minutes'])); ?><?php echo (int) $t['estimate_original'] !== (int) $t['estimate_minutes'] ? ' (' . esc_html(self::minutes($t['estimate_original'])) . ')' : ''; ?></span>
                <?php endif; ?>
                <?php if ($t['due_date'] !== null) : ?>
                    <span class="mlt-fact<?php echo $late ? ' is-late' : ''; ?>" dir="ltr" title="سررسید (UTC)">📅 <?php echo esc_html($t['due_date']); ?></span>
                <?php endif; ?>
                <span class="mlt-fact mlt-fact--bucket"><?php echo esc_html(MLT_Admin::label('bucket', $t['bucket'])); ?></span>
            </div>
            <div class="mlt-actions">
                <?php if ($t['status'] !== 'done') : ?>
                    <?php if ($ctx['active_task'] === 0) : ?>
                        <form method="post" action="<?php echo $post_url; ?>" class="mlt-inline">
                            <input type="hidden" name="action" value="mlt_timer">
                            <input type="hidden" name="do" value="start">
                            <input type="hidden" name="id" value="<?php echo $tid; ?>">
                            <input type="hidden" name="back" value="<?php echo esc_attr(MLT_Admin::SLUG); ?>">
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_timer')); ?>">
                            <button type="submit" class="mlt-btn mlt-btn--sm">▶ شروع</button>
                        </form>
                    <?php elseif ($ctx['active_task'] === $tid) : ?>
                        <span class="mlt-fact is-on">⏱ تایمر این تسک فعال است</span>
                    <?php else : ?>
                        <button type="button" class="mlt-btn mlt-btn--sm" disabled title="اول تایمر فعالت را تمام کن">▶ شروع</button>
                    <?php endif; ?>
                <?php endif; ?>
                <form method="post" action="<?php echo $post_url; ?>" class="mlt-status">
                    <input type="hidden" name="action" value="mlt_set_status">
                    <input type="hidden" name="id" value="<?php echo $tid; ?>">
                    <input type="hidden" name="late_reason" value="">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_set_status_' . $tid)); ?>">
                    <?php MLT_Admin::select('status', null, $t['status'], 'class="mlt-status-select" data-current="' . esc_attr($t['status']) . '" aria-label="تغییر وضعیت"'); ?>
                    <?php MLT_Admin::notify_block('s' . $tid, 'status', ['title' => $t['title'], 'owner' => MLT_Admin::label('owner', $t['owner']), 'status' => MLT_Admin::label('status', $t['status']), 'link' => MLT_Telegram::task_link($tid)], true); ?>
                </form>
                <button type="button" class="mlt-link-btn" onclick="this.closest('.mlt-card').classList.toggle('mlt-editing')">ویرایش</button>
                <button type="button" class="mlt-link-btn<?php echo $flagged ? ' mlt-has-flag' : ''; ?>" onclick="this.closest('.mlt-card').classList.toggle('mlt-commenting')">کامنت‌ها (<?php echo esc_html(count($list)); ?>)</button>
                <button type="button" class="mlt-link-btn" onclick="this.closest('.mlt-card').classList.toggle('mlt-handing')">📋 تحویل به ایجنت</button>
                <form method="post" action="<?php echo $post_url; ?>" class="mlt-del" onsubmit="return confirm('این تسک و کامنت‌ها و زمان‌های ثبت‌شده‌اش برای همیشه حذف می‌شود. مطمئنی؟');">
                    <input type="hidden" name="action" value="mlt_delete_task">
                    <input type="hidden" name="id" value="<?php echo $tid; ?>">
                    <?php wp_nonce_field('mlt_delete_task_' . $tid); ?>
                    <button type="submit" class="mlt-link-btn mlt-link-btn--danger">حذف</button>
                </form>
            </div>

            <div class="mlt-handoff">
                <textarea id="mlt-h-<?php echo $tid; ?>" readonly rows="12" dir="rtl" aria-label="متن تحویل به ایجنت"><?php echo esc_textarea(self::handoff_text($t, $assignees)); ?></textarea>
                <button type="button" class="mlt-btn mlt-btn--sm" data-copy-from="#mlt-h-<?php echo $tid; ?>">کپی متن</button>
                <span class="mlt-hint">بدون هیچ رمز یا توکن؛ ایجنت با اعتبار خودِ تو (Application Password) کار می‌کند.</span>
            </div>

            <div class="mlt-editpanel">
                <?php self::edit_panel($t, $assignees, $post_url); ?>
            </div>

            <div class="mlt-comments">
                <p class="mlt-tz">زمان‌ها به وقت <code dir="ltr">UTC</code></p>
                <?php foreach ($list as $c) : $author = get_userdata((int) $c['author']); ?>
                    <div class="mlt-comment<?php echo (int) $c['flagged'] ? ' mlt-comment--flagged' : ''; ?>">
                        <div class="mlt-c-head">
                            <strong><?php echo esc_html($author ? $author->display_name : 'کاربر حذف‌شده'); ?></strong>
                            <?php if ($c['agent'] !== null && $c['agent'] !== '') : ?>
                                <span class="mlt-tag"><?php echo esc_html($c['agent']); ?></span>
                            <?php endif; ?>
                            <time><?php echo esc_html(MLT_Time::format($c['created_at'])); ?></time>
                            <?php if ((int) $c['flagged']) : ?>
                                <span class="mlt-flagged">پرچم‌دار</span>
                            <?php else : ?>
                                <form method="post" action="<?php echo $post_url; ?>" class="mlt-flag">
                                    <input type="hidden" name="action" value="mlt_flag_comment">
                                    <input type="hidden" name="id" value="<?php echo $tid; ?>">
                                    <input type="hidden" name="cid" value="<?php echo (int) $c['id']; ?>">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_flag_comment_' . (int) $c['id'])); ?>">
                                    <button type="submit" class="mlt-link-btn">پرچم</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="mlt-c-body"><?php echo MLT_Admin::comment_html($c['body']); ?></div>
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
                    <?php MLT_Admin::notify_block('c' . $tid, 'comment', ['title' => $t['title'], 'owner' => MLT_Admin::label('owner', $t['owner']), 'status' => MLT_Admin::label('status', $t['status']), 'link' => MLT_Telegram::task_link($tid)]); ?>
                    <button type="submit" class="mlt-btn mlt-btn--sm">ثبت کامنت</button>
                </form>
            </div>
        </article>
        <?php
    }

    /** Edit form + the estimate (fill in once / extend with a reason). */
    private static function edit_panel(array $t, array $assignees, $post_url)
    {
        $tid = (int) $t['id'];
        $exts = $t['estimate_minutes'] === null ? [] : MLT_Estimate::extensions($tid);
        ?>
        <form method="post" action="<?php echo $post_url; ?>" class="mlt-form mlt-edit-form">
            <input type="hidden" name="action" value="mlt_edit_task">
            <input type="hidden" name="id" value="<?php echo $tid; ?>">
            <input type="hidden" name="assignees_marker" value="1">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_edit_task_' . $tid)); ?>">
            <label class="mlt-field mlt-field--wide">عنوان
                <input type="text" name="title" required maxlength="255" value="<?php echo esc_attr($t['title']); ?>">
            </label>
            <label class="mlt-field mlt-field--wide">توضیح
                <textarea name="description" rows="3"><?php echo esc_textarea($t['description']); ?></textarea>
            </label>
            <label class="mlt-field">مسئول اصلی <?php MLT_Admin::select('owner', null, $t['owner']); ?></label>
            <label class="mlt-field">اولویت <?php MLT_Admin::select('priority', null, $t['priority']); ?></label>
            <label class="mlt-field">دسته <?php MLT_Admin::select('bucket', null, $t['bucket']); ?></label>
            <fieldset class="mlt-field mlt-field--wide mlt-assignees">
                <legend>مسئول‌ها (مسئول اصلی همیشه جزوشان است)</legend>
                <?php foreach (MLT_Admin::LABELS['owner'] as $slug => $label) : ?>
                    <label class="mlt-check"><input type="checkbox" name="assignees[]" value="<?php echo esc_attr($slug); ?>"<?php checked(in_array($slug, $assignees, true)); ?>> <?php echo esc_html($label); ?></label>
                <?php endforeach; ?>
            </fieldset>
            <label class="mlt-field">سررسید (UTC)
                <input type="date" name="due_date" dir="ltr" value="<?php echo esc_attr((string) $t['due_date']); ?>">
            </label>
            <?php if ($t['estimate_minutes'] === null) : ?>
                <label class="mlt-field">زمان تقریبی (دقیقه، فقط یک‌بار)
                    <input type="number" name="estimate_minutes" min="<?php echo (int) MLT_Taskmeta::ESTIMATE_MIN; ?>" max="<?php echo (int) MLT_Taskmeta::ESTIMATE_MAX; ?>" step="1" placeholder="خالی = بعداً">
                </label>
            <?php endif; ?>
            <label class="mlt-field mlt-field--wide">معیار انجام (اختیاری، تا ۲۰۰۰ نویسه؛ هنگام done نشان داده می‌شود)
                <textarea name="done_criteria" rows="2" maxlength="<?php echo (int) MLT_Taskmeta::CRITERIA_MAX; ?>"><?php echo esc_textarea((string) $t['done_criteria']); ?></textarea>
            </label>
            <div class="mlt-field--wide">
                <button type="submit" class="mlt-btn">ذخیره</button>
                <button type="button" class="mlt-btn mlt-btn--ghost" onclick="this.closest('.mlt-card').classList.remove('mlt-editing')">انصراف</button>
            </div>
        </form>
        <?php if ($t['estimate_minutes'] !== null) : ?>
            <form method="post" action="<?php echo $post_url; ?>" class="mlt-form mlt-extend-form">
                <input type="hidden" name="action" value="mlt_extend">
                <input type="hidden" name="id" value="<?php echo $tid; ?>">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('mlt_extend_' . $tid)); ?>">
                <p class="mlt-hint mlt-field--wide">
                    زمان تقریبی ثابت است: <strong dir="ltr"><?php echo esc_html(self::minutes($t['estimate_original'])); ?></strong> اولیه،
                    <strong dir="ltr"><?php echo esc_html(self::minutes($t['estimate_minutes'])); ?></strong> فعلی. فقط با «درخواست تمدید» و یک دلیل می‌شود بیشترش کرد؛ زمان واقعی هرگز خودکار تخمین‌ها را عوض نمی‌کند.
                </p>
                <label class="mlt-field">مجموع جدید (دقیقه)
                    <input type="number" name="estimate_minutes" required min="<?php echo (int) $t['estimate_minutes'] + 1; ?>" max="<?php echo (int) MLT_Taskmeta::ESTIMATE_MAX; ?>" step="1">
                </label>
                <label class="mlt-field mlt-field--wide">دلیل تمدید (حداقل <?php echo (int) MLT_Estimate::REASON_MIN; ?> نویسه)
                    <textarea name="reason" rows="2" required minlength="<?php echo (int) MLT_Estimate::REASON_MIN; ?>" maxlength="<?php echo (int) MLT_Estimate::REASON_MAX; ?>"></textarea>
                </label>
                <div class="mlt-field--wide"><button type="submit" class="mlt-btn mlt-btn--ghost">درخواست تمدید</button></div>
                <?php if ($exts) : ?>
                    <ul class="mlt-exts mlt-field--wide">
                        <?php foreach ($exts as $x) : ?>
                            <li><span dir="ltr"><?php echo esc_html(self::minutes($x['old_minutes']) . ' → ' . self::minutes($x['new_minutes'])); ?></span> · <time dir="ltr"><?php echo esc_html(MLT_Time::format($x['created_at'])); ?> UTC</time> · <?php echo esc_html($x['user']); ?> — <?php echo esc_html($x['reason']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </form>
        <?php endif; ?>
        <?php
    }

    // ---- hand over to an agent ----

    /** Plain text a person pastes to their own agent. No token, password or Application Password in it, ever. */
    public static function handoff_text(array $t, array $assignees)
    {
        $api = untrailingslashit(rest_url(MLT_REST::NS));
        $ref = $t['code'] ?: (string) $t['id'];
        $names = implode('، ', array_map(function ($s) { return MLT_Admin::label('owner', $s); }, $assignees));
        $est = $t['estimate_minutes'] === null ? 'ثبت نشده (تسک ناقص است؛ زمان تقریبی را از من بپرس)' : $t['estimate_minutes'] . ' دقیقه';
        $lines = [
            '📋 تحویل تسک به ایجنت',
            'کد: ' . $ref,
            'عنوان: ' . $t['title'],
            'شرح: ' . ($t['description'] !== '' ? $t['description'] : '—'),
            'معیار انجام: ' . ($t['done_criteria'] ?: '—'),
            'مسئول‌ها: ' . $names . ' (مسئول اصلی: ' . MLT_Admin::label('owner', $t['owner']) . ')',
            'اولویت: ' . MLT_Admin::label('priority', $t['priority']),
            'سررسید: ' . ($t['due_date'] ?: '—') . ($t['due_date'] ? ' (UTC)' : ''),
            'زمان تقریبی: ' . $est,
            '',
            'آدرس پایه‌ی API: ' . $api . '  (فقط HTTPS؛ همه‌ی زمان‌ها میلادی و UTC)',
            'احراز هویت: Basic Auth با نام کاربری وردپرسِ خودم و Application Password خودم (همان که در محیط تو تنظیم شده؛',
            'نامش با Claude- یا Hermes- شروع می‌شود). آن را هرگز چاپ، لاگ یا در کامنت تکرار نکن.',
            '',
            'مراحل:',
            '1) گرفتن تسک:  GET ' . $api . '/tasks/' . $ref . '   (کامنت‌ها: GET .../tasks/' . $ref . '/comments)',
            '2) شروع تایمر: POST ' . $api . '/tasks/' . $ref . '/timer/start',
            '3) کامنت پیشرفت: POST .../tasks/' . $ref . '/comments  با {"body": "..."}',
            '   اگر کار قطع شد: POST .../tasks/' . $ref . '/timer/pause  با {"reason": "rest|food|offhours|waiting|interrupted|other_task"}',
            '   و برای ادامه: POST .../tasks/' . $ref . '/timer/resume',
            '4) تغییر وضعیت: PATCH .../tasks/' . $ref . '  با {"status": "in_progress"} یا وقتی معیار انجام برقرار شد {"status": "done"}',
            '   (با done، تایمر خودت هم خودکار تمام می‌شود)',
            '5) پایان تایمر: POST .../tasks/' . $ref . '/timer/finish   (اگر done نزدی)',
            '',
            'قواعد: با اعتبار خودم کار کن؛ زمان تقریبی را تغییر نده (افزایشش فقط با POST .../extend و یک دلیل حداقل ۱۰ نویسه، آن هم فقط اگر من بخواهم)؛',
            'چیزی به تلگرام نفرست؛ تایمر را دقیق نگه دار و پاز را با دلیل بزن.',
        ];
        return implode("\n", $lines);
    }

    // ---- add form ----

    private static function add_form($me)
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
                <label class="mlt-field">مسئول اصلی <?php MLT_Admin::select('owner', null, $me ?? 'you'); ?></label>
                <label class="mlt-field">اولویت <?php MLT_Admin::select('priority', null, 'med'); ?></label>
                <label class="mlt-field">دسته <?php MLT_Admin::select('bucket', null, 'normal'); ?></label>
                <label class="mlt-field">وضعیت <?php MLT_Admin::select('status', null, 'open'); ?></label>
                <label class="mlt-field">زمان تقریبی (دقیقه، حداقل ۵) *
                    <input type="number" name="estimate_minutes" required min="<?php echo (int) MLT_Taskmeta::ESTIMATE_MIN; ?>" max="<?php echo (int) MLT_Taskmeta::ESTIMATE_MAX; ?>" step="1" value="30">
                </label>
                <label class="mlt-field">سررسید (اختیاری، UTC)
                    <input type="date" name="due_date" dir="ltr">
                </label>
                <fieldset class="mlt-field mlt-field--wide mlt-assignees">
                    <legend>مسئول‌های بیشتر (اختیاری؛ مسئول اصلی همیشه جزوشان است)</legend>
                    <?php foreach (MLT_Admin::LABELS['owner'] as $slug => $label) : ?>
                        <label class="mlt-check"><input type="checkbox" name="assignees[]" value="<?php echo esc_attr($slug); ?>"> <?php echo esc_html($label); ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <label class="mlt-field mlt-field--wide">معیار انجام (اختیاری، تا ۲۰۰۰ نویسه)
                    <textarea name="done_criteria" rows="2" maxlength="<?php echo (int) MLT_Taskmeta::CRITERIA_MAX; ?>"></textarea>
                </label>
                <div class="mlt-field--wide">
                    <?php MLT_Admin::notify_block('add', 'task', ['link' => add_query_arg('page', MLT_Admin::SLUG, admin_url('admin.php'))]); ?>
                </div>
                <div class="mlt-field--wide"><button type="submit" class="mlt-btn">ذخیره</button></div>
            </form>
        </details>
        <?php
    }
}
