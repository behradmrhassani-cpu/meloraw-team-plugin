<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The import file format, described once. The importer's list of known fields, GET /schema, the downloadable
 * templates and docs/import-template/ all come from here, so they cannot drift apart (tests check that).
 * Only synthetic sample data lives in this file.
 */
class MLT_Schema
{
    const FORMAT = 'meloraw-tasks-import';
    const VERSION = 1;
    const SECTIONS = ['tasks', 'routines', 'ideas'];
    const TYPES = ['tasks', 'routines', 'ideas', 'full'];

    private static function f($type, $required, $note, array $more = [])
    {
        return ['type' => $type, 'required' => $required, 'note' => $note] + $more;
    }

    /** section => field => spec. Field order is the order used in the templates. */
    public static function fields()
    {
        $e = MLT_REST::ENUMS;
        $date = 'تاریخ میلادی YYYY-MM-DD (روز UTC)';
        $id = self::f('string', false, 'شناسه‌ی دلخواه برای تشخیص تکراری (حروف، عدد، . _ -؛ حداکثر ۳۲ نویسه). اگر ندهی، تکراری بودن بر اساس عنوان تشخیص داده می‌شود.', ['max' => 32, 'pattern' => '^[A-Za-z0-9._-]{1,32}$']);
        $assignees = self::f('array<string>', false, 'مسئول‌های بیشتر؛ مسئول اصلی (owner) همیشه جزوشان است.', ['enum' => $e['owner'], 'default' => []]);
        $created = self::f('datetime', false, 'فقط برای فایل‌های مهاجرت؛ "YYYY-MM-DD HH:MM:SS" UTC. از راه API نادیده گرفته می‌شود و زمان ایمپورت ثبت می‌شود.');
        return [
            'tasks' => [
                'id'               => $id,
                'title'            => self::f('string', true, 'عنوان تسک.', ['max' => MLT_REST::TITLE_MAX]),
                'description'      => self::f('string', false, 'شرح (متن ساده).', ['max' => MLT_REST::DESCRIPTION_MAX, 'default' => '']),
                'owner'            => self::f('string', true, 'مسئول اصلی.', ['enum' => $e['owner']]),
                'assignees'        => $assignees,
                'status'           => self::f('string', false, 'وضعیت.', ['enum' => $e['status'], 'default' => 'open']),
                'bucket'           => self::f('string', false, 'دسته.', ['enum' => $e['bucket'], 'default' => 'normal']),
                'priority'         => self::f('string', false, 'اولویت.', ['enum' => $e['priority'], 'default' => 'med']),
                'kind'             => self::f('string', false, 'نوع تسک: phase (فاز ثابت) یا new (تسک تازه).', ['enum' => MLT_REST::WRITABLE_KINDS, 'default' => 'new']),
                'estimate_minutes' => self::f('integer', false, 'زمان تقریبی به دقیقه (عدد صحیح). اگر ندهی تسک «ناقص» وارد می‌شود و بعداً با PATCH پر می‌شود.', ['min' => MLT_Taskmeta::ESTIMATE_MIN, 'max' => MLT_Taskmeta::ESTIMATE_MAX]),
                'due_date'         => self::f('date', false, 'سررسید: ' . $date . '.'),
                'done_criteria'    => self::f('string', false, 'معیار انجام؛ هنگام done نشان داده می‌شود.', ['max' => MLT_Taskmeta::CRITERIA_MAX]),
                'created_at'       => $created,
            ],
            'routines' => [
                'id'               => $id,
                'title'            => self::f('string', true, 'عنوان روتین.', ['max' => MLT_REST::TITLE_MAX]),
                'description'      => self::f('string', false, 'شرح.', ['max' => MLT_REST::DESCRIPTION_MAX, 'default' => '']),
                'owner'            => self::f('string', true, 'مسئول اصلی.', ['enum' => $e['owner']]),
                'assignees'        => $assignees,
                'frequency'        => self::f('string', true, 'تناوب.', ['enum' => array_keys(MLT_Routines::FREQS)]),
                'period_key'       => self::f('string', false, 'کلید دوره‌ی فعلی (2026-10-12 یا 2026-W42 یا 2026-10). اگر ندهی، دوره‌ی جاری UTC.', ['pattern' => 'مطابق frequency']),
                'status'           => self::f('string', false, 'وضعیت این دوره.', ['enum' => $e['status'], 'default' => 'open']),
                'bucket'           => self::f('string', false, 'دسته.', ['enum' => $e['bucket'], 'default' => 'normal']),
                'priority'         => self::f('string', false, 'اولویت.', ['enum' => $e['priority'], 'default' => 'med']),
                'estimate_minutes' => self::f('integer', false, 'زمان تقریبی هر بار اجرا (دقیقه).', ['min' => MLT_Taskmeta::ESTIMATE_MIN, 'max' => MLT_Taskmeta::ESTIMATE_MAX]),
                'due_date'         => self::f('date', false, 'سررسید: ' . $date . '.'),
                'done_criteria'    => self::f('string', false, 'معیار انجام.', ['max' => MLT_Taskmeta::CRITERIA_MAX]),
                'recurrence_until' => self::f('date', false, 'آخرین روز اجرا (' . $date . '). خالی = بدون پایان.'),
                'created_at'       => $created,
            ],
            'ideas' => [
                'id'          => $id,
                'title'       => self::f('string', true, 'عنوان ایده.', ['max' => MLT_REST::TITLE_MAX]),
                'description' => self::f('string', false, 'شرح (اختیاری).', ['max' => MLT_REST::DESCRIPTION_MAX, 'default' => '']),
                'type'        => self::f('string', false, 'نوع، مثل track یا sfx.', ['max' => 200]),
                'category'    => self::f('string', false, 'دسته.', ['max' => 200]),
                'tag'         => self::f('string', false, 'برچسب.', ['max' => 200]),
                'status'      => self::f('string', false, 'وضعیت.', ['enum' => $e['status'], 'default' => 'open']),
                'created_at'  => $created,
            ],
        ];
    }

