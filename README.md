# MELORAW Team Tasks

افزونه‌ی وردپرس برای تسک‌بورد داخلی تیم MELORAW. فقط ادمین‌ها به آن دسترسی دارن و ایجنت‌ها از طریق REST API با Application Password وصل می‌شن.

برای بریف کامل و قوانین پروژه، فایل [CLAUDE.md](CLAUDE.md) را ببین.

## نصب
1. فقط `meloraw-team-plugin.php`، `uninstall.php` و پوشه‌های `includes/` و `assets/` را در `wp-content/plugins/meloraw-team-tasks/` بگذار (مثلاً با یک zip). `CLAUDE.md`، `README.md`، `docs/`، `tests/` و `.git/` را روی سرور نگذار؛ وگرنه از وب قابل دانلود می‌شوند.
2. افزونه را از پنل وردپرس فعال کن.

## تنظیمات
رمزها و توکن‌ها **هرگز** داخل کد یا ریپو نیستن. در `wp-config.php` تعریف کن:

```php
define('MLT_TELEGRAM_BOT_TOKEN', 'توکن ربات');
define('MLT_TELEGRAM_CHAT_ID', 'chat id');
define('MLT_TELEGRAM_THREAD_ID', 'thread id');
```

## امکانات
هویت اعضا و برد «من/همه»، کد تسک (`MH-1`)، چند مسئول، زمان تقریبی ثابت با درخواست تمدید، زمان‌سنج با پاز، امتیاز، «امروز من»، گزارش زمان و تحویل به ایجنت: [docs/features.md](docs/features.md). متن آماده برای تکمیل تسک‌های قدیمی توسط ایجنت: [docs/agent-complete-tasks.md](docs/agent-complete-tasks.md). همه‌ی زمان‌ها میلادی و UTC هستند.

ایمپورت با قالب نمونه: [docs/import-template/README.md](docs/import-template/README.md). تست از صفر تا صد برای آدم غیرفنی: [docs/test-checklist.md](docs/test-checklist.md). صفحه‌ی «بررسی سلامت» در پنل مشکل نصب/سرور را با راه حل نشان می‌دهد.

## کش، هدرها و حذف افزونه
- همه‌ی پاسخ‌های REST و صفحه‌های ادمین افزونه هدر `noindex` و `no-store` دارند؛ استثنای کش لازم در LiteSpeed و Cloudflare: [docs/cache-exclusions.md](docs/cache-exclusions.md).
- هر کاربر در دقیقه حداکثر ۱۲۰ درخواست REST می‌تواند بزند؛ بیشتر از آن ۴۲۹ با `Retry-After` می‌گیرد.
- `GET /export` و `POST /import` (فقط ادمین): پشتیبان کامل تسک‌ها و کامنت‌ها، بدون رمز یا تنظیمات.
- حذف افزونه از وردپرس **داده‌ها را پاک نمی‌کند** (هر هشت جدول)، مگر در صفحه‌ی «تنظیمات» گزینه‌ی «حذف همه‌ی داده‌ها هنگام حذف افزونه» را روشن کرده باشی (پیش‌فرض خاموش). فقط توکن ربات تلگرام همیشه پاک می‌شود.
- Application Passwordهایی که اسمشان با `Claude-` یا `Hermes-` شروع می‌شود فقط روی `/wp-json/meloraw-tasks/v1` کار می‌کنند (نه روی بقیه‌ی REST وردپرس و نه XML-RPC)؛ رمزهای با اسم دیگر دست‌نخورده‌اند. اسم‌گذاری: `Claude-mh`، `Hermes-maral`.
- `POST /import` فیلدهای `*_login`، `agent` و `created_at`/`updated_at` فایل را نادیده می‌گیرد: همه‌ی ردیف‌ها به نام کاربرِ واردکننده و با زمان همان لحظه ثبت می‌شوند. namespace افزونه در `/wp-json/` عمومی دیده نمی‌شود.

## توسعه
- ظاهر کنترل‌ها: `python3 tests/css-check.py` (کنتراست همه‌ی حالت‌ها)، و با playwright: `node tests/css-states.js` روی صفحه‌ی نمونه‌ی `tests/style-sample.html` (قاعده‌های `:hover` و `:focus` خود وردپرس در آن شبیه‌سازی شده).
- این ریپو عمومیه. هیچ اطلاعات حساس یا رمز داخل commit نذار.
- قبل از push، `git grep` روی رمزها و توکن‌ها اجرا کن.
