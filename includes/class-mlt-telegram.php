<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manual Telegram messages. Nothing is ever sent unless a user asks for it (a ticked checkbox in a
 * form, or a call to POST /notify or POST /tasks/{id}/notify), and exactly the given text is sent.
 *
 * The bot token is part of Telegram's URL, so no error text from WordPress or Telegram is ever shown
 * or logged: a failure is reduced to a fixed category (timeout, http_401, ...) with a fixed message.
 */
class MLT_Telegram
{
    const TIMEOUT = 5;     // seconds, connect + response
    const MAX_TEXT = 4096; // Telegram's limit
    const LIMIT = 10;      // messages per user per window
    const WINDOW = 60;     // seconds

    const REASONS = [
        'not_configured' => 'توکن یا chat id در تنظیمات (یا wp-config.php) کامل نیست.',
        'timeout'        => 'تلگرام در دسترس نیست (مهلت پاسخ تمام شد).',
        'network'        => 'اتصال به تلگرام برقرار نشد.',
        'http_401'       => 'توکن ربات نامعتبر است.',
        'http_404'       => 'توکن ربات نامعتبر است.',
        'http_400'       => 'تلگرام درخواست را رد کرد (chat id یا thread id را بررسی کن).',
        'http_403'       => 'ربات اجازه‌ی ارسال به این چت را ندارد.',
        'http_429'       => 'تلگرام موقتاً محدودیت گذاشته؛ کمی بعد دوباره امتحان کن.',
        'http_5xx'       => 'تلگرام خطای سرور داد.',
        'bad_response'   => 'پاسخ تلگرام معتبر نبود.',
    ];

    /** Text as typed, minus control characters (ZWNJ and other Persian format characters are kept). */
    public static function clean($text)
    {
        $text = wp_check_invalid_utf8((string) $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text));
    }

    public static function task_link($id)
    {
        return add_query_arg(['page' => MLT_Admin::SLUG, 'comments' => (int) $id], admin_url('admin.php')) . '#mlt-t-' . (int) $id;
    }

    /** Title, owner, status, sender and a link to the admin page (the same fields the forms pre-fill). */
    public static function default_text(array $task, $header)
    {
        $agent = MLT_REST::current_agent();
        return implode("\n", [
            $header,
            'عنوان: ' . $task['title'],
            'مسئول: ' . (MLT_Admin::LABELS['owner'][$task['owner']] ?? $task['owner']),
            'وضعیت: ' . (MLT_Admin::LABELS['status'][$task['status']] ?? $task['status']),
            'فرستنده: ' . wp_get_current_user()->display_name . ($agent ? ' (' . $agent . ')' : ''),
            'لینک: ' . self::task_link($task['id']),
        ]);
    }

    /**
     * The only entry point that sends. Checks the text and the configuration, applies the per-user
     * rate limit, sends, and logs the outcome (who, which task, ok/failed; never the text).
     * @return true|WP_Error
     */
    public static function deliver($text, $task_id)
    {
        $text = self::clean($text);
        if ($text === '') {
            return new WP_Error('mlt_bad_request', 'متن پیام خالی است.', ['status' => 400]);
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            return new WP_Error('mlt_bad_request', 'متن پیام بیش از ' . self::MAX_TEXT . ' نویسه است.', ['status' => 400]);
        }

        $token = MLT_Settings::value('telegram_bot_token');
        $chat = MLT_Settings::value('telegram_chat_id');
        $thread = MLT_Settings::value('telegram_thread_id');
        if (!preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/', $token) || $chat === '') {
            return self::failed($task_id, 'not_configured', 409);
        }

        $wait = self::throttle(get_current_user_id());
        if ($wait) {
            return new WP_Error('mlt_rate_limited', 'تعداد ارسال‌ها بیش از حد مجاز است؛ ' . $wait . ' ثانیه دیگر دوباره امتحان کن.', ['status' => 429, 'retry_after' => $wait]);
        }

        $body = ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true'];
        if ($thread !== '') {
            $body['message_thread_id'] = $thread;
        }
        $response = wp_remote_post('https://api.telegram.org/bot' . $token . '/sendMessage', [
            'timeout'             => self::TIMEOUT,
            'redirection'         => 0,
            'limit_response_size' => 65536,
            'body'                => $body,
        ]);

        $reason = self::reason($response);
        if ($reason !== null) {
            return self::failed($task_id, $reason, 502);
        }
        MLT_REST::log_activity($task_id, 'telegram_notify', 'sent');
        return true;
    }

    /** null when Telegram accepted the message, otherwise a key of REASONS. Never looks at error text beyond timeout/network. */
    private static function reason($response)
    {
        if (is_wp_error($response)) {
            return stripos($response->get_error_message(), 'time') !== false ? 'timeout' : 'network';
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code === 200 && is_array($json) && ($json['ok'] ?? false) === true) {
            return null;
        }
        if ($code === 200 || $code < 100) {
            return 'bad_response';
        }
        $key = $code >= 500 ? 'http_5xx' : 'http_' . $code;
        return isset(self::REASONS[$key]) ? $key : 'bad_response';
    }

    private static function failed($task_id, $reason, $status)
    {
        MLT_REST::log_activity($task_id, 'telegram_error', 'failed: ' . $reason);
        return new WP_Error('mlt_telegram_failed', 'ارسال به تلگرام انجام نشد: ' . self::REASONS[$reason], ['status' => $status, 'reason' => $reason]);
    }

    /** Per-user limit for sending (see MLT_RateLimit). Returns 0 if allowed, else the seconds until the window ends. */
    public static function throttle($user_id)
    {
        return MLT_RateLimit::hit('rl', $user_id, self::LIMIT, self::WINDOW);
    }
}