    /** Field names an old-board file may also carry (accepted, not part of the template). */
    const LEGACY_ALIASES = ['tasks' => ['source'], 'routines' => [], 'ideas' => []];

    public static function known($section)
    {
        return array_merge(array_keys(self::fields()[$section]), self::LEGACY_ALIASES[$section]);
    }

    public static function limits()
    {
        return [
            'max_bytes'            => MLT_Transfer::MAX_BYTES,
            'max_rows_per_section' => MLT_Importer::MAX_ROWS,
            'description_max'      => MLT_REST::DESCRIPTION_MAX,
            'title_max'            => MLT_REST::TITLE_MAX,
            'done_criteria_max'    => MLT_Taskmeta::CRITERIA_MAX,
            'estimate_min_minutes' => MLT_Taskmeta::ESTIMATE_MIN,
            'estimate_max_minutes' => MLT_Taskmeta::ESTIMATE_MAX,
        ];
    }

    /** GET /schema */
    public static function describe()
    {
        return [
            'format'   => self::FORMAT,
            'version'  => self::VERSION,
            'dates'    => 'YYYY-MM-DD (UTC, Gregorian); datetimes "YYYY-MM-DD HH:MM:SS" UTC',
            'limits'   => self::limits(),
            'sections' => self::fields(),
            'enums'    => [
                'status'    => MLT_REST::ENUMS['status'],
                'bucket'    => MLT_REST::ENUMS['bucket'],
                'priority'  => MLT_REST::ENUMS['priority'],
                'owner'     => MLT_REST::ENUMS['owner'],
                'kind'      => MLT_REST::WRITABLE_KINDS,
                'frequency' => array_keys(MLT_Routines::FREQS),
                'pause_reason' => array_keys(MLT_Timer::REASONS),
            ],
            'notes'    => [
                'The code of a task (MH-1) is created on import and is not part of the file.',
                'A row whose title (or id) already exists is skipped as a duplicate.',
                'Keys starting with "_" (for example _help) are ignored everywhere.',
                'With "format": "' . self::FORMAT . '" an unknown field rejects its row; without it (old board files) it is only a warning.',
                'POST /import?dry_run=1 validates and reports every problem with its row number and saves nothing.',
            ],
        ];
    }

    // ---- templates ----

    private static function empty_row($section)
    {
        $row = [];
        foreach (self::fields()[$section] as $name => $spec) {
            if ($name === 'created_at') {
                continue; // migration-only field; not part of a hand-written file
            }
            $row[$name] = $spec['default'] ?? ($spec['type'] === 'integer' || $spec['type'] === 'date' ? null : '');
        }
        return $row;
    }

