<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which team member (you / maral / chakra / auto) a WordPress account is. The map is kept in the settings
 * (mlt_settings[identity_map] = user id => slug). The request's user is whoever owns the session or the
 * Application Password, so an agent acts as its person. Nothing is guessed: an account that is not in the
 * map has no identity (the board then shows everything and says so).
 */
class MLT_Identity
{
    /** slug => task code prefix */
    const PREFIX = ['you' => 'MH', 'maral' => 'MA', 'chakra' => 'CH', 'auto' => 'AU'];

    public static function slugs()
    {
        return array_keys(self::PREFIX);
    }

    /** user id => slug */
    public static function map()
    {
        $map = MLT_Settings::option('identity_map');
        return is_array($map) ? $map : [];
    }

    public static function of($user_id)
    {
        $map = self::map();
        $slug = $map[(int) $user_id] ?? null;
        return is_string($slug) && isset(self::PREFIX[$slug]) ? $slug : null;
    }

    public static function current()
    {
        return self::of(get_current_user_id());
    }

    public static function prefix($slug)
    {
        return self::PREFIX[$slug] ?? self::PREFIX['auto'];
    }
}
