<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one timezone the plugin works in: Tehran. Used for routine periods and for every time shown
 * in the panel. The site timezone stays UTC and is never read or changed; the database keeps UTC.
 */
class MLT_Time
{
    const ZONE = 'Asia/Tehran';

    private static function zone()
    {
        return new DateTimeZone(self::ZONE);
    }

    public static function now()
    {
        return new DateTimeImmutable('now', self::zone());
    }

    /** A UTC "Y-m-d H:i:s" value from the database, shown in Tehran time. */
    public static function format($utc, $format = 'Y/m/d H:i')
    {
        return (new DateTimeImmutable((string) $utc, new DateTimeZone('UTC')))->setTimezone(self::zone())->format($format);
    }

    /** UTC bounds (as database strings) of a Tehran calendar day "Y-m-d". */
    public static function day_bounds($date)
    {
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($date . ' 00:00:00', self::zone());
        $end = new DateTimeImmutable($date . ' 23:59:59', self::zone());
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
    }
}
