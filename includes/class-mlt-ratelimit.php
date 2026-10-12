<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-user fixed-window rate limit, shared by the Telegram sender ('rl') and the REST routes ('rg').
 * The counter is one options row per scope, user and window. The row is created with INSERT IGNORE, then
 * raised by a single atomic UPDATE whose LAST_INSERT_ID(expr) hands back this request's own count (not an
 * INSERT: there MySQL would return the new row's auto-increment id), so parallel requests cannot slip past
 * the limit. Raw SQL on purpose: an object cache must not sit between two requests and their counter.
 */
class MLT_RateLimit
{
    /** @return int 0 if the hit is allowed, otherwise the seconds until the window ends */
    public static function hit($scope, $user_id, $limit, $window)
    {
        global $wpdb;
        $prefix = 'mlt_' . $scope . '_' . (int) $user_id . '_';
        $name = $prefix . (int) floor(time() / $window);
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')", $name));
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s", $name));
        $count = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
        if ($count === 1) { // first hit of a new window: forget this user's older windows
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s",
                $wpdb->esc_like($prefix) . '%',
                $name
            ));
        }
        return $count > $limit ? $window - (time() % $window) : 0;
    }
}
