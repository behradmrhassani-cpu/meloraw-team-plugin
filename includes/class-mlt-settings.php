<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Telegram settings (stored only; nothing is sent yet). Stored in the option mlt_settings.
 * The bot token is never put in any HTML, API output, notice or log: the field is always empty,
 * and the page only says whether a token is stored.
 */
class MLT_Settings
{
    const OPTION = 'mlt_settings';
    const CAP = 'manage_options'; // administrators only, even if MLT_CAP is given to other roles later

    /** field => constant that wp-config.php may define instead (then it wins and the field is locked) */
    const FIELDS = [
        'telegram_chat_id'   => 'MLT_TELEGRAM_CHAT_ID',
        'telegram_thread_id' => 'MLT_TELEGRAM_THREAD_ID',
        'telegram_bot_token' => 'MLT_TELEGRAM_BOT_TOKEN',
    ];

    /** The value in effect: a wp-config.php constant wins over the saved setting. Only MLT_Telegram reads the token. */
    public static function value($field)
    {
        return defined(self::FIELDS[$field]) ? (string) constant(self::FIELDS[$field]) : (string) (self::stored()[$field] ?? '');
    }

    private static function stored()
    {
        $value = get_option(self::OPTION, []);
        return is_array($value) ? $value : [];
    }

    /** Persian error for a submitted value, or null when it is fine. */
    private static function problem($field, $value)
    {
        if ($value === '') {
            return null;
        }
        if ($field === 'telegram_chat_id' && !preg_match('/^(-?\d{1,20}|@[A-Za-z0-9_]{4,32})$/', $value)) {
            return 'شناسه‌ی چت (chat id) باید عدد باشد (مثل ‎-100123456789) یا @نام_کانال.';
        }
        if ($field === 'telegram_thread_id' && !preg_match('/^\d{1,12}$/', $value)) {
            return 'شناسه‌ی تاپیک (thread id) باید عدد باشد.';
        }
        if ($field === 'telegram_bot_token' && !preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/', $value)) {
            return 'قالب توکن ربات درست نیست (باید مثل 123456789:ABC... باشد). مقدار ذخیره نشد.';
        }
        return null;
    }

    public static function handle_save()
    {
        MLT_Admin::verify('mlt_save_settings', self::CAP);
        $old = self::stored();
        $new = $old;
        $errors = [];

        foreach (self::FIELDS as $field => $constant) {
            if (defined($constant)) {
                continue; // wp-config.php wins; the form field is disabled
            }
            $value = isset($_POST[$field]) && is_string($_POST[$field]) ? trim(sanitize_text_field(wp_unslash($_POST[$field]))) : '';
            if ($field === 'telegram_bot_token') {
                if (!empty($_POST['clear_token'])) {
                    unset($new[$field]);
                } elseif ($value !== '') {
                    $error = self::problem($field, $value);
                    if ($error) {
                        $errors[] = $error;
                    } else {
                        $new[$field] = $value;
                    }
                }
                continue;
            }
            $error = self::problem($field, $value);
            if ($error) {
                $errors[] = $error;
            } elseif ($value === '') {
                unset($new[$field]);
            } else {
                $new[$field] = $value;
            }
        }

        if ($errors) {
            MLT_Admin::redirect_with(['type' => 'error', 'msg' => 'ذخیره نشد: ' . implode(' ', $errors)], MLT_Admin::SETTINGS_SLUG, [], '');
        }
        $changed = array_keys(array_filter(self::FIELDS, function ($c, $f) use ($old, $new) {
            return ($old[$f] ?? null) !== ($new[$f] ?? null);
        }, ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            update_option(self::OPTION, $new, false); // not autoloaded: the token is not read on every request
            MLT_REST::log_activity(null, 'settings_update', 'fields: ' . implode(', ', $changed)); // names only, never values
        }
        MLT_Admin::redirect_with(['type' => 'ok', 'msg' => $changed ? 'تنظیمات ذخیره شد.' : 'تغییری نبود.'], MLT_Admin::SETTINGS_SLUG, [], '');
    }

    public static function render()
    {
        if (!current_user_can(self::CAP)) {
            wp_die('دسترسی ندارید.');
        }
        $s = self::stored();
        $locked = function ($field) {
            return defined(self::FIELDS[$field]);
        };
        ?>
        <div class="mlt" dir="rtl">
            <header class="mlt-head">
                <h1>تنظیمات</h1>
            </header>
            <?php MLT_Admin::render_notice(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mlt-import mlt-settings" autocomplete="off">
                <input type="hidden" name="action" value="mlt_save_settings">
                <?php wp_nonce_field('mlt_save_settings'); ?>
                <p class="mlt-hint">اتصال به تلگرام. هیچ‌چیز خودکار به تلگرام نمی‌رود: پیام فقط وقتی ارسال می‌شود که کسی چک‌باکس «📣 ارسال به تلگرام» را بزند یا endpointهای notify را صدا بزند، و دقیقاً همان متن ارسال می‌شود. اگر مقداری در <code dir="ltr">wp-config.php</code> تعریف شده باشد (<code dir="ltr">MLT_TELEGRAM_*</code>)، همان استفاده می‌شود و فیلدش قفل است.</p>

                <label class="mlt-field">شناسه‌ی چت (chat id)
                    <input type="text" name="telegram_chat_id" dir="ltr" maxlength="40" value="<?php echo $locked('telegram_chat_id') ? '' : esc_attr($s['telegram_chat_id'] ?? ''); ?>"<?php echo $locked('telegram_chat_id') ? ' disabled placeholder="در wp-config.php تعریف شده"' : ''; ?>>
                </label>
                <label class="mlt-field">شناسه‌ی تاپیک (thread id)
                    <input type="text" name="telegram_thread_id" dir="ltr" maxlength="12" value="<?php echo $locked('telegram_thread_id') ? '' : esc_attr($s['telegram_thread_id'] ?? ''); ?>"<?php echo $locked('telegram_thread_id') ? ' disabled placeholder="در wp-config.php تعریف شده"' : ''; ?>>
                </label>
                <label class="mlt-field">توکن ربات
                    <input type="password" name="telegram_bot_token" dir="ltr" value="" autocomplete="new-password" maxlength="80"<?php echo $locked('telegram_bot_token') ? ' disabled placeholder="در wp-config.php تعریف شده"' : ' placeholder="خالی بگذار تا توکن فعلی بماند"'; ?>>
                </label>
                <p class="mlt-hint">
                    وضعیت توکن:
                    <?php echo $locked('telegram_bot_token') ? 'از <code dir="ltr">wp-config.php</code>' : (!empty($s['telegram_bot_token']) ? '<strong>ذخیره شده است</strong> (مقدارش هیچ‌جا نمایش داده نمی‌شود)' : 'تنظیم نشده'); ?>
                </p>
                <?php if (!$locked('telegram_bot_token') && !empty($s['telegram_bot_token'])) : ?>
                    <label class="mlt-check"><input type="checkbox" name="clear_token" value="1"> توکن ذخیره‌شده پاک شود</label>
                <?php endif; ?>
                <div><button type="submit" class="mlt-btn">ذخیره</button></div>
            </form>
        </div>
        <?php
    }
}
