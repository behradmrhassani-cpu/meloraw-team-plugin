# MELORAW Team Tasks

افزونه‌ی وردپرس برای تسک‌بورد داخلی تیم MELORAW. فقط ادمین‌ها به آن دسترسی دارن و ایجنت‌ها از طریق REST API با Application Password وصل می‌شن.

برای بریف کامل و قوانین پروژه، فایل [CLAUDE.md](CLAUDE.md) را ببین.

## نصب
1. پوشه‌ی این ریپو را در `wp-content/plugins/meloraw-team-tasks/` کپی کن.
2. افزونه را از پنل وردپرس فعال کن.

## تنظیمات
رمزها و توکن‌ها **هرگز** داخل کد یا ریپو نیستن. در `wp-config.php` تعریف کن:

```php
define('MLT_TELEGRAM_BOT_TOKEN', 'توکن ربات');
define('MLT_TELEGRAM_CHAT_ID', 'chat id');
define('MLT_TELEGRAM_THREAD_ID', 'thread id');
```

## توسعه
- این ریپو عمومیه. هیچ اطلاعات حساس یا رمز داخل commit نذار.
- قبل از push، `git grep` روی رمزها و توکن‌ها اجرا کن.
