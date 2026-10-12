<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Routines: recurring tasks whose state is kept per period (day / week / month).
 *
 * A routine's definition (title, description, owner, frequency) is not in the code: it is read
 * from the newest mlt_tasks row of that routine (kind = routine, meta.routine_id). The state for
 * a period is the row carrying that period_key; if there is none the routine is "open". So when
 * the period key changes the routine is reset by itself, with no cron.
 */
class MLT_Routines
{
    const FREQS = ['daily' => 'روزانه', 'weekly' => 'هفتگی', 'monthly' => 'ماهانه'];

    /** Key of the period that contains $now (default: now, UTC): 2026-10-12, 2026-W42 or 2026-10. */
    public static function period_key($freq, $now = null)
    {
        $now = $now ?: MLT_Time::now(); // UTC; the site timezone is not used
        if ($freq === 'daily') {
            return $now->format('Y-m-d');
        }
        if ($freq === 'weekly') {
            return $now->format('Y') . '-W' . sprintf('%02d', self::week_number($now));
        }
        return $now->format('Y-m');
    }

    /** The old board's week: ceil((days since Jan 1 + weekday of Jan 1 + 1) / 7), weekday Sunday = 0. */
    public static function week_number(DateTimeInterface $date)
    {
        $days = (int) $date->format('z');
        $jan1 = (int) (new DateTimeImmutable($date->format('Y') . '-01-01', $date->getTimezone()))->format('w');
        return (int) ceil(($days + $jan1 + 1) / 7);
    }

    /** Routines (optionally of one frequency) with their state for the current period, daily first. */
    public static function views($freq = null)
    {
        $defs = self::definitions();
        $periods = [];
        foreach (array_keys(self::FREQS) as $f) {
            $periods[$f] = self::period_key($f);
        }
        $rows = self::rows(array_values($periods));

        $views = [];
        foreach (array_keys(self::FREQS) as $f) {
            foreach ($defs as $key => $def) {
                if ($def['frequency'] === $f && ($freq === null || $freq === $f)) {
                    $views[] = self::view($def, $periods[$f], $rows[$key . '|' . $periods[$f]] ?? null);
                }
            }
        }
        return $views;
    }

    /**
     * Sets the state of the routine's current period. $period_key, when given, must still be the
     * current one (a form left open across midnight must not tick the next day).
     */
    public static function set_status($key, $status, $period_key = null)
    {
        global $wpdb;
        if (!in_array($status, MLT_REST::ENUMS['status'], true)) {
            return new WP_Error('mlt_bad_request', 'وضعیت نامعتبر است.', ['status' => 400]);
        }
        $defs = self::definitions();
        if (!isset($defs[$key])) {
            return new WP_Error('mlt_not_found', 'پیدا نشد.', ['status' => 404]);
        }
        $def = $defs[$key];
        $period = self::period_key($def['frequency']);
        if ($period_key !== null && $period_key !== $period) {
            return new WP_Error('mlt_period_changed', 'دوره‌ی این روتین عوض شده است؛ صفحه را تازه کن.', ['status' => 409]);
        }

        $table = $wpdb->prefix . 'mlt_tasks';
        $row = self::rows([$period])[$key . '|' . $period] ?? null;
        $old = $row ? $row['status'] : 'open';
        if ($status !== $old) {
            $now = current_time('mysql', true);
            $user = get_current_user_id();
            $created = false;
            if (!$row) {
                // first change in this period: copy the definition into a row for it
                $created = (bool) $wpdb->insert($table, [
                    'legacy_id'   => 'routine_' . $key . '_' . $period, // same shape as imported rows
                    'kind'        => 'routine',
                    'recurrence'  => $def['frequency'],
                    'period_key'  => $period,
                    'meta'        => $def['meta'],
                    'title'       => $def['title'],
                    'description' => $def['description'],
                    'owner'       => $def['owner'],
                    'priority'    => $def['priority'],
                    'bucket'      => $def['bucket'],
                    'status'      => $status,
                    'created_by'  => $user,
                    'created_at'  => $now,
                    'updated_by'  => $user,
                    'updated_at'  => $now,
                ]);
                $row = $created ? ['id' => $wpdb->insert_id] : (self::rows([$period])[$key . '|' . $period] ?? null); // else: a concurrent request created it first
                if (!$row) {
                    return new WP_Error('mlt_db_error', 'ذخیره انجام نشد.', ['status' => 500]);
                }
            }
            if (!$created && $wpdb->update($table, ['status' => $status, 'updated_by' => $user, 'updated_at' => $now], ['id' => $row['id']]) === false) {
                return new WP_Error('mlt_db_error', 'ذخیره انجام نشد.', ['status' => 500]);
            }
            $row['status'] = $status;
            MLT_REST::log_activity((int) $row['id'], 'routine_status', sprintf('«%s» (%s): %s → %s', $def['title'], $period, $old, $status));
        }
        return self::view($def, $period, $row);
    }

    private static function view(array $def, $period, $row)
    {
        return [
            'key'         => $def['key'],
            'title'       => $def['title'],
            'description' => $def['description'],
            'owner'       => $def['owner'],
            'frequency'   => $def['frequency'],
            'period_key'  => $period,
            'status'      => $row ? $row['status'] : 'open',
            'task_id'     => $row ? (int) $row['id'] : null,
        ];
    }

    /** routine key => definition, from the newest row of each routine; natural order (r2 before r10). */
    private static function definitions()
    {
        global $wpdb;
        $t = $wpdb->prefix . 'mlt_tasks';
        $rows = $wpdb->get_results(
            "SELECT t.* FROM $t t INNER JOIN (SELECT MAX(id) AS id FROM $t WHERE kind = 'routine' GROUP BY JSON_UNQUOTE(JSON_EXTRACT(meta, '\$.routine_id'))) latest ON latest.id = t.id",
            ARRAY_A
        );
        $defs = [];
        foreach ($rows as $r) {
            $meta = json_decode((string) $r['meta'], true);
            $key = is_array($meta) && isset($meta['routine_id']) && is_string($meta['routine_id']) ? $meta['routine_id'] : '';
            if ($key !== '' && isset(self::FREQS[$r['recurrence']])) {
                $defs[$key] = [
                    'key'         => $key,
                    'title'       => $r['title'],
                    'description' => $r['description'],
                    'owner'       => $r['owner'],
                    'frequency'   => $r['recurrence'],
                    'priority'    => $r['priority'],
                    'bucket'      => $r['bucket'],
                    'meta'        => $r['meta'],
                ];
            }
        }
        uksort($defs, 'strnatcasecmp');
        return $defs;
    }

    /** Rows for the given period keys, indexed "routine key|period key". */
    private static function rows(array $periods)
    {
        global $wpdb;
        $found = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mlt_tasks WHERE kind = 'routine' AND period_key IN (" . implode(',', array_fill(0, count($periods), '%s')) . ')',
            $periods
        ), ARRAY_A);
        $map = [];
        foreach ($found as $r) {
            $meta = json_decode((string) $r['meta'], true);
            if (is_array($meta) && isset($meta['routine_id'])) {
                $map[$meta['routine_id'] . '|' . $r['period_key']] = $r;
            }
        }
        return $map;
    }
}
