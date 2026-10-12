<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import of tasks, routines and ideas from a JSON file: the template (see MLT_Schema, docs/import-template/) or the
 * old board's export. Comments and flags in a file are never imported.
 *
 * - A row may carry an `id` (any short text) or not. A row with an id is a duplicate when that id exists; a row
 *   without one is a duplicate when the same title already exists (for routines and ideas the id is derived from the
 *   title). A repeat inside the file counts as a duplicate too. Running a file again is safe.
 * - With "format": "meloraw-tasks-import" a field the format does not know rejects its row; in an old file (no format)
 *   it is only a warning, so a real row of the migration is never lost over an extra key.
 * - Every problem is reported with its row number (dry run: nothing is saved).
 */
class MLT_Importer
{
    const MAX_BYTES = 5242880;
    const MAX_ROWS = 5000;      // per section
    const MAX_REASONS = 200;
    const MAX_WARNINGS = 50;
    const IDEA_OWNER = 'chakra'; // ideas carry no owner in the file; the bank is Chakra's

    const SECTIONS = ['tasks' => 'تسک‌ها', 'routines' => 'روتین‌ها', 'ideas' => 'ایده‌ها'];

    const PERIOD_FORMAT = [
        'daily'   => '/^\d{4}-\d{2}-\d{2}$/',
        'weekly'  => '/^\d{4}-W\d{2}$/',
        'monthly' => '/^\d{4}-\d{2}$/',
    ];

