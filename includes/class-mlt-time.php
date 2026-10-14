<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one clock of the plugin: UTC+0 (the time the site's posts are published in), Gregorian dates only.
 * Used for routine periods, due dates, timers, scores and every time shown in the panel. The WordPress
 * timezone setting is never read or changed; the database keeps UTC as well.
 */
class MLT_Time
{
    const ZONE = 'UTC';

    public static function now()
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::ZONE));
    }

    /** Today's date, "Y-m-d". */
    public static function today()
    {
        return self::now()->format('Y-m-d');
    }

    /** A "Y-m-d H:i:s" value from the database, shown as UTC. */
    public static function format($utc, $format = 'Y-m-d H:i')
    {
        return (new DateTimeImmutable((string) $utc, new DateTimeZone(self::ZONE)))->format($format);
    }

    /** Database bounds of a calendar day "Y-m-d". */
    public static function day_bounds($date)
    {
        return [$date . ' 00:00:00', $date . ' 23:59:59'];
    }
}
