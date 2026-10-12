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

    public static function register_routes()
    {
        $filters = [];
        foreach (self::ENUMS as $field => $values) {
            $filters[$field] = ['type' => 'string', 'enum' => $values];
        }

        $write = [
            'title'       => ['type' => 'string'],
            'description' => ['type' => 'string'],
        ];
        foreach (['status', 'bucket', 'priority', 'owner'] as $field) {
            $write[$field] = ['type' => 'string', 'enum' => self::ENUMS[$field]];
        }
        $write['kind'] = ['type' => 'string', 'enum' => self::WRITABLE_KINDS];

        $id  = '(?P<id>\d+)';
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

        self::route('/routines', [
            ['GET', 'get_routines', ['period' => ['type' => 'string', 'enum' => array_keys(MLT_Routines::FREQS)]]],
        ]);
        self::route('/routines/(?P<key>[A-Za-z0-9._-]{1,32})', [
            ['PATCH', 'update_routine', [
                'status'     => ['type' => 'string', 'enum' => self::ENUMS['status'], 'required' => true],
                'period_key' => ['type' => 'string'],
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
     */
    public static function guard($response, $handler, $request)
    {
        if (self::is_ours($request) && !current_user_can(MLT_CAP)) {
            return self::forbidden();
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
            $response->header('X-Robots-Tag', 'noindex, nofollow');
            $response->header('Cache-Control', 'no-store, private');
            if ($response->is_error()) {
                $data = $response->get_data();
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

    public static function get_tasks(WP_REST_Request $request)
    {
        global $wpdb;
        $where = [];
        $params = [];
        foreach (array_keys(self::ENUMS) as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $where[] = "$field = %s";
                $params[] = $value;
            }
        }

        $sql = "SELECT * FROM {$wpdb->prefix}mlt_tasks";
        if ($where) {
            $sql = $wpdb->prepare($sql . ' WHERE ' . implode(' AND ', $where), $params);
        }
        $rows = $wpdb->get_results($sql . ' ORDER BY id DESC', ARRAY_A);

        return rest_ensure_response(array_map([__CLASS__, 'format_task'], $rows));
    }

    public static function get_task(WP_REST_Request $request)
    {
        $row = self::find_task((int) $request['id']);
        return $row ? self::format_task($row) : self::not_found();
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

        $now = current_time('mysql', true);
        $user = get_current_user_id();
        $data += [
            'description' => '',
            'created_by'  => $user,
            'created_at'  => $now,
            'updated_by'  => $user,
            'updated_at'  => $now,
        ];
        if (!$wpdb->insert("{$wpdb->prefix}mlt_tasks", $data)) {
            return self::db_error();
        }
        $id = $wpdb->insert_id;
        self::log_activity($id, 'task_create', sprintf('«%s»', $data['title']));

        $response = rest_ensure_response(self::format_task(self::find_task($id)));
        $response->set_status(201);
        return $response;
    }

    public static function update_task(WP_REST_Request $request)
    {
        global $wpdb;
        $id = (int) $request['id'];
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

        $data['updated_by'] = get_current_user_id();
        $data['updated_at'] = current_time('mysql', true);
        if ($wpdb->update("{$wpdb->prefix}mlt_tasks", $data, ['id' => $id]) === false) {
            return self::db_error();
        }
        $changed = [];
        foreach ($data as $field => $value) {
            if (!in_array($field, ['status', 'updated_by', 'updated_at'], true) && (string) $value !== (string) $old[$field]) {
                $changed[] = $field;
            }
        }
        if ($changed) {
            self::log_activity($id, 'task_edit', sprintf('«%s»: %s', $old['title'], implode(', ', $changed)));
        }
        if (isset($data['status']) && $data['status'] !== $old['status']) {
            self::log_activity($id, 'status_change', sprintf('«%s»: %s → %s', $old['title'], $old['status'], $data['status']));
        }
        return self::format_task(self::find_task($id));
    }

    public static function delete_task(WP_REST_Request $request)
    {
        global $wpdb;
        $id = (int) $request['id'];
        $task = self::find_task($id);
        if (!$task) {
            return self::not_found();
        }
        $wpdb->delete("{$wpdb->prefix}mlt_comments", ['task_id' => $id]);
        $wpdb->delete("{$wpdb->prefix}mlt_tasks", ['id' => $id]);
        self::log_activity($id, 'task_delete', sprintf('«%s»', $task['title']));
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
        }
        foreach (['status', 'bucket', 'priority', 'owner', 'kind'] as $field) {
            if (array_key_exists($field, $params)) {
                $data[$field] = $request[$field];
            }
        }
        return $data;
    }

    private static function find_task($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mlt_tasks WHERE id = %d", $id), ARRAY_A);
    }

    private static function format_task(array $row)
    {
        foreach (['id', 'created_by', 'updated_by'] as $int) {
            $row[$int] = (int) $row[$int];
        }
        if ($row['source_comment_id'] !== null) {
            $row['source_comment_id'] = (int) $row['source_comment_id'];
        }
        $row['meta'] = $row['meta'] === null ? null : json_decode($row['meta'], true);
        return $row;
    }

    // ---- routines ----

    public static function get_routines(WP_REST_Request $request)
    {
        return rest_ensure_response(MLT_Routines::views($request->get_param('period')));
    }

    /** Sets the state of the routine's current period; period_key, if sent, must still be the current one. */
    public static function update_routine(WP_REST_Request $request)
    {
        return MLT_Routines::set_status((string) $request['key'], $request['status'], $request->get_param('period_key'));
    }

    // ---- comments ----

    public static function get_comments(WP_REST_Request $request)
    {
        global $wpdb;
        $id = (int) $request['id'];
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
        $id = (int) $request['id'];
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
        $id  = (int) $request['id'];
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

    private static function format_comment(array $row)
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
    private static function current_agent()
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
            'summary'    => $summary,
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
