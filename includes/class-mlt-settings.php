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
    const DELETE_FLAG = 'delete_on_uninstall'; // read by uninstall.php; off unless someone ticks it

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

    /** WordPress accounts that can use the plugin (Administrators). */
    public static function team_accounts()
    {
        return get_users(['role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC']);
    }

    /** One key of the saved settings (non-secret ones: identity map, hours, announcement). */
    public static function option($key)
    {
        return self::stored()[$key] ?? null;
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

        // who is who: WordPress account -> you / maral / chakra / auto
        $map = [];
        $posted = isset($_POST['identity']) && is_array($_POST['identity']) ? wp_unslash($_POST['identity']) : [];
        foreach (self::team_accounts() as $account) {
            $slug = isset($posted[$account->ID]) && is_string($posted[$account->ID]) ? sanitize_key($posted[$account->ID]) : '';
            if (in_array($slug, MLT_Identity::slugs(), true)) {
                $map[$account->ID] = $slug;
            }
        }
        if ($map) {
            $new['identity_map'] = $map;
        } else {
            unset($new['identity_map']);
        }

        // peak-energy hour per person (UTC hour 0-23): the panel shows a hint during it
        $hours = [];
        $posted_hours = isset($_POST['peak']) && is_array($_POST['peak']) ? wp_unslash($_POST['peak']) : [];
        foreach (MLT_Identity::slugs() as $slug) {
            $h = isset($posted_hours[$slug]) && is_string($posted_hours[$slug]) ? $posted_hours[$slug] : '';
            if ($h !== '' && ctype_digit($h) && (int) $h <= 23) {
                $hours[$slug] = (int) $h;
            }
        }
        if ($hours) {
            $new['peak_hours'] = $hours;
        } else {
            unset($new['peak_hours']);
        }

        // announcement for everybody, inside the panel only
        $text = isset($_POST['announcement_text']) && is_string($_POST['announcement_text']) ? trim(sanitize_textarea_field(wp_unslash($_POST['announcement_text']))) : '';
        $expires = isset($_POST['announcement_expires']) && is_string($_POST['announcement_expires']) ? MLT_Taskmeta::date(sanitize_text_field(wp_unslash($_POST['announcement_expires']))) : null;
        if ($expires === false) {
            $errors[] = 'تاریخ انقضای اعلان باید به شکل YYYY-MM-DD باشد.';
            $expires = null;
        }
        if (mb_strlen($text) > 500) {
            $errors[] = 'متن اعلان بیش از ۵۰۰ نویسه است.';
        } elseif ($text !== '') {
            $new['announcement'] = ['text' => $text, 'expires' => $expires, 'active' => !empty($_POST['announcement_active']) ? 1 : 0];
        } else {
            unset($new['announcement']);
        }

        if (!empty($_POST[self::DELETE_FLAG])) {
            $new[self::DELETE_FLAG] = '1';
        } else {
            unset($new[self::DELETE_FLAG]);
        }

        if ($errors) {
            MLT_Admin::redirect_with(['type' => 'error', 'msg' => 'ذخیره نشد: ' . implode(' ', $errors)], MLT_Admin::SETTINGS_SLUG, [], '');
        }
        $changed = array_values(array_filter(array_merge(array_keys(self::FIELDS), [self::DELETE_FLAG, 'identity_map', 'peak_hours', 'announcement']), function ($f) use ($old, $new) {
            return ($old[$f] ?? null) !== ($new[$f] ?? null);
        }));
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
            <?php MLT_Admin::topbar(); ?>
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
                <h2 class="mlt-sub">هویت اعضا</h2>
                <p class="mlt-hint">هر حساب وردپرس را به یکی از اعضای تیم وصل کن. برد پیش‌فرض تسک‌های همان نفر را نشان می‌دهد و کد تسک‌های تازه (MH، MA، CH، AU) از روی سازنده ساخته می‌شود. ایجنت‌ها با Application Password همان حساب، همان هویت را دارند. حسابی که وصل نشده باشد همه‌ی تسک‌ها را می‌بیند و پیام یادآوری می‌گیرد.</p>
                <?php $identity_map = MLT_Identity::map(); ?>
                <table class="mlt-idtable">
                    <?php foreach (self::team_accounts() as $account) : ?>
                        <tr>
                            <th><?php echo esc_html($account->display_name); ?> <code dir="ltr"><?php echo esc_html($account->user_login); ?></code></th>
                            <td>
                                <select name="identity[<?php echo (int) $account->ID; ?>]" aria-label="هویت <?php echo esc_attr($account->user_login); ?>">
                                    <option value="">— وصل نشده</option>
                                    <?php foreach (MLT_Admin::LABELS['owner'] as $slug => $label) : ?>
                                        <option value="<?php echo esc_attr($slug); ?>"<?php selected(($identity_map[$account->ID] ?? ''), $slug); ?>><?php echo esc_html($label . ' (' . MLT_Identity::prefix($slug) . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <h2 class="mlt-sub">ساعت اوج انرژی</h2>
                <p class="mlt-hint">ساعت (۰ تا ۲۳، به وقت UTC) که هر نفر معمولاً بهترین تمرکز را دارد. در همان ساعت یک پیام داخل پنل (نه تلگرام) یادآوری می‌کند که وقت کار روی «تمرکز اصلی» است.</p>
                <?php $peak = MLT_Settings::option('peak_hours'); $peak = is_array($peak) ? $peak : []; ?>
                <table class="mlt-idtable">
                    <?php foreach (MLT_Admin::LABELS['owner'] as $slug => $label) : ?>
                        <tr>
                            <th><?php echo esc_html($label); ?></th>
                            <td>
                                <select name="peak[<?php echo esc_attr($slug); ?>]" aria-label="ساعت اوج <?php echo esc_attr($label); ?>">
                                    <option value="">— تعیین نشده</option>
                                    <?php for ($h = 0; $h < 24; $h++) : ?>
                                        <option value="<?php echo (int) $h; ?>"<?php selected(isset($peak[$slug]) ? (int) $peak[$slug] : -1, $h); ?>><?php echo esc_html(sprintf('%02d:00 UTC', $h)); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <h2 class="mlt-sub">اعلان سراسری</h2>
                <p class="mlt-hint">یک پیام که بالای همه‌ی صفحه‌های افزونه برای همه دیده می‌شود (فقط داخل پنل؛ به تلگرام نمی‌رود). با تاریخ انقضا خودش محو می‌شود.</p>
                <?php $ann = MLT_Settings::option('announcement'); $ann = is_array($ann) ? $ann : []; ?>
                <label class="mlt-field">متن اعلان (حداکثر ۵۰۰ نویسه؛ خالی = بدون اعلان)
                    <textarea name="announcement_text" rows="3" maxlength="500"><?php echo esc_textarea($ann['text'] ?? ''); ?></textarea>
                </label>
                <label class="mlt-field">تاریخ انقضا (اختیاری، YYYY-MM-DD به وقت UTC)
                    <input type="date" name="announcement_expires" dir="ltr" value="<?php echo esc_attr($ann['expires'] ?? ''); ?>">
                </label>
                <label class="mlt-check"><input type="checkbox" name="announcement_active" value="1"<?php checked(!empty($ann['active'])); ?>> فعال باشد</label>
                <h2 class="mlt-sub">حذف افزونه</h2>
                <label class="mlt-check"><input type="checkbox" name="delete_on_uninstall" value="1"<?php checked(!empty($s[self::DELETE_FLAG])); ?>> هنگام حذف افزونه از وردپرس، همه‌ی داده‌ها (تسک‌ها، کامنت‌ها، لاگ و تنظیمات) برای همیشه پاک شود</label>
                <p class="mlt-hint">پیش‌فرض خاموش است: با حذف افزونه هیچ داده‌ای پاک نمی‌شود. اگر روشنش کنی، «حذف» افزونه از صفحه‌ی افزونه‌های وردپرس بدون هیچ تأیید دیگری همه‌چیز را پاک می‌کند؛ قبلش از <code dir="ltr">GET /export</code> خروجی بگیر.</p>
                <div><button type="submit" class="mlt-btn">ذخیره</button></div>
            </form>
        </div>
        <?php
    }
}
