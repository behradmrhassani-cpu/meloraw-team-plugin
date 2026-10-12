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

    public static function register_routes()
    {
        $args = [];
        foreach (self::ENUMS as $field => $values) {
            $args[$field] = ['type' => 'string', 'enum' => $values];
        }

        register_rest_route(self::NS, '/tasks', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_tasks'],
            'permission_callback' => [__CLASS__, 'can_manage'],
            'args'                => $args,
        ]);

        add_filter('rest_post_dispatch', [__CLASS__, 'add_headers'], 10, 3);
    }

    public static function can_manage()
    {
        if (current_user_can(MLT_CAP)) {
            return true;
        }
        // Generic error only; no detail for unauthenticated callers.
        return new WP_Error('mlt_forbidden', 'دسترسی ندارید.', ['status' => rest_authorization_required_code()]);
    }

    public static function add_headers($response, $server, $request)
    {
        if (strpos($request->get_route(), '/' . self::NS) === 0) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
            $response->header('Cache-Control', 'no-store, private');
        }
        return $response;
    }

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
}
