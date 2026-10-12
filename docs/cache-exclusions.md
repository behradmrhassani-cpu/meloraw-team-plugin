# استثنای کش برای LiteSpeed و Cloudflare

هدف: هیچ پاسخی از افزونه (داده‌ی تسک‌ها، کامنت‌ها، لاگ، تنظیمات) نباید در کش سرور، CDN یا مرورگر بماند و به کسی که حق دیدنش را ندارد برسد.

## افزونه خودش چه می‌کند
روی **همه‌ی پاسخ‌های REST افزونه** (موفق، خطا، ۴۰۱، ۴۲۹) و روی **صفحه‌های ادمین افزونه** و handlerهای فرم‌هایشان (`admin-post.php?action=mlt_…`)، این هدرها فرستاده می‌شود:

| هدر | مقدار |
|---|---|
| `X-Robots-Tag` | `noindex, nofollow` |
| `Cache-Control` | `no-store, private` (وردپرس برای کاربر واردشده ممکن است `no-cache, must-revalidate, max-age=0` هم کنارش بگذارد؛ `no-store` همیشه هست) |
| `X-LiteSpeed-Cache-Control` | `no-cache` (بدون نیاز به افزونه‌ی LiteSpeed Cache) |

این هدرها لایه‌ی اول‌اند. بخش‌های پایین، لایه‌ی دوم است تا اگر تنظیمی یا CDNی هدرها را نادیده گرفت، باز هم چیزی کش نشود.

## مسیرهایی که باید exclude شوند

| مسیر | چیست |
|---|---|
| `/wp-json/meloraw-tasks/*` | REST API (ایجنت‌ها و پنل) |
| `/?rest_route=/meloraw-tasks/*` | همان REST، وقتی پیوندهای یکتا ساده است یا `/wp-json/` بازنویسی نمی‌شود |
| `/wp-admin/admin.php?page=meloraw-tasks*` | هفت صفحه‌ی پنل: `meloraw-tasks`، `-routines`، `-ideas`، `-reports`، `-log`، `-import`، `-settings` |
| `/wp-admin/admin-post.php` | ارسال فرم‌های پنل (`action=mlt_*`) |

فایل‌های استاتیک `/wp-content/plugins/meloraw-team-tasks/assets/*` داده ندارند و می‌توانند کش شوند (نسخه‌شان با `?ver=` عوض می‌شود).

## LiteSpeed

### با افزونه‌ی LiteSpeed Cache (پنل وردپرس)
1. **LiteSpeed Cache ← Cache ← Excludes ← Do Not Cache URIs** (هر خط یک مورد؛ از ابتدای مسیر تطبیق می‌دهد):
   ```
   /wp-json/meloraw-tasks/
   /wp-admin/admin-post.php
   ```
   `wp-admin` را LiteSpeed Cache به‌طور پیش‌فرض کش نمی‌کند؛ مورد دوم فقط صریح‌کردن است.
2. **… ← Excludes ← Do Not Cache Query Strings**:
   ```
   rest_route
   ```
   (فقط اگر پیوندهای یکتا ساده است یا REST با `?rest_route=` صدا زده می‌شود.)
3. **LiteSpeed Cache ← Cache ← Cache**: «Cache REST API» را **خاموش** کن، یا حداقل مطمئن شو مورد ۱ بالا ثبت شده. ایجنت‌ها با Application Password و بدون کوکی ورود می‌آیند؛ LiteSpeed آن‌ها را «ناشناس» می‌بیند، پس یک پاسخ موفق REST بدون این exclude قابل کش‌شدن است.
4. اگر QUIC.cloud CDN روشن است، بعد از ثبت exclude‌ها در افزونه، یک بار کش CDN را purge کن و بخش «بررسی بعد از اعمال» را بزن.

### بدون افزونه، با `.htaccess` (لایه‌ی اضافه؛ در LiteSpeed Enterprise و OpenLiteSpeed با پشتیبانی `.htaccess`)
بالای بلوک `# BEGIN WordPress` اضافه کن:
```apache
<IfModule LiteSpeed>
RewriteEngine On
RewriteRule ^wp-json/meloraw-tasks/ - [E=Cache-Control:no-cache]
RewriteCond %{QUERY_STRING} (^|&)rest_route=(%2F|/)?meloraw-tasks [NC]
RewriteRule .* - [E=Cache-Control:no-cache]
RewriteCond %{QUERY_STRING} (^|&)page=meloraw-tasks [NC]
RewriteRule ^wp-admin/ - [E=Cache-Control:no-cache]
RewriteRule ^wp-admin/admin-post\.php$ - [E=Cache-Control:no-cache]
</IfModule>
```

