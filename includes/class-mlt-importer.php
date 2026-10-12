<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * One-off import of the old board's JSON export (tasks, routines, ideas).
 * Comments and flags in the file are never imported. Re-running is safe:
 * rows are matched on legacy_id and existing ones are skipped.
 */
class MLT_Importer
{
    const MAX_BYTES = 2097152;
    const MAX_REASONS = 30;
    const IDEA_OWNER = 'chakra'; // ideas carry no owner in the file; the bank is Chakra's

    const SECTIONS = ['tasks' => 'تسک‌ها', 'routines' => 'روتین‌ها', 'ideas' => 'ایده‌ها'];

    const PERIOD_FORMAT = [
        'daily'   => '/^\d{4}-\d{2}-\d{2}$/',
        'weekly'  => '/^\d{4}-W\d{2}$/',
        'monthly' => '/^\d{4}-\d{2}$/',
    ];

    /**
     * @return array|WP_Error report: dry, sections[name] => created|duplicate|invalid, reasons[], ignored_comments
     */
    public static function run($raw, $dry, $keep_dates = true)
    {
        global $wpdb;

        $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw), true);
        if (!is_array($data) || !array_intersect_key($data, self::SECTIONS)) {
            return new WP_Error('mlt_import_format', 'فایل JSON معتبر نیست یا بخش‌های tasks، routines و ideas را ندارد.');
        }
        foreach (array_keys(self::SECTIONS) as $section) {
            if (isset($data[$section]) && !is_array($data[$section])) {
                return new WP_Error('mlt_import_format', 'بخش ' . $section . ' باید آرایه باشد.');
            }
        }

        if (!$keep_dates) { // an upload over the API must not backdate rows
            foreach (array_keys(self::SECTIONS) as $section) {
                foreach ((array) ($data[$section] ?? []) as $i => $item) {
                    if (is_array($item)) {
                        unset($data[$section][$i]['created_at']);
                    }
                }
            }
        }

        $now = current_time('mysql', true);
        $user = get_current_user_id();
        $builders = ['tasks' => 'build_task', 'routines' => 'build_routine', 'ideas' => 'build_idea'];
        $report = [
            'dry'              => (bool) $dry,
            'sections'         => [],
            'reasons'          => [],
            'ignored_comments' => isset($data['comments']) && is_array($data['comments']) ? count($data['comments']) : 0,
        ];

        // 1) validate every row
        $valid = [];
        foreach ($builders as $section => $builder) {
            $report['sections'][$section] = ['created' => 0, 'duplicate' => 0, 'invalid' => 0];
            foreach ($data[$section] ?? [] as $index => $item) {
                $row = is_array($item) ? self::$builder($item, $now, $user) : 'ردیف یک شیء JSON نیست';
                if (is_string($row)) {
                    self::reject($report, $section, $item, $index, $row);
                } else {
                    $valid[] = [$section, $item, $index, $row];
                }
            }
        }

        // 2) which legacy ids already exist?
        $ids = array_column(array_column($valid, 3), 'legacy_id');
        $existing = [];
        if ($ids) {
            $existing = array_flip($wpdb->get_col($wpdb->prepare(
                "SELECT legacy_id FROM {$wpdb->prefix}mlt_tasks WHERE legacy_id IN (" . implode(',', array_fill(0, count($ids), '%s')) . ')',
                $ids
            )));
        }

        // 3) insert what is new (a repeat inside the file counts as a duplicate too)
        foreach ($valid as list($section, $item, $index, $row)) {
            if (isset($existing[$row['legacy_id']])) {
                $report['sections'][$section]['duplicate']++;
                continue;
            }
            $existing[$row['legacy_id']] = true;
            if (!$dry) {
                if (!$wpdb->insert("{$wpdb->prefix}mlt_tasks", $row)) {
                    self::reject($report, $section, $item, $index, 'ذخیره در دیتابیس انجام نشد');
                    continue;
                }
                MLT_Taskmeta::after_insert($wpdb->insert_id, $row['owner'], $row['owner']); // old data: the code prefix follows the owner
            }
            $report['sections'][$section]['created']++;
        }

        if (!$dry) {
            $parts = [];
            foreach ($report['sections'] as $section => $n) {
                $parts[] = sprintf('%s: %d created, %d duplicate, %d invalid', $section, $n['created'], $n['duplicate'], $n['invalid']);
            }
            MLT_REST::log_activity(null, 'import', implode(' | ', $parts) . sprintf(' | ignored comments: %d', $report['ignored_comments']));
        }
        return $report;
    }

    // ---- row builders: return a DB row, or a Persian reason string when the row is rejected ----

    private static function build_task(array $r, $now, $user)
    {
        $id = self::legacy_id($r['id'] ?? null);
        if ($id === null) {
            return 'شناسه‌ی قدیمی (id) معتبر نیست';
        }
        $source = $r['source'] ?? 'added';
        $kinds = ['fixed' => 'phase', 'added' => 'new'];
        if (!is_string($source) || !isset($kinds[$source])) {
            return 'مقدار source نامعتبر است';
        }
        $row = self::common($r, $now, $user);
        return is_string($row) ? $row : ['legacy_id' => $id, 'kind' => $kinds[$source]] + $row;
    }

    private static function build_routine(array $r, $now, $user)
    {
        $id = self::legacy_id($r['id'] ?? null);
        if ($id === null) {
            return 'شناسه‌ی قدیمی (id) معتبر نیست';
        }
        $freq = $r['frequency'] ?? null;
        if (!is_string($freq) || !isset(self::PERIOD_FORMAT[$freq])) {
            return 'frequency نامعتبر است';
        }
        $period = $r['period_key'] ?? null;
        if (!is_string($period) || !preg_match(self::PERIOD_FORMAT[$freq], $period)) {
            return 'period_key با frequency نمی‌خواند';
        }
        $row = self::common($r, $now, $user);
        if (is_string($row)) {
            return $row;
        }
        return [
            'legacy_id'  => 'routine_' . $id . '_' . $period, // same shape as the old board's state key
            'kind'       => 'routine',
            'recurrence' => $freq,
            'period_key' => $period,
            'meta'       => wp_json_encode(['routine_id' => $id], JSON_UNESCAPED_UNICODE),
        ] + $row;
    }

    private static function build_idea(array $r, $now, $user)
    {
        $id = self::legacy_id($r['id'] ?? null);
        if ($id === null) {
            return 'شناسه‌ی قدیمی (id) معتبر نیست';
        }
        $row = self::common(array_merge($r, ['owner' => self::IDEA_OWNER]), $now, $user);
        if (is_string($row)) {
            return $row;
        }
        $meta = [];
        foreach (['type', 'category', 'tag'] as $field) {
            $meta[$field] = isset($r[$field]) && is_string($r[$field]) ? sanitize_text_field($r[$field]) : '';
        }
        return [
            'legacy_id' => 'idea_' . $id,
            'kind'      => 'idea',
            'meta'      => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
        ] + $row;
    }

    /** Fields shared by every kind. created_at is kept when the file has it, otherwise it is the import time. */
    private static function common(array $r, $now, $user)
    {
        $title = isset($r['title']) && is_string($r['title']) ? sanitize_text_field($r['title']) : '';
        if ($title === '') {
            return 'عنوان خالی است';
        }
        if (mb_strlen($title) > MLT_REST::TITLE_MAX) {
            return 'عنوان بیش از حد طولانی است';
        }
        if (isset($r['description']) && !is_string($r['description'])) {
            return 'توضیح باید متن باشد';
        }

        $row = ['title' => $title, 'description' => sanitize_textarea_field($r['description'] ?? '')];
        foreach (['owner' => null, 'priority' => 'med', 'bucket' => 'normal', 'status' => 'open'] as $field => $default) {
            $value = $r[$field] ?? $default;
            if ($value === null) {
                return 'مسئول (owner) مشخص نیست';
            }
            if (!is_string($value) || !in_array($value, MLT_REST::ENUMS[$field], true)) {
                return 'مقدار ' . $field . ' نامعتبر است';
            }
            $row[$field] = $value;
        }

        $created = $r['created_at'] ?? $now;
        $date = is_string($created) ? DateTime::createFromFormat('Y-m-d H:i:s', $created) : false;
        if (!$date || $date->format('Y-m-d H:i:s') !== $created) {
            return 'created_at نامعتبر است';
        }
        return $row + ['created_by' => $user, 'created_at' => $created, 'updated_by' => $user, 'updated_at' => $created];
    }

    private static function legacy_id($value)
    {
        $value = is_int($value) ? (string) $value : $value;
        return is_string($value) && preg_match('/^[A-Za-z0-9._-]{1,32}$/', $value) ? $value : null;
    }

    private static function reject(array &$report, $section, $item, $index, $reason)
    {
        $report['sections'][$section]['invalid']++;
        if (count($report['reasons']) < self::MAX_REASONS) {
            $id = is_array($item) && isset($item['id']) && is_scalar($item['id']) ? mb_substr((string) $item['id'], 0, 40) : '#' . $index;
            $report['reasons'][] = ['section' => $section, 'id' => $id, 'reason' => $reason];
        }
    }
}
