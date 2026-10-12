<?php
if (!defined('ABSPATH')) {
    exit;
}

class MLT_REST
{
    const NS = 'meloraw-tasks/v1';

    const ENUMS = [
        'status'   => ['open', 'in_progress', 'done'],
        'bucket'   => ['blocker', 'boost', 'normal'],
        'priority' => ['high', 'med', 'low'],
        'owner'    => ['you', 'maral', 'chakra', 'auto'],
        'kind'     => ['phase', 'new', 'routine', 'idea'],
    ];

    // Routines and ideas are managed by their own endpoints/importer, not created here.
    const WRITABLE_KINDS = ['phase', 'new'];

    const TITLE_MAX = 255;
    const BODY_MAX = 10000;
    const DESCRIPTION_MAX = 20000;

    const RATE_LIMIT = 120; // REST calls per user per window (on top of the Telegram limit)
    const RATE_WINDOW = 60; // seconds

    /** Sent on every plugin response (REST and admin pages): no indexing, no caching anywhere, LiteSpeed included. */
    const NO_CACHE_HEADERS = [
        'X-Robots-Tag'              => 'noindex, nofollow',
        'Cache-Control'             => 'no-store, private',
        'X-LiteSpeed-Cache-Control' => 'no-cache',
    ];

    public static function register_routes()
    {
        $filters = [];
        foreach (self::ENUMS as $field => $values) {
            $filters[$field] = ['type' => 'string', 'enum' => $values];
        }
        $filters['assignee'] = ['type' => 'string', 'enum' => self::ENUMS['owner']];
        $filters['mine'] = ['type' => 'boolean'];
        $filters['incomplete'] = ['type' => 'boolean'];

        $write = [
            'title'       => ['type' => 'string'],
            'description' => ['type' => 'string'],
        ];
        foreach (['status', 'bucket', 'priority', 'owner'] as $field) {
            $write[$field] = ['type' => 'string', 'enum' => self::ENUMS[$field]];
        }
        $write['kind'] = ['type' => 'string', 'enum' => self::WRITABLE_KINDS];
        $write['estimate_minutes'] = ['type' => 'integer'];
        $write['due_date'] = ['type' => ['string', 'null']];
        $write['done_criteria'] = ['type' => ['string', 'null']];
        $write['assignees'] = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::ENUMS['owner']]];
        $write['late_reason'] = ['type' => 'string']; // optional, only read when the task is set to done

        $id  = '(?P<id>\d+|' . MLT_Taskmeta::CODE_PATTERN . ')'; // numeric id or code (MH-3)
        $cid = '(?P<cid>\d+)';

        self::route('/tasks', [
            ['GET', 'get_tasks', $filters],
            ['POST', 'create_task', $write],
        ]);
        self::route("/tasks/$id", [
            ['GET', 'get_task'],
            ['PATCH', 'update_task', $write],
            ['DELETE', 'delete_task'],
        ]);
        self::route("/tasks/$id/comments", [
            ['GET', 'get_comments'],
            ['POST', 'create_comment', ['body' => ['type' => 'string', 'required' => true]]],
        ]);
        self::route("/tasks/$id/comments/$cid/flag", [
            ['POST', 'flag_comment'],
        ]);

        self::route('/timer', [
            ['GET', 'get_timer'],
        ]);
        self::route("/tasks/$id/time", [
            ['GET', 'get_time'],
        ]);
        self::route("/tasks/$id/timer/start", [['POST', 'timer_start']]);
        self::route("/tasks/$id/timer/pause", [['POST', 'timer_pause', ['reason' => ['type' => 'string', 'required' => true]]]]);
        self::route("/tasks/$id/timer/resume", [['POST', 'timer_resume']]);
        self::route("/tasks/$id/timer/finish", [['POST', 'timer_finish', ['ended_at' => ['type' => 'string']]]]);
        self::route("/tasks/$id/extend", [
            ['POST', 'extend_task', [
                'estimate_minutes' => ['type' => 'integer', 'required' => true],
                'reason'           => ['type' => 'string', 'required' => true],
            ]],
        ]);
        self::route("/tasks/$id/notify", [
            ['POST', 'notify_task', ['text' => ['type' => 'string']]],
        ]);
        self::route('/notify', [
            ['POST', 'notify_free', ['text' => ['type' => 'string', 'required' => true]]],
        ]);
        self::route('/me', [
            ['GET', 'get_me'],
        ]);
        self::route('/export', [
            ['GET', 'export_all'],
        ]);
        self::route('/import', [
            ['POST', 'import_all', ['dry_run' => ['type' => 'boolean']]],
        ]);
        self::route('/routines', [
            ['GET', 'get_routines', ['period' => ['type' => 'string', 'enum' => array_keys(MLT_Routines::FREQS)]]],
        ]);
        self::route('/routines/(?P<key>[A-Za-z0-9._-]{1,32})', [
            ['PATCH', 'update_routine', [
                'status'           => ['type' => 'string', 'enum' => self::ENUMS['status']],
                'period_key'       => ['type' => 'string'],
                'estimate_minutes' => ['type' => 'integer'],
                'done_criteria'    => ['type' => ['string', 'null']],
                'recurrence_until' => ['type' => ['string', 'null']],
                'stopped'          => ['type' => 'boolean'],
            ]],
        ]);

        add_filter('rest_request_before_callbacks', [__CLASS__, 'guard'], 10, 3);
        add_filter('rest_request_after_callbacks', [__CLASS__, 'localize_error'], 10, 3);
        add_filter('rest_post_dispatch', [__CLASS__, 'add_headers'], 10, 3);
    }

    private static function route($path, array $handlers)
    {
        $endpoints = [];
        foreach ($handlers as $h) {
            $endpoints[] = [
                'methods'             => $h[0],
                'callback'            => [__CLASS__, $h[1]],
                'permission_callback' => [__CLASS__, 'can_manage'],
                'args'                => $h[2] ?? [],
                'show_in_index'       => false,
            ];
        }
        register_rest_route(self::NS, $path, $endpoints);
    }

    public static function can_manage()
    {
        return current_user_can(MLT_CAP) ? true : self::forbidden();
    }

    private static function forbidden()
    {
        // Generic error only; no detail for unauthenticated callers.
        return new WP_Error('mlt_forbidden', 'دسترسی ندارید.', ['status' => rest_authorization_required_code()]);
    }

    /**
     * WordPress validates params before permission_callback, so a validation error
     * (which lists allowed values) would reach unauthenticated callers. Replace it.
     * Callers with access are also counted against the per-user rate limit.
     */
    public static function guard($response, $handler, $request)
    {
        if (!self::is_ours($request)) {
            return $response;
        }
        if (!current_user_can(MLT_CAP)) {
            return self::forbidden();
        }
        $wait = MLT_RateLimit::hit('rg', get_current_user_id(), self::RATE_LIMIT, self::RATE_WINDOW);
        if ($wait) {
            return new WP_Error('mlt_rate_limited', 'تعداد درخواست‌ها بیش از حد مجاز است؛ ' . $wait . ' ثانیه دیگر دوباره امتحان کن.', ['status' => 429, 'retry_after' => $wait]);
        }
        return $response;
    }

    private static function is_ours($request)
    {
        return strpos($request->get_route(), '/' . self::NS) === 0;
    }

    public static function add_headers($response, $server, $request)
    {
        if (self::is_ours($request)) {
            foreach (self::NO_CACHE_HEADERS as $name => $value) {
                $response->header($name, $value);
            }
            if ($response->is_error()) {
                $data = $response->get_data();
                if (is_array($data) && isset($data['data']['retry_after'])) {
                    $response->header('Retry-After', (string) (int) $data['data']['retry_after']);
                }
                if (is_array($data) && strpos((string) ($data['code'] ?? ''), 'mlt_') !== 0) {
                    $status = $response->get_status();
                    list($code, $message, $fields) = self::persian_error((string) ($data['code'] ?? ''), $status, $data['data']['params'] ?? []);
                    $response->set_data(['code' => $code, 'message' => $message, 'data' => ['status' => $status, 'fields' => $fields]]);
                }
            }
        }
        return $response;
    }

    /** WordPress core errors (invalid/missing param, bad JSON...) are English; replace them with Persian ones. */
    public static function localize_error($response, $handler, $request)
    {
        if (!is_wp_error($response) || !self::is_ours($request) || strpos($response->get_error_code(), 'mlt_') === 0) {
            return $response;
        }
        $data = $response->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
        list($code, $message, $fields) = self::persian_error($response->get_error_code(), $status, is_array($data) ? ($data['params'] ?? []) : []);
        return new WP_Error($code, $message, ['status' => $status, 'fields' => $fields]);
    }

    private static function persian_error($code, $status, $params)
    {
        $params = is_array($params) ? $params : [];
        if ($code === 'rest_invalid_param') {
            $fields = array_map('strval', array_keys($params));
            return ['mlt_invalid_param', 'مقدار این فیلدها نامعتبر است: ' . implode('، ', $fields), $fields];
        }
        if ($code === 'rest_missing_callback_param') {
            $fields = array_map('strval', array_values($params));
            return ['mlt_missing_param', 'این فیلدهای الزامی ارسال نشده‌اند: ' . implode('، ', $fields), $fields];
        }
        if ($code === 'rest_invalid_json') {
            return ['mlt_invalid_json', 'بدنه‌ی درخواست JSON معتبر نیست.', []];
        }
        if ($status === 401 || $status === 403) {
            return ['mlt_forbidden', 'دسترسی ندارید.', []];
        }
        if ($status === 404) {
            return ['mlt_not_found', 'پیدا نشد.', []];
        }
        return $status < 500 ? ['mlt_bad_request', 'درخواست نامعتبر است.', []] : ['mlt_error', 'خطای سرور.', []];
    }

    // ---- tasks ----

    /** Numeric id of the task named in the URL: "42" or a code such as "MH-3"; 0 if there is none. */
    private static function task_id(WP_REST_Request $request)
    {
        return MLT_Taskmeta::resolve($request['id']);
    }

    public static function get_me()
    {
        $slug = MLT_Identity::current();
        $user = wp_get_current_user();
        return [
            'user'     => $user->user_login,
            'identity' => $slug,
            'prefix'   => $slug ? MLT_Identity::prefix($slug) : null,
            'agent'    => self::current_agent(),
            'today'    => MLT_Time::today(),
        ];
    }

    public static function get_tasks(WP_REST_Request $request)
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $where = [];
        $params = [];
        foreach (array_keys(self::ENUMS) as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $where[] = "$field = %s";
                $params[] = $value;
            }
        }
        $assignee = $request->get_param('assignee');
        if ($request->get_param('mine')) {
            $assignee = MLT_Identity::current();
            if ($assignee === null) {
                return new WP_Error('mlt_no_identity', 'حساب شما در تنظیمات افزونه به هیچ‌یک از اعضای تیم نگاشت نشده است؛ ادمین باید در «تنظیمات» نگاشتش کند.', ['status' => 409]);
            }
        }
        if ($assignee !== null) {
            $where[] = "id IN (SELECT task_id FROM {$p}mlt_task_assignees WHERE who = %s)";
            $params[] = $assignee;
        }
        if ($request->get_param('incomplete')) {
            $where[] = "estimate_minutes IS NULL AND kind IN ('phase','new')"; // routines: GET /routines, incomplete flag
        }

        $sql = "SELECT * FROM {$p}mlt_tasks";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql = $params ? $wpdb->prepare($sql, $params) : $sql;
        }
        $rows = $wpdb->get_results($sql . ' ORDER BY id DESC', ARRAY_A);

        return rest_ensure_response(self::format_tasks($rows));
    }

    public static function get_task(WP_REST_Request $request)
    {
        $row = self::find_task(self::task_id($request));
        if (!$row) {
            return self::not_found();
        }
        $task = self::format_task($row);
        $task['extensions'] = MLT_Estimate::extensions((int) $row['id']);
        return $task;
    }

    // ---- timer (always the caller's own) ----

    public static function get_timer()
    {
        return MLT_Timer::present_active(get_current_user_id());
    }

    public static function get_time(WP_REST_Request $request)
    {
        $id = self::task_id($request);
        return self::find_task($id) ? MLT_Timer::task_time($id) : self::not_found();
    }

    private static function timer_result($result)
    {
        return is_wp_error($result) ? $result : MLT_Timer::present_active(get_current_user_id());
    }

    public static function timer_start(WP_REST_Request $request)
    {
        return self::timer_result(MLT_Timer::start(self::task_id($request)));
    }

    public static function timer_pause(WP_REST_Request $request)
    {
        return self::timer_result(MLT_Timer::pause(self::task_id($request), $request['reason']));
    }

    public static function timer_resume(WP_REST_Request $request)
    {
        return self::timer_result(MLT_Timer::resume(self::task_id($request)));
    }

    public static function timer_finish(WP_REST_Request $request)
    {
        $result = MLT_Timer::finish(self::task_id($request), $request->get_param('ended_at'));
        return is_wp_error($result) ? $result : ['finished' => MLT_Timer::present_entry($result), 'summary' => MLT_Timer::summarize(MLT_Timer::entries_of_task((int) $result['task_id'], get_current_user_id()))];
    }

    /** Raises the fixed estimate; a reason is required and the original estimate stays. */
    public static function extend_task(WP_REST_Request $request)
    {
        $id = self::task_id($request);
        $result = MLT_Estimate::extend($id, $request['estimate_minutes'], $request['reason']);
        return is_wp_error($result) ? $result : self::format_task(self::find_task($id)) + ['extension' => $result];
    }

    public static function create_task(WP_REST_Request $request)
    {
        global $wpdb;
        $data = self::task_fields($request);
        if (is_wp_error($data)) {
            return $data;
        }
        if (!isset($data['title'])) {
            return self::bad_request('عنوان لازم است.');
        }
        if (!isset($data['estimate_minutes'])) {
            return self::bad_request('زمان تقریبی (estimate_minutes، حداقل ' . MLT_Taskmeta::ESTIMATE_MIN . ' دقیقه) لازم است.');
        }
        $assignees = $data['assignees'] ?? [];
        unset($data['assignees'], $data['late_reason']);
        $identity = MLT_Identity::current();

        $now = current_time('mysql', true);
        $user = get_current_user_id();
        $data += [
            'description'       => '',
            'owner'             => $identity ?? 'you', // a mapped user's own tasks are theirs by default
            'estimate_original' => $data['estimate_minutes'], // never changes afterwards; extensions are kept apart
            'created_by'        => $user,
            'created_at'        => $now,
            'updated_by'        => $user,
            'updated_at'        => $now,
        ];
        if (!$wpdb->insert("{$wpdb->prefix}mlt_tasks", $data)) {
            return self::db_error();
        }
        $id = $wpdb->insert_id;
        MLT_Taskmeta::set_assignees($id, $assignees, $data['owner']);
        $code = MLT_Taskmeta::assign_code($id, $identity ?? 'auto'); // prefix = who creates it, fixed for good
        self::log_activity($id, 'task_create', sprintf('«%s» [%s]', $data['title'], $code));

        $response = rest_ensure_response(self::format_task(self::find_task($id)));
        $response->set_status(201);
        return $response;
    }

    public static function update_task(WP_REST_Request $request)
    {
        global $wpdb;
        $id = self::task_id($request);
        $old = self::find_task($id);
        if (!$old) {
            return self::not_found();
        }
        $data = self::task_fields($request);
        if (is_wp_error($data)) {
            return $data;
        }
        if (!$data) {
            return self::bad_request('هیچ فیلدی برای ویرایش نیست.');
        }
        $assignees = $data['assignees'] ?? null;
        $late_reason = $data['late_reason'] ?? null;
        unset($data['assignees'], $data['late_reason']);

        if (isset($data['estimate_minutes'])) {
            if ($old['estimate_minutes'] !== null) {
                return self::bad_request('زمان تقریبی بعد از ثبت ثابت است؛ برای افزایشش «درخواست تمدید» (POST /tasks/{id}/extend) با دلیل بزن.');
            }
            $data['estimate_original'] = $data['estimate_minutes']; // filling an incomplete task in
        }
        $data['updated_by'] = get_current_user_id();
        $data['updated_at'] = current_time('mysql', true);
        if ($wpdb->update("{$wpdb->prefix}mlt_tasks", $data, ['id' => $id]) === false) {
            return self::db_error();
        }
        $changed = [];
        foreach ($data as $field => $value) {
            if (!in_array($field, ['status', 'updated_by', 'updated_at', 'estimate_original'], true) && (string) $value !== (string) $old[$field]) {
                $changed[] = $field;
            }
        }
        $owner = $data['owner'] ?? $old['owner'];
        if ($assignees !== null || $owner !== $old['owner']) {
            $keep = $assignees ?? MLT_Taskmeta::assignees_for([$id])[$id];
            list($added, $removed) = MLT_Taskmeta::set_assignees($id, $keep, $owner);
            if ($added || $removed) {
                $changed[] = 'assignees:' . ($added ? ' +' . implode(',+', $added) : '') . ($removed ? ' -' . implode(',-', $removed) : '');
            }
        }
        if ($changed) {
            self::log_activity($id, 'task_edit', sprintf('«%s»: %s', $old['title'], implode(', ', $changed)));
        }
        if (isset($data['estimate_original']) && $old['estimate_minutes'] === null) {
            self::log_activity($id, 'estimate_set', sprintf('«%s»: %d min', $old['title'], $data['estimate_minutes']));
        }
        if (isset($data['status']) && $data['status'] !== $old['status']) {
            self::log_activity($id, 'status_change', sprintf('«%s»: %s → %s', $old['title'], $old['status'], $data['status']));
        }
        return self::format_task(self::find_task($id));
    }

    public static function delete_task(WP_REST_Request $request)
    {
        global $wpdb;
        $id = self::task_id($request);
        $task = self::find_task($id);
        if (!$task) {
            return self::not_found();
        }
        foreach (['mlt_comments', 'mlt_task_assignees', 'mlt_estimate_extensions', 'mlt_time_entries'] as $table) {
            $wpdb->delete($wpdb->prefix . $table, ['task_id' => $id]);
        }
        $wpdb->delete("{$wpdb->prefix}mlt_tasks", ['id' => $id]);
        self::log_activity($id, 'task_delete', sprintf('«%s» [%s]', $task['title'], (string) $task['code']));
        return ['deleted' => true, 'id' => $id];
    }

    /** Sanitized writable fields present in the request (enums are already validated by the route args). */
    private static function task_fields(WP_REST_Request $request)
    {
        $params = $request->get_json_params() ?: $request->get_body_params();
        $data = [];

        if (array_key_exists('title', $params)) {
            $title = sanitize_text_field((string) $request['title']);
            if ($title === '') {
                return self::bad_request('عنوان خالی است.');
            }
            if (mb_strlen($title) > self::TITLE_MAX) {
                return self::bad_request('عنوان طولانی‌تر از ' . self::TITLE_MAX . ' نویسه است.');
            }
            $data['title'] = $title;
        }
        if (array_key_exists('description', $params)) {
            $data['description'] = sanitize_textarea_field((string) $request['description']);
            if (mb_strlen($data['description']) > self::DESCRIPTION_MAX) {
                return self::bad_request('توضیح طولانی‌تر از ' . self::DESCRIPTION_MAX . ' نویسه است.');
            }
        }
        foreach (['status', 'bucket', 'priority', 'owner', 'kind'] as $field) {
            if (array_key_exists($field, $params)) {
                $data[$field] = $request[$field];
            }
        }
        if (array_key_exists('estimate_minutes', $params)) {
            $estimate = MLT_Taskmeta::estimate($request['estimate_minutes']);
            if ($estimate === false) {
                return self::bad_request(sprintf('زمان تقریبی باید عدد صحیح بین %d و %d دقیقه باشد.', MLT_Taskmeta::ESTIMATE_MIN, MLT_Taskmeta::ESTIMATE_MAX));
            }
            $data['estimate_minutes'] = $estimate;
        }
        if (array_key_exists('due_date', $params)) {
            $date = MLT_Taskmeta::date($request['due_date']);
            if ($date === false) {
                return self::bad_request('سررسید باید تاریخ میلادی به شکل YYYY-MM-DD باشد.');
            }
            $data['due_date'] = $date;
        }
        if (array_key_exists('done_criteria', $params)) {
            $criteria = MLT_Taskmeta::criteria($request['done_criteria']);
            if ($criteria === false) {
                return self::bad_request('معیار انجام بیش از ' . MLT_Taskmeta::CRITERIA_MAX . ' نویسه است.');
            }
            $data['done_criteria'] = $criteria;
        }
        if (array_key_exists('assignees', $params)) {
            $data['assignees'] = array_values(array_unique(array_map('strval', (array) $request['assignees'])));
        }
        if (array_key_exists('late_reason', $params)) {
            $data['late_reason'] = trim(sanitize_text_field((string) $request['late_reason']));
        }
        return $data;
    }

    private static function find_task($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mlt_tasks WHERE id = %d", $id), ARRAY_A);
    }

    /** Many tasks at once: the assignees come from one query. */
    public static function format_tasks(array $rows)
    {
        $assignees = MLT_Taskmeta::assignees_for(array_map('intval', wp_list_pluck($rows, 'id')));
        return array_map(function ($row) use ($assignees) {
            return self::format_task($row, $assignees[(int) $row['id']]);
        }, $rows);
    }

    public static function format_task(array $row, array $assignees = null)
    {
        foreach (['id', 'created_by', 'updated_by'] as $int) {
            $row[$int] = (int) $row[$int];
        }
        foreach (['source_comment_id', 'estimate_minutes', 'estimate_original'] as $nullable_int) {
            if ($row[$nullable_int] !== null) {
                $row[$nullable_int] = (int) $row[$nullable_int];
            }
        }
        $row['meta'] = $row['meta'] === null ? null : json_decode($row['meta'], true);
        $row['assignees'] = $assignees ?? MLT_Taskmeta::assignees_for([$row['id']])[$row['id']];
        return $row + MLT_Taskmeta::flags($row);
    }

    // ---- telegram (manual only) ----

    /** Sends `text` (or, if it is not given, title/owner/status/sender/link) to the configured Telegram chat. */
    public static function notify_task(WP_REST_Request $request)
    {
        $task = self::find_task(self::task_id($request));
        if (!$task) {
            return self::not_found();
        }
        $text = $request->has_param('text') ? $request['text'] : MLT_Telegram::default_text($task, '📣 اطلاع‌رسانی تسک');
        return self::notify_result(MLT_Telegram::deliver($text, (int) $task['id']));
    }

    /** A manual message that belongs to no task. */
    public static function notify_free(WP_REST_Request $request)
    {
        return self::notify_result(MLT_Telegram::deliver($request['text'], null));
    }

    /** `true` -> {sent: true}; an error (429 carries retry_after, which add_headers turns into Retry-After) is returned as is. */
    private static function notify_result($result)
    {
        return $result === true ? ['sent' => true] : $result;
    }

    // ---- export / import ----

    public static function export_all()
    {
        $data = MLT_Transfer::export();
        self::log_activity(null, 'export', sprintf('tasks: %d | comments: %d', count($data['tasks']), count($data['comments'])));
        return $data;
    }

    /** Takes the JSON of GET /export, or the old board's file (tasks / routines / ideas). ?dry_run=1 only reports. Authors, agent names and dates in the file are ignored. */
    public static function import_all(WP_REST_Request $request)
    {
        $body = $request->get_body();
        if (strlen($body) > MLT_Transfer::MAX_BYTES) {
            return new WP_Error('mlt_too_large', 'فایل بیش از حد بزرگ است.', ['status' => 413]);
        }
        $data = $request->get_json_params();
        if (!is_array($data)) {
            return self::bad_request('بدنه‌ی درخواست باید JSON باشد.');
        }
        $dry = (bool) $request->get_param('dry_run');
        if (($data['format'] ?? null) === MLT_Transfer::FORMAT) {
            return MLT_Transfer::import($data, $dry);
        }
        $report = MLT_Importer::run($body, $dry, false);
        if (is_wp_error($report)) {
            return self::bad_request($report->get_error_message());
        }
        return ['format' => 'legacy'] + $report;
    }

    // ---- routines ----

    public static function get_routines(WP_REST_Request $request)
    {
        return rest_ensure_response(MLT_Routines::views($request->get_param('period')));
    }

    /**
     * The state of the routine's current period (status; period_key, if sent, must still be the current one) and/or its
     * template: estimate_minutes, done_criteria, recurrence_until, stopped (true: the routine ends yesterday, false: runs on).
     */
    public static function update_routine(WP_REST_Request $request)
    {
        $key = (string) $request['key'];
        $template = array_intersect_key($request->get_json_params() ?: $request->get_body_params(), array_flip(['estimate_minutes', 'done_criteria', 'recurrence_until', 'stopped']));
        if (!$request->has_param('status') && !$template) {
            return self::bad_request('status یا یکی از فیلدهای قالب روتین لازم است.');
        }
        if ($template) {
            $result = MLT_Routines::update_template($key, $template);
            if (is_wp_error($result)) {
                return $result;
            }
        }
        if ($request->has_param('status')) {
            return MLT_Routines::set_status($key, $request['status'], $request->get_param('period_key'));
        }
        return MLT_Routines::view_of($key);
    }

    // ---- comments ----

    public static function get_comments(WP_REST_Request $request)
    {
        global $wpdb;
        $id = self::task_id($request);
        if (!self::find_task($id)) {
            return self::not_found();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mlt_comments WHERE task_id = %d ORDER BY id ASC",
            $id
        ), ARRAY_A);
        return rest_ensure_response(array_map([__CLASS__, 'format_comment'], $rows));
    }

    public static function create_comment(WP_REST_Request $request)
    {
        global $wpdb;
        $id = self::task_id($request);
        if (!self::find_task($id)) {
            return self::not_found();
        }
        $body = trim(sanitize_textarea_field((string) $request['body']));
        if ($body === '') {
            return self::bad_request('متن کامنت خالی است.');
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            return self::bad_request('متن کامنت طولانی‌تر از ' . self::BODY_MAX . ' نویسه است.');
        }

        $ok = $wpdb->insert("{$wpdb->prefix}mlt_comments", [
            'task_id'    => $id,
            'body'       => $body,
            'author'     => get_current_user_id(),
            'agent'      => self::current_agent(),
            'flagged'    => 0,
            'created_at' => current_time('mysql', true),
        ]);
        if (!$ok) {
            return self::db_error();
        }
        $cid = $wpdb->insert_id;
        self::log_activity($id, 'comment_add', sprintf('«%s»: کامنت #%d', self::find_task($id)['title'], $cid));

        $response = rest_ensure_response(self::format_comment(self::find_comment($id, $cid)));
        $response->set_status(201);
        return $response;
    }

    public static function flag_comment(WP_REST_Request $request)
    {
        global $wpdb;
        $id  = self::task_id($request);
        $cid = (int) $request['cid'];
        $comment = self::find_comment($id, $cid);
        if (!$comment) {
            return self::not_found();
        }
        if ($wpdb->update("{$wpdb->prefix}mlt_comments", ['flagged' => 1], ['id' => $cid]) === false) {
            return self::db_error();
        }
        if (!(int) $comment['flagged']) {
            self::log_activity($id, 'comment_flag', sprintf('«%s»: کامنت #%d', self::find_task($id)['title'], $cid));
        }
        return self::format_comment(self::find_comment($id, $cid));
    }

    private static function find_comment($task_id, $cid)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mlt_comments WHERE id = %d AND task_id = %d",
            $cid,
            $task_id
        ), ARRAY_A);
    }

    public static function format_comment(array $row)
    {
        $user = get_userdata((int) $row['author']);
        return [
            'id'           => (int) $row['id'],
            'task_id'      => (int) $row['task_id'],
            'body'         => $row['body'],
            'author'       => (int) $row['author'],
            'author_login' => $user ? $user->user_login : null,
            'agent'        => $row['agent'],
            'flagged'      => (bool) $row['flagged'],
            'created_at'   => $row['created_at'],
        ];
    }

    /** Name of the Application Password used for this request (e.g. "Claude-mh"); null for cookie auth. */
    public static function current_agent()
    {
        $uuid = rest_get_authenticated_app_password();
        if (!$uuid) {
            return null;
        }
        $item = WP_Application_Passwords::get_user_application_password(get_current_user_id(), $uuid);
        return $item ? mb_substr(sanitize_text_field($item['name']), 0, 100) : null;
    }

    /** Who did what: the user, plus the Application Password name when the call came from an agent. */
    public static function log_activity($task_id, $action, $summary)
    {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}mlt_activity_log", [
            'task_id'    => $task_id,
            'user'       => get_current_user_id(),
            'agent'      => self::current_agent(),
            'action'     => $action,
            'summary'    => MLT_Log::mask($summary), // a token or password pasted into a title must not reach the log
            'created_at' => current_time('mysql', true),
        ]);
    }

    // ---- errors ----

    private static function not_found()
    {
        return new WP_Error('mlt_not_found', 'پیدا نشد.', ['status' => 404]);
    }

    private static function bad_request($message)
    {
        return new WP_Error('mlt_bad_request', $message, ['status' => 400]);
    }

    private static function db_error()
    {
        return new WP_Error('mlt_db_error', 'ذخیره انجام نشد.', ['status' => 500]);
    }
}