    /**
     * @return array|WP_Error report: dry, mode (template|legacy), sections[name] => created|duplicate|invalid,
     *                        reasons[] (section, row, id, reason), warnings[], ignored_comments
     */
    public static function run($raw, $dry, $keep_dates = true)
    {
        global $wpdb;

        $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw), true);
        if (!is_array($data) || !array_intersect_key($data, self::SECTIONS)) {
            return new WP_Error('mlt_import_format', 'فایل JSON معتبر نیست یا بخش‌های tasks، routines و ideas را ندارد.');
        }
        $strict = ($data['format'] ?? null) === MLT_Schema::FORMAT;
        if ($strict && isset($data['version']) && $data['version'] !== MLT_Schema::VERSION) {
            return new WP_Error('mlt_import_format', 'version این فایل پشتیبانی نمی‌شود (باید ' . MLT_Schema::VERSION . ' باشد).');
        }
        foreach (array_keys(self::SECTIONS) as $section) {
            if (isset($data[$section]) && !is_array($data[$section])) {
                return new WP_Error('mlt_import_format', 'بخش ' . $section . ' باید آرایه باشد.');
            }
            if (count($data[$section] ?? []) > self::MAX_ROWS) {
                return new WP_Error('mlt_too_large', 'بخش ' . $section . ' بیش از ' . self::MAX_ROWS . ' ردیف دارد.', ['status' => 413]);
            }
        }

        $now = current_time('mysql', true);
        $user = get_current_user_id();
        $builders = ['tasks' => 'build_task', 'routines' => 'build_routine', 'ideas' => 'build_idea'];
        $report = [
            'dry'              => (bool) $dry,
            'mode'             => $strict ? 'template' : 'legacy',
            'sections'         => [],
            'reasons'          => [],
            'warnings'         => [],
            'ignored_comments' => isset($data['comments']) && is_array($data['comments']) ? count($data['comments']) : 0,
        ];
        if ($strict) {
            foreach (array_keys($data) as $key) {
                if (!is_string($key) || ($key !== '' && $key[0] === '_') || in_array($key, ['format', 'version', 'tasks', 'routines', 'ideas', 'comments', 'flags'], true)) {
                    continue;
                }
                self::warn($report, '—', '—', 'کلید سطح‌بالای ناشناخته نادیده گرفته شد: ' . mb_substr($key, 0, 40));
            }
        }

        // 1) validate every row
        $valid = [];
        foreach ($builders as $section => $builder) {
            $report['sections'][$section] = ['created' => 0, 'duplicate' => 0, 'invalid' => 0];
            foreach ($data[$section] ?? [] as $index => $item) {
                if (!is_array($item) || ($item && array_values($item) === $item)) {
                    self::reject($report, $section, $item, $index, 'ردیف یک شیء JSON نیست');
                    continue;
                }
                $unknown = array_values(array_filter(array_keys($item), function ($k) use ($section) {
                    return !(is_string($k) && ($k === '' || $k[0] === '_')) && !in_array($k, MLT_Schema::known($section), true);
                }));
                if ($unknown && $strict) {
                    self::reject($report, $section, $item, $index, 'فیلد ناشناخته: ' . implode('، ', array_map(function ($k) { return mb_substr((string) $k, 0, 40); }, $unknown)));
                    continue;
                }
                if ($unknown) {
                    self::warn($report, $section, self::row_no($index), 'فیلد ناشناخته نادیده گرفته شد: ' . implode('، ', array_map(function ($k) { return mb_substr((string) $k, 0, 40); }, $unknown)));
                }
                if (!$keep_dates) { // an upload over the API must not backdate rows
                    unset($item['created_at']);
                }
                $row = self::$builder($item, $now, $user);
                if (is_string($row)) {
                    self::reject($report, $section, $item, $index, $row);
                } else {
                    $valid[] = [$section, $item, $index, $row];
                }
            }
        }

        // 2) what already exists: legacy ids, and titles (for rows without an id)
        $ids = array_filter(array_column(array_column($valid, 3), 'legacy_id'));
        $existing = [];
        if ($ids) {
            $existing = array_flip($wpdb->get_col($wpdb->prepare(
                "SELECT legacy_id FROM {$wpdb->prefix}mlt_tasks WHERE legacy_id IN (" . implode(',', array_fill(0, count($ids), '%s')) . ')',
                array_values($ids)
            )));
        }
        $titles = [];
        $needs_titles = (bool) array_filter(array_column($valid, 3), function ($r) {
            return $r['legacy_id'] === null;
        });
        if ($needs_titles) {
            foreach ($wpdb->get_col("SELECT title FROM {$wpdb->prefix}mlt_tasks") as $t) {
                $titles[mb_strtolower(trim($t))] = true;
            }
        }

        // 3) insert what is new (a repeat inside the file counts as a duplicate too)
        foreach ($valid as list($section, $item, $index, $row)) {
            if ($row['legacy_id'] !== null) {
                $dup = isset($existing[$row['legacy_id']]);
                $existing[$row['legacy_id']] = true;
            } else {
                $key = mb_strtolower(trim($row['title']));
                $dup = isset($titles[$key]);
                $titles[$key] = true;
            }
            if ($dup) {
                $report['sections'][$section]['duplicate']++;
                continue;
            }
            if (!$dry) {
                $assignees = $row['_assignees'];
                unset($row['_assignees']);
                if (!$wpdb->insert("{$wpdb->prefix}mlt_tasks", $row)) {
                    self::reject($report, $section, $item, $index, 'ذخیره در دیتابیس انجام نشد');
                    continue;
                }
                MLT_Taskmeta::after_insert($wpdb->insert_id, $row['owner'], $row['owner'], $assignees); // the code prefix follows the owner
            }
            $report['sections'][$section]['created']++;
        }

        if (!$dry) {
            $parts = [];
            foreach ($report['sections'] as $section => $n) {
                $parts[] = sprintf('%s: %d created, %d duplicate, %d invalid', $section, $n['created'], $n['duplicate'], $n['invalid']);
            }
            MLT_REST::log_activity(null, 'import', implode(' | ', $parts) . sprintf(' | mode: %s | ignored comments: %d', $report['mode'], $report['ignored_comments']));
        }
        return $report;
    }

    // ---- row builders: return a DB row (+ _assignees), or a Persian reason string when the row is rejected ----

    private static function build_task(array $r, $now, $user)
    {
        $id = self::row_id($r);
        if ($id === false) {
            return 'شناسه‌ی قدیمی (id) معتبر نیست';
        }
        $kind = $r['kind'] ?? null;
        if ($kind === null && isset($r['source'])) {
            $kind = is_string($r['source']) ? (['fixed' => 'phase', 'added' => 'new'][$r['source']] ?? false) : false;
        }
        $kind = $kind ?? 'new';
        if (!is_string($kind) || !in_array($kind, MLT_REST::WRITABLE_KINDS, true)) {
            return 'مقدار kind (یا source) نامعتبر است (phase یا new)';
        }
        $row = self::common($r, $now, $user);
        return is_string($row) ? $row : ['legacy_id' => $id, 'kind' => $kind] + $row;
    }

    private static function build_routine(array $r, $now, $user)
    {
        $id = self::row_id($r);
        if ($id === false) {
            return 'شناسه‌ی قدیمی (id) معتبر نیست';
        }
        $freq = $r['frequency'] ?? null;
        if (!is_string($freq) || !isset(self::PERIOD_FORMAT[$freq])) {
            return 'frequency نامعتبر است (daily، weekly یا monthly)';
        }
        $period = $r['period_key'] ?? null;
        if ($period === null || $period === '') {
            $period = MLT_Routines::period_key($freq); // the current period (UTC)
        }
        if (!is_string($period) || !preg_match(self::PERIOD_FORMAT[$freq], $period)) {
            return 'period_key با frequency نمی‌خواند';
        }
        $row = self::common($r, $now, $user);
        if (is_string($row)) {
            return $row;
        }
        $until = MLT_Taskmeta::date($r['recurrence_until'] ?? null);
        if ($until === false) {
            return 'recurrence_until نامعتبر است (YYYY-MM-DD)';
        }
        $key = $id ?? 'rt' . substr(md5(mb_strtolower(trim($row['title']))), 0, 10); // no id: derived from the title
        return [
            'legacy_id'        => 'routine_' . $key . '_' . $period, // same shape as the old board's state key
            'kind'             => 'routine',
            'recurrence'       => $freq,
            'period_key'       => $period,
            'recurrence_until' => $until,
            'meta'             => wp_json_encode(['routine_id' => $key], JSON_UNESCAPED_UNICODE),
        ] + $row;
    }

    private static function build_idea(array $r, $now, $user)
    {
        $id = self::row_id($r);
        if ($id === false) {
            return 'شناسه‌ی قدیمی (id) معتبر نیست';
        }
        $row = self::common(array_merge($r, ['owner' => self::IDEA_OWNER]), $now, $user);
        if (is_string($row)) {
            return $row;
        }
        $meta = [];
        foreach (['type', 'category', 'tag'] as $field) {
            if (isset($r[$field]) && !is_string($r[$field])) {
                return 'مقدار ' . $field . ' باید متن باشد';
            }
            $meta[$field] = isset($r[$field]) ? mb_substr(sanitize_text_field($r[$field]), 0, 200) : '';
        }
        $key = $id ?? 'it' . substr(md5(mb_strtolower(trim($row['title']))), 0, 10);
        return [
            'legacy_id' => 'idea_' . $key,
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
        $description = sanitize_textarea_field($r['description'] ?? '');
        if (mb_strlen($description) > MLT_REST::DESCRIPTION_MAX) {
            return 'توضیح بیش از ' . MLT_REST::DESCRIPTION_MAX . ' نویسه است';
        }

        $row = ['title' => $title, 'description' => $description];
        foreach (['owner' => null, 'priority' => 'med', 'bucket' => 'normal', 'status' => 'open'] as $field => $default) {
            $value = $r[$field] ?? $default;
            if ($value === null || $value === '') {
                return $field === 'owner' ? 'مسئول (owner) مشخص نیست' : 'مقدار ' . $field . ' خالی است';
            }
            if (!is_string($value) || !in_array($value, MLT_REST::ENUMS[$field], true)) {
                return 'مقدار ' . $field . ' نامعتبر است (' . implode('، ', MLT_REST::ENUMS[$field]) . ')';
            }
            $row[$field] = $value;
        }

        $estimate = isset($r['estimate_minutes']) && $r['estimate_minutes'] !== '' ? MLT_Taskmeta::estimate($r['estimate_minutes']) : null;
        if ($estimate === false) {
            return sprintf('estimate_minutes نامعتبر است (عدد صحیح بین %d و %d)', MLT_Taskmeta::ESTIMATE_MIN, MLT_Taskmeta::ESTIMATE_MAX);
        }
        $due = MLT_Taskmeta::date($r['due_date'] ?? null);
        if ($due === false) {
            return 'due_date نامعتبر است (YYYY-MM-DD)';
        }
        if (isset($r['done_criteria']) && !is_string($r['done_criteria'])) {
            return 'done_criteria باید متن باشد';
        }
        $criteria = MLT_Taskmeta::criteria($r['done_criteria'] ?? '');
        if ($criteria === false) {
            return 'done_criteria بیش از ' . MLT_Taskmeta::CRITERIA_MAX . ' نویسه است';
        }
        $assignees = $r['assignees'] ?? [];
        if (!is_array($assignees) || array_filter($assignees, function ($a) { return !is_string($a) || !in_array($a, MLT_Identity::slugs(), true); })) {
            return 'assignees نامعتبر است (فهرستی از ' . implode('، ', MLT_Identity::slugs()) . ')';
        }

        $created = $r['created_at'] ?? $now;
        $date = is_string($created) ? DateTime::createFromFormat('Y-m-d H:i:s', $created) : false;
        if (!$date || $date->format('Y-m-d H:i:s') !== $created) {
            return 'created_at نامعتبر است';
        }
        return $row + [
            'estimate_minutes'  => $estimate,
            'estimate_original' => $estimate,
            'due_date'          => $due,
            'done_criteria'     => $criteria,
            '_assignees'        => array_values($assignees),
            'created_by'        => $user,
            'created_at'        => $created,
            'updated_by'        => $user,
            'updated_at'        => $created,
        ];
    }

    /** null: no id given; false: an id that is not acceptable; else the id. */
    private static function row_id(array $r)
    {
        if (!array_key_exists('id', $r) || $r['id'] === null || $r['id'] === '') {
            return null;
        }
        $value = is_int($r['id']) ? (string) $r['id'] : $r['id'];
        return is_string($value) && preg_match('/^[A-Za-z0-9._-]{1,32}$/', $value) ? $value : false;
    }

    private static function row_no($index)
    {
        return is_int($index) ? $index + 1 : (string) $index;
    }

    private static function reject(array &$report, $section, $item, $index, $reason)
    {
        $report['sections'][$section]['invalid']++;
        if (count($report['reasons']) < self::MAX_REASONS) {
            $label = is_array($item) && isset($item['id']) && is_scalar($item['id']) && (string) $item['id'] !== ''
                ? (string) $item['id']
                : (is_array($item) && isset($item['title']) && is_string($item['title']) ? $item['title'] : '');
            $report['reasons'][] = ['section' => $section, 'row' => self::row_no($index), 'id' => mb_substr($label, 0, 40), 'reason' => $reason];
        }
    }

    private static function warn(array &$report, $section, $row, $message)
    {
        if (count($report['warnings']) < self::MAX_WARNINGS) {
            $report['warnings'][] = ['section' => $section, 'row' => $row, 'message' => $message];
        }
    }
}