    /** Synthetic sample rows (no real team data). Dates are far in the future so no sample task is late. */
    private static function sample($section)
    {
        $samples = [
            'tasks' => [
                ['id' => 'demo-t1', 'title' => 'نمونه: نوشتن متن معرفی ترک', 'description' => 'یک پاراگراف کوتاه برای صفحه‌ی ترک.', 'owner' => 'you', 'assignees' => ['maral'], 'status' => 'open', 'bucket' => 'boost', 'priority' => 'high', 'kind' => 'new', 'estimate_minutes' => 45, 'due_date' => '2030-01-15', 'done_criteria' => 'متن نهایی در پوشه‌ی مشترک و تأییدشده توسط یک نفر دیگر.'],
                ['id' => 'demo-t2', 'title' => 'نمونه: بررسی افکت‌های صوتی دسته‌ی اول', 'description' => '', 'owner' => 'chakra', 'assignees' => [], 'status' => 'in_progress', 'bucket' => 'normal', 'priority' => 'med', 'kind' => 'phase', 'estimate_minutes' => 90, 'due_date' => null, 'done_criteria' => ''],
                ['title' => 'نمونه: تسک بدون شناسه (تکراری بودن با عنوان تشخیص داده می‌شود)', 'description' => '', 'owner' => 'maral', 'assignees' => [], 'status' => 'open', 'bucket' => 'blocker', 'priority' => 'low', 'kind' => 'new', 'estimate_minutes' => 20, 'due_date' => '2030-02-01', 'done_criteria' => ''],
            ],
            'routines' => [
                ['id' => 'demo-r1', 'title' => 'نمونه: مرور صندوق پیام‌ها', 'description' => 'هر روز یک بار.', 'owner' => 'you', 'assignees' => [], 'frequency' => 'daily', 'status' => 'open', 'bucket' => 'normal', 'priority' => 'med', 'estimate_minutes' => 15, 'due_date' => null, 'done_criteria' => 'همه‌ی پیام‌ها پاسخ یا برچسب خورده‌اند.', 'recurrence_until' => null],
                ['id' => 'demo-r2', 'title' => 'نمونه: برنامه‌ی انتشار هفته‌ی بعد', 'description' => '', 'owner' => 'maral', 'assignees' => ['you'], 'frequency' => 'weekly', 'status' => 'open', 'bucket' => 'boost', 'priority' => 'high', 'estimate_minutes' => 60, 'due_date' => null, 'done_criteria' => '', 'recurrence_until' => '2030-12-31'],
                ['id' => 'demo-r3', 'title' => 'نمونه: گزارش ماهانه', 'description' => '', 'owner' => 'chakra', 'assignees' => [], 'frequency' => 'monthly', 'status' => 'open', 'bucket' => 'normal', 'priority' => 'low', 'estimate_minutes' => 120, 'due_date' => null, 'done_criteria' => '', 'recurrence_until' => null],
            ],
            'ideas' => [
                ['id' => 'demo-i1', 'title' => 'نمونه: ترک ملایم با پیانو و باران', 'description' => '', 'type' => 'track', 'category' => 'آرام', 'tag' => 'piano', 'status' => 'open'],
                ['id' => 'demo-i2', 'title' => 'نمونه: صدای قدم روی برف', 'description' => '', 'type' => 'sfx', 'category' => 'محیط', 'tag' => 'snow', 'status' => 'open'],
                ['title' => 'نمونه: ایده بدون شناسه', 'description' => '', 'type' => 'sfx', 'category' => 'محیط', 'tag' => '', 'status' => 'open'],
            ],
        ];
        return $samples[$section];
    }

    /** @return array the template: type = tasks|routines|ideas|full, variant = empty|sample */
    public static function template($type, $variant)
    {
        $sections = $type === 'full' ? self::SECTIONS : [$type];
        $out = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            '_help' => $variant === 'empty'
                ? 'قالب خالی: هر ردیف را پر کن یا پاک کن. فیلدهای اجباری: ' . self::required_names($sections) . '. شرح کامل: GET /schema یا docs/import-template/README.md.'
                : 'نمونه‌ی ساختگی. مقدارها را با داده‌ی خودت عوض کن؛ کلیدهایی که با _ شروع می‌شوند نادیده گرفته می‌شوند.',
        ];
        foreach ($sections as $section) {
            $out[$section] = [$variant === 'empty' ? self::empty_row($section) : null];
            if ($variant !== 'empty') {
                $out[$section] = self::sample($section);
            }
        }
        return $out;
    }

    private static function required_names(array $sections)
    {
        $parts = [];
        foreach ($sections as $s) {
            $parts[] = $s . ': ' . implode('، ', array_keys(array_filter(self::fields()[$s], function ($f) { return $f['required']; })));
        }
        return implode(' — ', $parts);
    }

    public static function template_json($type, $variant)
    {
        return wp_json_encode(self::template($type, $variant), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    public static function template_filename($type, $variant)
    {
        return $type . '-template' . ($variant === 'empty' ? '-empty' : '') . '.json';
    }
}
