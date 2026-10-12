<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agent passwords: an Application Password whose name starts with "Claude-" or "Hermes-" (e.g. Claude-mh,
 * Hermes-maral) may only be used on this plugin's REST routes. Without this, a leaked agent password of an
 * Administrator works on all of WordPress' REST API (create users, manage plugins...). Application Passwords
 * with any other name are left exactly as they are.
 *
 * Two places enforce it: the authentication step (so the password does not work on XML-RPC) and the REST
 * dispatch step (so it does not work on /wp/v2/... or any other namespace).
 */
class MLT_Agents
{
    const PREFIXES = ['claude-', 'hermes-']; // compared case-insensitively: "claude-x" is restricted too

    const NAMESPACE_ROUTE = '/' . MLT_REST::NS;

    public static function init()
    {
        add_action('wp_authenticate_application_password_errors', [__CLASS__, 'check_transport'], 10, 3);
        add_filter('rest_pre_dispatch', [__CLASS__, 'check_route'], 10, 3);
        add_filter('rest_index', [__CLASS__, 'hide_namespace']);
    }

    public static function is_agent_name($name)
    {
        $name = strtolower(ltrim((string) $name));
        foreach (self::PREFIXES as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /** An agent password is refused outside REST (XML-RPC). */
    public static function check_transport($error, $user, $item)
    {
        if (is_array($item) && self::is_agent_name($item['name'] ?? '') && !(defined('REST_REQUEST') && REST_REQUEST)) {
            $error->add('mlt_agent_scope', 'این رمز فقط برای API تسک‌ها معتبر است.');
        }
    }

    /** An agent password is refused on every REST route outside meloraw-tasks/v1. */
    public static function check_route($result, $server, $request)
    {
        $uuid = rest_get_authenticated_app_password();
        if (!$uuid) {
            return $result;
        }
        $item = WP_Application_Passwords::get_user_application_password(get_current_user_id(), $uuid);
        $route = $request->get_route();
        $in_scope = $route === self::NAMESPACE_ROUTE || strpos($route, self::NAMESPACE_ROUTE . '/') === 0;
        if ($item && self::is_agent_name($item['name']) && !$in_scope) {
            return new WP_Error('mlt_agent_scope', 'این رمز فقط برای API تسک‌ها معتبر است.', ['status' => 403]);
        }
        return $result;
    }

    /** The public /wp-json/ index lists every namespace and its root route; this plugin's is not advertised. */
    public static function hide_namespace($response)
    {
        $data = $response->get_data();
        if (isset($data['namespaces']) && is_array($data['namespaces'])) {
            $data['namespaces'] = array_values(array_diff($data['namespaces'], [MLT_REST::NS]));
        }
        if (isset($data['routes']) && is_array($data['routes'])) {
            foreach (array_keys($data['routes']) as $route) {
                if ($route === self::NAMESPACE_ROUTE || strpos($route, self::NAMESPACE_ROUTE . '/') === 0) {
                    unset($data['routes'][$route]);
                }
            }
        }
        $response->set_data($data);
        return $response;
    }
}