### اگر با Application Password درست، باز هم ۴۰۱ می‌گیری
روی بعضی LiteSpeedها هدر `Authorization` حذف می‌شود. این خط را در `.htaccess` بالای بلوک وردپرس بگذار:
```apache
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

## Cloudflare

### Cache Rule (Rules ← Cache Rules ← Create rule)
- **If**: Custom filter expression:
  ```
  (starts_with(http.request.uri.path, "/wp-json/meloraw-tasks/"))
  or (http.request.uri.query contains "rest_route=" and http.request.uri.query contains "meloraw-tasks")
  or (starts_with(http.request.uri.path, "/wp-admin/"))
  ```
  (سومی کل `wp-admin` را می‌گیرد که روش معمول است و صفحه‌های افزونه و `admin-post.php` را هم پوشش می‌دهد. اگر فقط افزونه را می‌خواهی: `http.request.uri.path eq "/wp-admin/admin-post.php"` و `http.request.uri.path eq "/wp-admin/admin.php" and http.request.uri.query contains "page=meloraw-tasks"`.)
- **Then**: Cache eligibility ← **Bypass cache**.
- روی این مسیرها «Edge Cache TTL» یا «Cache Everything» ست نکن، و اگر Cloudflare APO روشن است، پیش‌فرضش (دورزدن کوکی `wordpress_logged_in`) را نگه دار.

### Page Rules قدیمی (اگر هنوز از آن‌ها استفاده می‌کنی)
`*meloraw.com/wp-json/meloraw-tasks/*` ← Cache Level: Bypass
`*meloraw.com/wp-admin/*` ← Cache Level: Bypass

### اگر ایجنت‌ها از Cloudflare ۴۰۳ یا challenge می‌گیرند
Bot Fight Mode یا Browser Integrity Check ممکن است درخواست‌های بدون User-Agent مرورگری را رد کنند. یک قانون WAF Custom با Action=Skip فقط برای `starts_with(http.request.uri.path, "/wp-json/meloraw-tasks/")` بساز، و کنارش یک Rate Limiting Rule برای همین مسیر بگذار؛ افزونه فقط کاربرِ واردشده را محدود می‌کند (۱۲۰ درخواست در دقیقه) و تلاش‌های ناموفق ورود را نمی‌شمارد.

## بررسی بعد از اعمال
از هر ماشینی (رمز را از متغیر محیطی بخوان؛ در تاریخچه‌ی شل نگذار):
```bash
# ۱) بدون توکن: ۴۰۱ و هدرها
curl -sI https://meloraw.com/wp-json/meloraw-tasks/v1/tasks

# ۲) با Application Password، دو بار پشت‌سرهم؛ هر دو باید «MISS/BYPASS/DYNAMIC» باشند، هرگز «HIT»
curl -sI -u "$MLT_AUTH" https://meloraw.com/wp-json/meloraw-tasks/v1/tasks
curl -sI -u "$MLT_AUTH" https://meloraw.com/wp-json/meloraw-tasks/v1/tasks
```
انتظار در پاسخ:
- `x-robots-tag: noindex, nofollow`
- `cache-control` شامل `no-store`
- `x-litespeed-cache-control: no-cache`
- Cloudflare: `cf-cache-status: DYNAMIC` یا `BYPASS` (نه `HIT`)
- LiteSpeed: هدر `x-litespeed-cache: hit` نباید باشد

برای صفحه‌های ادمین: در مرورگر وارد شو، DevTools ← Network ← روی `admin.php?page=meloraw-tasks` بزن و همین هدرها را ببین.

> این راهنما روی سرور واقعی LiteSpeed و Cloudflare اجرا نشده؛ فقط هدرهای افزونه روی سایت آزمایشی محلی با curl بررسی شده‌اند. بعد از اعمال، حتماً مرحله‌ی «بررسی» را روی `meloraw.com` انجام بده.
