#!/usr/bin/env bash
# تست curl برای API افزونه. فقط روی سایت تست یا کپی اجرا شود؛ تسک‌های تست را در پایان حذف می‌کند.
#
#   MLT_BASE=https://example.com/wp-json/meloraw-tasks/v1 \
#   MLT_AUTH='user:xxxx xxxx xxxx xxxx xxxx xxxx' \
#   [MLT_AUTH_NOCAP='editor:xxxx ...'] \
#   tests/api-smoke.sh [auth|tasks|comments|flag|status|routines|notify|fields|estimate|timer|score|headers|scope|export|ratelimit|hidden|all]  (ratelimit جزو all نیست)
#
# رمزها فقط از متغیر محیطی خوانده می‌شوند و چاپ نمی‌شوند. نیازمند curl و jq.

set -u
: "${MLT_BASE:?MLT_BASE را تنظیم کنید}"
: "${MLT_AUTH:?MLT_AUTH را تنظیم کنید}"
SECTION="${1:-all}"
FAIL=0
CREATED=()

ok()   { echo "  ✓ $1"; }
bad()  { echo "  ✗ $1"; FAIL=1; }

# req METHOD PATH [JSON] [AUTH|-]  → sets CODE and BODY (and HDRS, the response headers)
# اگر rate limit کلی (۱۲۰ درخواست در دقیقه برای هر کاربر) فعال شد، به اندازه‌ی Retry-After صبر می‌کند و یک بار دوباره می‌زند.
req() {
  local method=$1 path=$2 data=${3:-} auth=${4:-$MLT_AUTH} try
  local args=(-s -o /tmp/mlt_body.$$ -D /tmp/mlt_hdr.$$ -w '%{http_code}' -X "$method" -H 'Content-Type: application/json')
  [ "$auth" != "-" ] && args+=(-u "$auth")
  [ -n "$data" ] && args+=(--data "$data")
  for try in 1 2; do
    CODE=$(curl "${args[@]}" "$MLT_BASE$path")
    [ "$CODE" = 429 ] && [ $try = 1 ] && [ "${NO_RETRY:-}" != 1 ] || break
    sleep "$(tr -d '\r' < /tmp/mlt_hdr.$$ | awk -F': ' 'tolower($1)=="retry-after"{print $2}' | head -1)"
  done
  BODY=$(cat /tmp/mlt_body.$$)
  HDRS=$(tr -d '\r' < /tmp/mlt_hdr.$$)
}

expect_code() { [ "$CODE" = "$1" ] && ok "$2 → $CODE" || bad "$2 → $CODE (انتظار $1): $BODY"; }
# پیام خطا باید فارسی باشد و متن انگلیسی وردپرس در آن نباشد
expect_persian() { echo "$BODY" | jq -e '.message | test("[آ-ی]") and (test("Invalid|parameter|No route|Missing|Sorry") | not)' >/dev/null && ok "$1" || bad "$1: $(echo "$BODY" | jq -c '.message')"; }
expect_jq()   { [ "$(echo "$BODY" | jq -r "$1")" = "$2" ] && ok "$3" || bad "$3: $(echo "$BODY" | jq -c "$1")"; }

# new_task JSON → sets NEW_ID (no subshell, so CREATED is kept for cleanup)
new_task() {
  # زمان تقریبی از ساخت تسک اجباری است؛ اگر نیامده بود ۳۰ دقیقه می‌گذاریم
  local json=$1
  echo "$json" | jq -e 'has("estimate_minutes")' >/dev/null 2>&1 || json=$(echo "$json" | jq -c '. + {estimate_minutes: 30}')
  req POST /tasks "$json"
  NEW_ID=$(echo "$BODY" | jq -r '.id // empty')
  [ -n "$NEW_ID" ] && CREATED+=("$NEW_ID")
}

cleanup() {
  for id in "${CREATED[@]}"; do req DELETE "/tasks/$id"; done
  rm -f /tmp/mlt_body.$$ /tmp/mlt_hdr.$$
}
trap cleanup EXIT

run_auth() {
  echo "== احراز هویت"
  req GET /tasks "" -;                 expect_code 401 "بدون توکن"
  expect_jq '.message' 'دسترسی ندارید.' "پیام خطا کلی است"
  req GET /tasks "" "nobody:wrong-pass"; expect_code 401 "رمز اشتباه"
  req GET "/tasks?owner=hacker" "" -;  expect_code 401 "پارامتر نامعتبر بدون توکن (نه 400 تفصیلی)"
  echo "$BODY" | grep -q maral && bad "خطا اسم مسئول‌ها را لو داد" || ok "خطا جزئیاتی لو نداد"
  req POST /tasks/1/comments '{}' -;   expect_code 401 "کامنت بدون توکن"
  CODE=$(curl -s "${MLT_BASE%/meloraw-tasks/v1}/" | grep -c 'meloraw-tasks/v1/tasks')
  [ "$CODE" = 0 ] && ok "مسیرها در ایندکس عمومی /wp-json نیستند" || bad "مسیرها در ایندکس عمومی دیده می‌شوند"
  if [ -n "${MLT_AUTH_NOCAP:-}" ]; then
    req GET /tasks "" "$MLT_AUTH_NOCAP"; expect_code 403 "کاربر بدون نقش ادمین"
    req POST /tasks '{"title":"x"}' "$MLT_AUTH_NOCAP"; expect_code 403 "ساخت تسک بدون نقش ادمین"
  fi
}

run_tasks() {
  echo "== CRUD تسک"
  local id
  req POST /tasks '{"title":"تست <b>API</b>","description":"خط اول\nخط دوم","owner":"maral","bucket":"boost","priority":"high","estimate_minutes":30}'
  expect_code 201 "ساخت تسک با رمز"
  id=$(echo "$BODY" | jq -r '.id'); CREATED+=("$id")
  expect_jq '.title' 'تست API' "تگ HTML از عنوان حذف شد"
  expect_jq '.status' 'open' "وضعیت پیش‌فرض open"
  expect_jq '.kind' 'new' "نوع پیش‌فرض new"
  expect_jq '.created_by > 0' 'true' "created_by ثبت شد"

  req GET "/tasks/$id";                expect_code 200 "خواندن تسک"
  expect_jq '.owner' 'maral' "مسئول درست خوانده شد"
  expect_jq '.description' $'خط اول\nخط دوم' "خط‌های توضیح حفظ شد"

  req GET "/tasks?owner=maral&bucket=boost"; expect_code 200 "فیلتر لیست"
  expect_jq "[.[] | select(.id == $id)] | length" '1' "تسک در لیست فیلترشده هست"

  req PATCH "/tasks/$id" '{"title":"تست ویرایش‌شده","priority":"low"}'
  expect_code 200 "ویرایش تسک"
  expect_jq '.title + "|" + .priority + "|" + .owner' 'تست ویرایش‌شده|low|maral' "فقط فیلدهای ارسالی عوض شدند"

  req POST /tasks '{"description":"بی‌عنوان"}';      expect_code 400 "ساخت بدون عنوان"
  req POST /tasks '{"title":"   "}';                 expect_code 400 "عنوان خالی"
  req POST /tasks '{"title":"x","owner":"hacker"}';   expect_code 400 "مسئول نامعتبر"
  expect_jq '.code + "|" + (.data.fields | join(","))' 'mlt_invalid_param|owner' "کد و نام فیلد خطا"
  expect_persian "پیام خطای مسئول نامعتبر فارسی است"
  req POST /tasks '{bad json';                       expect_code 400 "JSON خراب"
  expect_persian "پیام JSON خراب فارسی است"
  req GET /no-such-route;                            expect_code 404 "مسیر ناموجود"
  expect_persian "پیام مسیر ناموجود فارسی است"
  req POST /tasks '{"title":"x","kind":"routine"}';   expect_code 400 "ساخت روتین از این مسیر ممنوع"
  req PATCH "/tasks/$id" '{}';                       expect_code 400 "ویرایش بدون فیلد"
  req GET /tasks/999999999;                          expect_code 404 "تسک ناموجود"

  req DELETE "/tasks/$id";             expect_code 200 "حذف تسک"
  req GET "/tasks/$id";                expect_code 404 "بعد از حذف پیدا نمی‌شود"
}

run_comments() {
  echo "== کامنت"
  local id cid
  new_task '{"title":"تست کامنت"}'; id=$NEW_ID
  req POST "/tasks/$id/comments" '{"body":"سلام <script>alert(1)</script> لینک: https://meloraw.com"}'
  expect_code 201 "ساخت کامنت با رمز"
  cid=$(echo "$BODY" | jq -r '.id')
  expect_jq '.body | contains("<script>")' 'false' "اسکریپت از متن حذف شد"
  expect_jq '.body | contains("https://meloraw.com")' 'true' "لینک حفظ شد"
  expect_jq '.flagged' 'false' "پیش‌فرض بدون پرچم"
  expect_jq '.agent != null' 'true' "نام ایجنت (Application Password) ثبت شد"

  req GET "/tasks/$id/comments";       expect_code 200 "خواندن کامنت‌ها"
  expect_jq "[.[] | select(.id == $cid)] | length" '1' "کامنت در لیست هست"

  req POST "/tasks/$id/comments" '{"body":"  "}';   expect_code 400 "کامنت خالی"
  expect_persian "پیام کامنت خالی فارسی است"
  req POST "/tasks/$id/comments" '{}';              expect_code 400 "کامنت بدون فیلد body"
  expect_jq '.code' 'mlt_missing_param' "کد خطای فیلد الزامی"
  expect_persian "پیام فیلد الزامی فارسی است"
  req POST /tasks/999999999/comments '{"body":"x"}'; expect_code 404 "کامنت روی تسک ناموجود"
  req GET /tasks/999999999/comments;                expect_code 404 "خواندن کامنت تسک ناموجود"
}

run_flag() {
  echo "== پرچم"
  local id id2 cid
  new_task '{"title":"تست پرچم"}'; id=$NEW_ID
  req POST "/tasks/$id/comments" '{"body":"این باید تسک بشه"}'; cid=$(echo "$BODY" | jq -r '.id')
  req POST "/tasks/$id/comments/$cid/flag"; expect_code 200 "پرچم زدن"
  expect_jq '.flagged' 'true' "کامنت پرچم‌دار شد"
  req GET "/tasks/$id/comments";       expect_jq ".[] | select(.id == $cid) | .flagged" 'true' "پرچم در خواندن دیده می‌شود"
  req POST "/tasks/$id/comments/$cid/flag"; expect_code 200 "پرچم دوباره (بی‌اثر)"
  new_task '{"title":"تسک دیگر"}'; id2=$NEW_ID
  req POST "/tasks/$id2/comments/$cid/flag"; expect_code 404 "پرچم کامنت با تسک اشتباه"
  req POST "/tasks/$id/comments/999999999/flag"; expect_code 404 "پرچم کامنت ناموجود"
}

run_status() {
  echo "== وضعیت سه‌حالته"
  local id s
  new_task '{"title":"تست وضعیت"}'; id=$NEW_ID
  for s in in_progress done open; do
    req PATCH "/tasks/$id" "{\"status\":\"$s\"}"; expect_code 200 "تغییر به $s"
    req GET "/tasks/$id"; expect_jq '.status' "$s" "خواندن وضعیت $s"
  done
  req PATCH "/tasks/$id" '{"status":"archived"}'; expect_code 400 "وضعیت نامعتبر"
  req GET "/tasks?status=in_progress"; expect_code 200 "فیلتر in_progress"
}

# فقط خواندن و اعتبارسنجی؛ وضعیت هیچ روتینی را عوض نمی‌کند (روتین‌ها حذف‌شدنی نیستند)
run_routines() {
  echo "== روتین‌ها"
  req GET /routines "" -;                          expect_code 401 "خواندن روتین‌ها بدون توکن"
  req PATCH /routines/zz-none '{"status":"done"}' -; expect_code 401 "تغییر روتین بدون توکن"
  req GET /routines;                               expect_code 200 "خواندن روتین‌ها با رمز"
  expect_jq 'type' 'array' "خروجی آرایه است"
  expect_jq 'all(.[]; (keys | sort) == ["description","done_criteria","estimate_minutes","frequency","incomplete","key","owner","period_key","recurrence_until","status","stopped","task_id","title"])' 'true' "ساختار هر روتین"
  expect_jq 'all(.[]; .frequency as $f | (.period_key | test(if $f == "daily" then "^[0-9]{4}-[0-9]{2}-[0-9]{2}$" elif $f == "weekly" then "^[0-9]{4}-W[0-9]{2}$" else "^[0-9]{4}-[0-9]{2}$" end)))' 'true' "کلید دوره با تناوب می‌خواند"
  req GET "/routines?period=yearly";               expect_code 400 "تناوب نامعتبر"
  expect_jq '.code' 'mlt_invalid_param' "کد خطای تناوب نامعتبر"
  expect_persian "پیام تناوب نامعتبر فارسی است"
  req PATCH /routines/zz-none '{"status":"archived"}'; expect_code 400 "وضعیت نامعتبر روتین"
  req PATCH /routines/zz-none '{}';                expect_code 400 "روتین بدون وضعیت"
  expect_jq '.code' 'mlt_bad_request' "کد خطا: status یا قالب لازم است"
  req PATCH /routines/zz-none '{"status":"done"}'; expect_code 404 "روتین ناموجود"
  expect_persian "پیام روتین ناموجود فارسی است"
  if [ -n "${MLT_AUTH_NOCAP:-}" ]; then
    req GET /routines "" "$MLT_AUTH_NOCAP";       expect_code 403 "خواندن روتین‌ها بدون نقش ادمین"
    req PATCH /routines/zz-none '{"status":"done"}' "$MLT_AUTH_NOCAP"; expect_code 403 "تغییر روتین بدون نقش ادمین"
  fi
}

# تنظیمات (توکن ربات) و لاگ فقط از پنل ادمین دیده می‌شوند؛ هیچ مسیر APIای برایشان نیست
run_hidden() {
  echo "== مسیرهایی که نباید وجود داشته باشند"
  req GET /settings;                               expect_code 404 "مسیر settings وجود ندارد"
  req GET /log;                                    expect_code 404 "مسیر log وجود ندارد"
  req GET /activity;                               expect_code 404 "مسیر activity وجود ندارد"
  expect_persian "پیام مسیر ناموجود فارسی است"
}

# اعلان تلگرام: فقط حالت‌هایی که هیچ‌وقت پیام واقعی نمی‌فرستند (بدون توکن، بدون دسترسی، ورودی نامعتبر، تسک ناموجود).
# ارسال موفق را اینجا نمی‌زنیم؛ آن با درخواست HTTP ساختگی روی سایت تست بررسی می‌شود.
run_notify() {
  echo "== اعلان تلگرام (بدون ارسال واقعی)"
  req POST /notify '{"text":"x"}' -;               expect_code 401 "notify بدون توکن"
  req POST /tasks/1/notify '{"text":"x"}' -;       expect_code 401 "notify تسک بدون توکن"
  req POST /notify '{}';                           expect_code 400 "notify بدون متن"
  expect_jq '.code' 'mlt_missing_param' "کد خطای متن الزامی"
  expect_persian "پیام نبودن متن فارسی است"
  req POST /notify '{"text":"   "}';               expect_code 400 "notify با متن خالی"
  expect_persian "پیام متن خالی فارسی است"
  req POST /notify "{\"text\":\"$(head -c 4100 /dev/zero | tr '\0' 'a')\"}"; expect_code 400 "notify با متن خیلی بلند"
  expect_persian "پیام متن بلند فارسی است"
  req POST /tasks/999999999/notify '{"text":"x"}'; expect_code 404 "notify تسک ناموجود"
  expect_persian "پیام تسک ناموجود فارسی است"
  if [ -n "${MLT_AUTH_NOCAP:-}" ]; then
    req POST /notify '{"text":"x"}' "$MLT_AUTH_NOCAP";         expect_code 403 "notify بدون نقش ادمین"
    req POST /tasks/1/notify '{"text":"x"}' "$MLT_AUTH_NOCAP"; expect_code 403 "notify تسک بدون نقش ادمین"
  fi
}

# کد خوانا، چند مسئول، فیلدهای قالب واحد (تخمین، سررسید، معیار انجام)
run_fields() {
  echo "== کد تسک، مسئول‌ها و فیلدهای قالب"
  local id code i
  req GET /me "" -;                                expect_code 401 "me بدون توکن"
  req GET /me;                                     expect_code 200 "me با ادمین"
  expect_jq 'has("identity") and has("prefix")' 'true' "me هویت را برمی‌گرداند"
  local identity; identity=$(echo "$BODY" | jq -r '.identity // empty')

  req POST /tasks '{"title":"بدون تخمین"}';        expect_code 400 "ساخت بدون زمان تقریبی"
  expect_persian "پیام نبودن تخمین فارسی است"
  req POST /tasks '{"title":"x","estimate_minutes":4}'; expect_code 400 "تخمین کمتر از ۵"
  req POST /tasks '{"title":"x","estimate_minutes":"abc"}'; expect_code 400 "تخمین غیرعددی"
  req POST /tasks '{"title":"x","estimate_minutes":5,"due_date":"2026-13-40"}'; expect_code 400 "سررسید نامعتبر"
  req POST /tasks '{"title":"x","estimate_minutes":5,"assignees":["hacker"]}'; expect_code 400 "مسئول نامعتبر در assignees"

  new_task '{"title":"قالب یک","owner":"maral","estimate_minutes":45,"due_date":"2031-01-02","done_criteria":"معیار تست"}'; id=$NEW_ID
  expect_code 201 "ساخت با همه‌ی فیلدها"
  code=$(echo "$BODY" | jq -r '.code')
  echo "$code" | grep -qE '^[A-Z]{2}-[0-9]+$' && ok "کد خوانا: $code" || bad "کد نامعتبر: $code"
  expect_jq '[.estimate_minutes,.estimate_original]|join(",")' '45,45' "estimate_original = estimate_minutes"
  expect_jq '.assignees|join(",")' 'maral' "فقط owner مسئول است"
  expect_jq '[.incomplete,.late]|join(",")' 'false,false' "ناقص/دیرشده نیست"
  req GET "/tasks/$code";                          expect_jq '.id' "$id" "گرفتن با کد"
  req GET "/tasks/$(echo "$code" | tr A-Z a-z)";   expect_jq '.id' "$id" "کد بدون حساسیت به حروف"
  req GET /tasks/ZZ-99999999;                      expect_code 404 "کد ناموجود"
  req PATCH "/tasks/$code" '{"owner":"chakra"}';   expect_jq '.code' "$code" "کد با عوض‌شدن مسئول عوض نمی‌شود"
  expect_jq '.assignees|join(",")' 'maral,chakra' "مسئول جدید اضافه شد، قبلی می‌ماند"
  req PATCH "/tasks/$id" '{"assignees":["you"]}';  expect_jq '.assignees|join(",")' 'you,chakra' "ویرایش مسئول‌ها (owner همیشه هست)"
  req GET "/tasks?assignee=you"
  [ "$(echo "$BODY" | jq "[.[]|select(.id==$id)]|length")" = 1 ] && ok "در نمای مسئول فقط یک‌بار دیده می‌شود" || bad "تکراری یا گم‌شده در نمای مسئول"
  req GET "/tasks?assignee=maral"
  [ "$(echo "$BODY" | jq "[.[]|select(.id==$id)]|length")" = 0 ] && ok "مسئول حذف‌شده دیگر نمی‌بیند" || bad "هنوز در نمای مسئول حذف‌شده است"
  req GET "/tasks?owner=chakra"
  [ "$(echo "$BODY" | jq "[.[]|select(.id==$id)]|length")" = 1 ] && ok "فیلتر قدیمی owner سازگار است" || bad "فیلتر owner خراب شد"

  req PATCH "/tasks/$id" '{"estimate_minutes":90}'; expect_code 400 "تخمین ثبت‌شده با PATCH عوض نمی‌شود"
  req PATCH "/tasks/$id" '{"due_date":"2031-02-03"}'; expect_jq '.due_date' '2031-02-03' "ویرایش سررسید"
  req PATCH "/tasks/$id" '{"due_date":null}';       expect_jq '.due_date' 'null' "پاک‌کردن سررسید"
  req PATCH "/tasks/$id" "{\"done_criteria\":\"$(head -c 2001 /dev/zero | tr '\0' 'a')\"}"; expect_code 400 "معیار بیش از ۲۰۰۰ نویسه"
  req PATCH "/tasks/$id" "{\"done_criteria\":\"$(head -c 2000 /dev/zero | tr '\0' 'a')\"}"; expect_code 200 "معیار دقیقاً ۲۰۰۰ نویسه"

  # کدها یکتا و پشت‌سرهم، حتی وقتی چند درخواست همزمان تسک می‌سازند
  local before; req GET /tasks; before=$(echo "$BODY" | jq length)
  seq 1 12 | xargs -P 6 -I{} curl -s -u "$MLT_AUTH" -H 'Content-Type: application/json' -X POST -d '{"title":"همزمان {}","estimate_minutes":5,"owner":"auto"}' "$MLT_BASE/tasks" > /tmp/mlt_par.$$
  local ids; ids=$(jq -r '.id' /tmp/mlt_par.$$); for i in $ids; do CREATED+=("$i"); done
  [ "$(jq -r '.code' /tmp/mlt_par.$$ | sort -u | wc -l)" = 12 ] && ok "۱۲ ساخت همزمان: ۱۲ کد یکتا" || bad "کد تکراری در ساخت همزمان"
  jq -r '.code' /tmp/mlt_par.$$ | sed 's/.*-//' | sort -n | awk 'NR>1 && $1!=p+1{gap=1} {p=$1} END{exit gap}' && ok "شماره‌ها پشت‌سرهم‌اند (بدون سوراخ)" || bad "سوراخ در شماره‌ی کدها"
  rm -f /tmp/mlt_par.$$

  # تسک ناقص: با import ساخته می‌شود (تخمین ندارد) و با PATCH پر می‌شود
  local key="smokeinc_$$"
  req POST /import "{\"format\":\"meloraw-tasks-export\",\"version\":1,\"tasks\":[{\"id\":1,\"legacy_id\":\"$key\",\"title\":\"ناقص $key\",\"owner\":\"you\"}]}"
  expect_jq '.tasks.created' '1' "تسک بدون تخمین وارد شد"
  req GET "/tasks?incomplete=true"
  id=$(echo "$BODY" | jq -r --arg k "ناقص $key" '.[]|select(.title==$k)|.id'); CREATED+=("$id")
  [ -n "$id" ] && ok "در فیلتر ناقص دیده می‌شود" || bad "در فیلتر ناقص نیست"
  expect_jq 'all(.[]; .estimate_minutes == null)' 'true' "فیلتر ناقص فقط تسک‌های بدون تخمین را برمی‌گرداند"
  req GET "/tasks/$id";                            expect_jq '[.incomplete,.code!=null]|join(",")' 'true,true' "ناقص است ولی کد دارد"
  req PATCH "/tasks/$id" '{"estimate_minutes":20}'; expect_jq '[.estimate_minutes,.estimate_original,.incomplete]|join(",")' '20,20,false' "پرکردن تخمین با PATCH"

  if [ -n "$identity" ]; then
    req GET "/tasks?mine=true"
    expect_jq "all(.[]; .assignees | index(\"$identity\") != null)" 'true' "mine فقط تسک‌های هویت $identity را می‌دهد"
    new_task '{"title":"mine-test","owner":"'$identity'"}'
    expect_jq ".code | startswith(\"$(echo "$BODY" | jq -r .code | cut -c1-2)\")" 'true' "پیشوند کد از سازنده"
  else
    echo "  (حساب این رمز در تنظیمات به هیچ عضوی وصل نیست؛ تست mine رد شد)"
    req GET "/tasks?mine=true";                    expect_code 409 "mine بدون هویت: پیام ساده"
    expect_persian "پیام بدون هویت فارسی است"
  fi
}

# زمان تقریبی ثابت: فقط «تمدید» با دلیل؛ روتین‌ها: قالب و توقف
run_estimate() {
  echo "== تمدید زمان تقریبی و قالب روتین"
  local id key today
  new_task '{"title":"تمدید","estimate_minutes":50}'; id=$NEW_ID
  req POST "/tasks/$id/extend" '{"estimate_minutes":80,"reason":"x"}' -;  expect_code 401 "تمدید بدون توکن"
  [ -n "${MLT_AUTH_NOCAP:-}" ] && { req POST "/tasks/$id/extend" '{"estimate_minutes":80,"reason":"دلیل طولانی کافی"}' "$MLT_AUTH_NOCAP"; expect_code 403 "تمدید بدون نقش ادمین"; }
  req POST "/tasks/$id/extend" '{"estimate_minutes":80}';                  expect_code 400 "تمدید بدون دلیل"
  req POST "/tasks/$id/extend" '{"estimate_minutes":80,"reason":"کوتاه"}'; expect_code 400 "دلیل کمتر از ۱۰ نویسه"
  expect_persian "پیام دلیل فارسی است"
  req POST "/tasks/$id/extend" '{"estimate_minutes":80,"reason":"          "}'; expect_code 400 "دلیل فقط فاصله"
  req POST "/tasks/$id/extend" '{"estimate_minutes":50,"reason":"دلیل کافی و طولانی"}'; expect_code 400 "تمدید به همان مقدار"
  req POST "/tasks/$id/extend" '{"estimate_minutes":40,"reason":"دلیل کافی و طولانی"}'; expect_code 400 "تمدید به مقدار کمتر (کاهش ممنوع)"
  req POST "/tasks/$id/extend" '{"estimate_minutes":80,"reason":"دقیقاً ده نویس"}'; expect_code 200 "تمدید با دلیل"
  expect_jq '[.estimate_minutes,.estimate_original]|join(",")' '80,50' "estimate_original ثابت ماند"
  req POST "/tasks/$id/extend" '{"estimate_minutes":120,"reason":"تمدید دوم با دلیل کافی"}'; expect_code 200 "تمدید دوم"
  req GET "/tasks/$id"
  expect_jq '[.estimate_minutes,.estimate_original,(.extensions|length)]|join(",")' '120,50,2' "دو تمدید در جدول جدا ثبت شد"
  expect_jq '.extensions|map("\(.old_minutes)>\(.new_minutes)")|join(",")' '50>80,80>120' "ترتیب و مقدارها"
  expect_jq '.extensions[0].reason' 'دقیقاً ده نویس' "دلیل ذخیره شد"
  req GET /tasks;  echo "$BODY" | jq -e '[.[]|select(.id=='$id')|has("extensions")]|all(.==false)' >/dev/null && ok "فهرست تسک‌ها دلیل‌ها را نمی‌آورد" || bad "فهرست دلیل‌ها را آورد"
  req PATCH "/tasks/$id" '{"estimate_minutes":10}'; expect_code 400 "PATCH تخمین را عوض نمی‌کند"
  # دو تمدید همزمان از همان مقدار: فقط یکی برنده است
  seq 1 2 | xargs -P 2 -I{} curl -s -o /dev/null -w '%{http_code}\n' -u "$MLT_AUTH" -H 'Content-Type: application/json' -X POST -d '{"estimate_minutes":200,"reason":"تمدید همزمان با دلیل"}' "$MLT_BASE/tasks/$id/extend" > /tmp/mlt_par.$$
  [ "$(grep -c '^200$' /tmp/mlt_par.$$)" = 1 ] && ok "تمدید همزمان: فقط یک برنده" || bad "تمدید همزمان: $(tr '\n' ' ' < /tmp/mlt_par.$$)"; rm -f /tmp/mlt_par.$$
  req GET "/tasks/$id"; expect_jq '.estimate_minutes' '200' "مقدار نهایی ۲۰۰"
  # تسک بدون تخمین تمدید نمی‌شود
  key="smkext_$$"
  req POST /import "{\"format\":\"meloraw-tasks-export\",\"version\":1,\"tasks\":[{\"id\":1,\"legacy_id\":\"$key\",\"title\":\"ناقص $key\",\"owner\":\"you\"}]}"
  req GET "/tasks?incomplete=true"; local inc; inc=$(echo "$BODY" | jq -r --arg k "ناقص $key" '.[]|select(.title==$k)|.id'); CREATED+=("$inc")
  req POST "/tasks/$inc/extend" '{"estimate_minutes":60,"reason":"دلیل کافی و طولانی"}'; expect_code 400 "تمدید تسک بدون تخمین"
  req POST /tasks/999999999/extend '{"estimate_minutes":60,"reason":"دلیل کافی و طولانی"}'; expect_code 404 "تمدید تسک ناموجود"

  # روتین موقت (با import ساخته و در پایان پاک می‌شود)
  today=$(date -u +%F); key="smkr$$"
  req POST /import "{\"format\":\"meloraw-tasks-export\",\"version\":1,\"tasks\":[{\"id\":1,\"legacy_id\":\"routine_${key}_$today\",\"title\":\"روتین تست\",\"owner\":\"you\",\"kind\":\"routine\",\"recurrence\":\"daily\",\"period_key\":\"$today\",\"meta\":{\"routine_id\":\"$key\"}}]}"
  expect_jq '.tasks.created' '1' "روتین تست ساخته شد"
  req GET "/tasks?kind=routine"; local rid; rid=$(echo "$BODY" | jq -r --arg k "routine_${key}_$today" '.[]|select(.legacy_id==$k)|.id'); CREATED+=("$rid")
  req GET "/routines?period=daily"; expect_jq "[.[]|select(.key==\"$key\")|.incomplete]|join(\",\")" 'true' "روتین بدون تخمین ناقص است"
  req PATCH "/routines/$key" '{}';                                          expect_code 400 "PATCH خالی روتین"
  req PATCH "/routines/$key" '{"estimate_minutes":3}';                      expect_code 400 "تخمین روتین کمتر از ۵"
  req PATCH "/routines/$key" '{"recurrence_until":"nope"}';                 expect_code 400 "پایان روتین نامعتبر"
  req PATCH "/routines/$key" '{"stopped":true,"recurrence_until":"2031-01-01"}'; expect_code 400 "stopped و recurrence_until با هم"
  req PATCH "/routines/$key" '{"estimate_minutes":15,"done_criteria":"معیار روتین"}'
  expect_jq '[.estimate_minutes,.done_criteria,.incomplete,.stopped]|join(",")' '15,معیار روتین,false,false' "قالب روتین ثبت شد"
  req PATCH "/routines/$key" '{"status":"in_progress"}';                    expect_jq '[.status,.estimate_minutes]|join(",")' 'in_progress,15' "تغییر وضعیت قالب را نگه می‌دارد"
  req PATCH "/routines/$key" '{"stopped":true}';                            expect_jq '[.stopped,.recurrence_until]|join(",")' "true,$(date -u -d yesterday +%F)" "توقف روتین (پایان = دیروز)"
  req PATCH "/routines/$key" '{"status":"done"}';                           expect_code 409 "روتین متوقف وضعیت نمی‌گیرد"
  req PATCH "/routines/$key" '{"stopped":false}';                           expect_jq '[.stopped,(.recurrence_until // "none")]|join(",")' 'false,none' "ادامه‌ی روتین"
  req PATCH "/routines/$key" '{"recurrence_until":"2031-01-01"}';           expect_jq '[.stopped,.recurrence_until]|join(",")' 'false,2031-01-01' "پایان آینده: هنوز فعال"
  req PATCH "/routines/$key" '{"status":"done"}';                           expect_jq '.status' 'done' "وضعیت روتین فعال"
  [ -n "${MLT_AUTH_NOCAP:-}" ] && { req PATCH "/routines/$key" '{"stopped":true}' "$MLT_AUTH_NOCAP"; expect_code 403 "توقف روتین بدون نقش ادمین"; }
}

# زمان‌سنج: یک تایمر فعال برای هر کاربر، فقط صاحبش، پاز با دلیل، دو معیار زمان
#   MLT_AUTH2=user:pass   (اختیاری) کاربر دوم برای تست «فقط صاحب تایمر»
run_timer() {
  echo "== زمان‌سنج"
  local a b ca cb i
  new_task '{"title":"تایمر الف"}'; a=$NEW_ID; ca=$(echo "$BODY" | jq -r .code)
  new_task '{"title":"تایمر ب"}';  b=$NEW_ID; cb=$(echo "$BODY" | jq -r .code)
  req GET /timer "" -;                              expect_code 401 "timer بدون توکن"
  req POST "/tasks/$a/timer/start" "" -;            expect_code 401 "شروع بدون توکن"
  [ -n "${MLT_AUTH_NOCAP:-}" ] && { req POST "/tasks/$a/timer/start" "" "$MLT_AUTH_NOCAP"; expect_code 403 "شروع بدون نقش ادمین"; }
  req GET /timer;                                   expect_jq '.active' 'false' "اول تایمر فعالی نیست"

  req POST "/tasks/$a/timer/start";                 expect_code 200 "شروع تایمر"
  expect_jq '[.active,.state,.task.code,.attention]|map(tostring)|join(",")' "true,running,$ca,null" "روشن است و روی $ca"
  req GET "/tasks/$a"; expect_jq '.status' 'in_progress' "شروع تایمر تسک را «در حال انجام» می‌کند"
  req POST "/tasks/$b/timer/start";                 expect_code 409 "تایمر دوم همزمان ممنوع"
  echo "$BODY" | jq -r .message | grep -q "$ca" && ok "پیام می‌گوید کدام تایمر باز است" || bad "پیام کد را نمی‌گوید"
  req POST "/tasks/$a/timer/pause" '{}';            expect_code 400 "پاز بدون دلیل"
  expect_persian "پیام پاز بدون دلیل فارسی است"
  req POST "/tasks/$a/timer/pause" '{"reason":"lazy"}'; expect_code 400 "پاز با دلیل ناشناخته"
  req POST "/tasks/$a/timer/resume";                expect_code 409 "ادامه وقتی روشن است"
  req POST "/tasks/$a/timer/pause" '{"reason":"food"}'; expect_code 200 "پاز با دلیل"
  expect_jq '.state' 'paused' "پازشده"
  req POST "/tasks/$a/timer/pause" '{"reason":"rest"}'; expect_code 409 "پاز دوباره"
  req POST "/tasks/$b/timer/resume";                expect_code 409 "ادامه روی تسک دیگر"
  req POST "/tasks/$b/timer/start";                 expect_code 409 "پازشده هم جای تایمر فعال را می‌گیرد"
  req POST "/tasks/$a/timer/resume";                expect_code 200 "ادامه"
  req POST "/tasks/$a/timer/finish";                expect_code 200 "پایان"
  expect_jq '[.finished.final,.summary.entries,.summary.pause_count]|map(tostring)|join(",")' 'true,2,1' "دو بازه و یک پاز"
  req GET /timer;                                   expect_jq '.active' 'false' "بعد از پایان فعال نیست"
  req POST "/tasks/$a/timer/finish";                expect_code 409 "پایان دوباره"
  req GET "/tasks/$a/time"
  expect_jq '.total.by_reason.food.count' '1' "پاز غذا شمرده شد"
  expect_jq '(.entries|length)' '2' "دو ردیف زمان"

  # زمان واقعی: ۲ ثانیه کار، ۲ ثانیه پاز، ۱ ثانیه کار
  req POST "/tasks/$b/timer/start" >/dev/null; sleep 2; req POST "/tasks/$b/timer/pause" '{"reason":"interrupted"}' >/dev/null; sleep 2
  req POST "/tasks/$b/timer/resume" >/dev/null; sleep 1; req POST "/tasks/$b/timer/finish"
  local n p t; n=$(echo "$BODY" | jq .summary.net_seconds); p=$(echo "$BODY" | jq .summary.pause_seconds); t=$(echo "$BODY" | jq .summary.total_seconds)
  [ "$n" -ge 2 ] && [ "$n" -le 5 ] && ok "زمان خالص $n ثانیه (حدود ۳)" || bad "زمان خالص $n"
  [ "$p" -ge 1 ] && [ "$p" -le 4 ] && ok "زمان پاز $p ثانیه (حدود ۲)" || bad "زمان پاز $p"
  [ "$t" -ge "$n" ] && [ "$t" -le $((n + p + 1)) ] && [ "$t" -ge $((n + p - 1)) ] && ok "زمان کل $t = خالص + پاز" || bad "زمان کل $t (خالص $n، پاز $p)"
  req GET "/tasks/$b/time"; expect_jq '.total.by_reason.interrupted.count' '1' "پاز «قطع شدن» جدا شمرده می‌شود"

  # ساخت همزمان: فقط یک شروع موفق
  seq 1 6 | xargs -P 6 -I{} curl -s -o /dev/null -w '%{http_code}\n' -u "$MLT_AUTH" -X POST "$MLT_BASE/tasks/$a/timer/start" > /tmp/mlt_par.$$
  # تسک الف الان in_progress است؛ done نیست، پس شروع مجاز است
  [ "$(grep -c '^200$' /tmp/mlt_par.$$)" = 1 ] && ok "۶ شروع همزمان: فقط یکی موفق" || bad "شروع همزمان: $(tr '\n' ' ' < /tmp/mlt_par.$$)"; rm -f /tmp/mlt_par.$$

  # اصلاح زمان پایان («کی تموم کردی؟»)
  req GET /timer; local st; st=$(echo "$BODY" | jq -r .entry.started_at)
  req POST "/tasks/$a/timer/finish" '{"ended_at":"not a date"}';           expect_code 400 "زمان پایان نامعتبر"
  req POST "/tasks/$a/timer/finish" '{"ended_at":"2999-01-01 00:00"}';     expect_code 400 "زمان پایان در آینده"
  req POST "/tasks/$a/timer/finish" '{"ended_at":"2000-01-01 00:00"}';     expect_code 400 "زمان پایان قبل از شروع"
  req POST "/tasks/$a/timer/finish" "{\"ended_at\":\"$st\"}";             expect_code 200 "پایان با زمان اصلاح‌شده"
  expect_jq '.finished.ended_at' "$st" "زمان پایان همان است که گفتیم"

  # فقط صاحب تایمر
  if [ -n "${MLT_AUTH2:-}" ]; then
    req POST "/tasks/$a/timer/start" >/dev/null
    req POST "/tasks/$a/timer/pause" '{"reason":"rest"}' "$MLT_AUTH2";     expect_code 409 "کاربر دیگر نمی‌تواند تایمر من را پاز کند"
    req POST "/tasks/$a/timer/finish" "" "$MLT_AUTH2";                     expect_code 409 "کاربر دیگر نمی‌تواند تایمر من را تمام کند"
    req GET /timer "" "$MLT_AUTH2";                                        expect_jq '.active' 'false' "تایمر من در GET /timer او دیده نمی‌شود"
    req POST "/tasks/$a/timer/start" "" "$MLT_AUTH2";                      expect_code 200 "او تایمر خودش را روی همان تسک دارد"
    req POST "/tasks/$a/timer/finish" "" "$MLT_AUTH2" >/dev/null
    req POST "/tasks/$a/timer/finish" >/dev/null
    req GET "/tasks/$a/time"; expect_jq '(.by_user|keys|length) >= 2' 'true' "زمان هر کاربر جدا حساب می‌شود"
  else
    echo "  (MLT_AUTH2 تنظیم نشده؛ تست کاربر دوم رد شد)"
    req POST "/tasks/$a/timer/start" >/dev/null; req POST "/tasks/$a/timer/finish" >/dev/null
  fi
  req POST /tasks/999999999/timer/start;            expect_code 404 "شروع روی تسک ناموجود"
}

# امتیاز هنگام done: بر اساس زمان خالص، تخمین اولیه و سررسید (مرزها در tests/db-scenarios.php)
run_score() {
  echo "== امتیاز"
  local me a b c d week
  req GET /me; me=$(echo "$BODY" | jq -r .user)
  req GET /scores "" -;                             expect_code 401 "scores بدون توکن"
  [ -n "${MLT_AUTH_NOCAP:-}" ] && { req GET /scores "" "$MLT_AUTH_NOCAP"; expect_code 403 "scores بدون نقش ادمین"; req POST /scores/recalculate "" "$MLT_AUTH_NOCAP"; expect_code 403 "بازمحاسبه بدون نقش ادمین"; }
  req POST /scores/recalculate "" -;                expect_code 401 "بازمحاسبه بدون توکن"
  req GET "/scores?week=bad";                       expect_code 400 "هفته‌ی نامعتبر"
  req GET "/scores?user=nobody-here";               expect_code 404 "کاربر ناموجود"
  req GET /scores;                                  week=$(echo "$BODY" | jq -r .week)
  echo "$week" | grep -qE '^[0-9]{4}-W[0-9]{2}$' && ok "هفته‌ی جاری $week" || bad "هفته: $week"
  local base; base=$(echo "$BODY" | jq "[.rows[]|.points]|add // 0")

  # الف) با تایمر، بالای تخمین نمی‌رود: کامل × وزن (اولویت بالا = ۳)
  new_task '{"title":"امتیاز الف","estimate_minutes":5,"priority":"high"}'; a=$NEW_ID
  req POST "/tasks/$a/timer/start" >/dev/null; sleep 1
  req PATCH "/tasks/$a" '{"status":"done"}';        expect_code 200 "done با تایمر روشن"
  req GET /timer;                                   expect_jq '.active' 'false' "done تایمر خودم را تمام کرد"
  req GET "/scores?week=$week"
  expect_jq "[.rows[]|select(.task_id==$a)|[.points,.tier,.late,.has_timer]|map(tostring)|join(\",\")]|join(\";\")" '30,full,false,true' "تسک الف: ۱۰ × ۳ = ۳۰، کامل"
  expect_jq "[.rows[]|select(.task_id==$a)|.estimate_original]|join(\",\")" '5' "مبنا: تخمین اولیه"

  # ب) بدون تایمر: done می‌شود ولی امتیاز سرعت ندارد
  new_task '{"title":"امتیاز ب","estimate_minutes":30}'; b=$NEW_ID
  req PATCH "/tasks/$b" '{"status":"done"}';        expect_jq '.status' 'done' "بدون تایمر هم done می‌شود"
  req GET "/scores?week=$week"
  expect_jq "[.rows[]|select(.task_id==$b)|[.points,.tier,.has_timer]|map(tostring)|join(\",\")]|join(\";\")" '0,no_timer,false' "برچسب «بدون زمان‌سنج»، امتیاز ۰"

  # ج) سررسید گذشته: خیلی کم (وزن متوسط ۲)؛ با دلیل دیرکرد کمی بیشتر؛ بازکردن امتیاز را پاک می‌کند
  new_task '{"title":"امتیاز ج","estimate_minutes":30,"due_date":"'$(date -u -d '3 days ago' +%F)'"}'; c=$NEW_ID
  expect_jq '.late' 'true' "از سررسید گذشته = دیر شده"
  req GET "/tasks/$c"; expect_jq '.late' 'true' "برچسب دیر شده در GET"
  req POST "/tasks/$c/timer/start" >/dev/null; sleep 1; req POST "/tasks/$c/timer/finish" >/dev/null
  req PATCH "/tasks/$c" '{"status":"done"}'
  expect_jq '.late' 'false' "done شد: دیگر «دیر شده» نیست"
  req GET "/scores?week=$week"
  expect_jq "[.rows[]|select(.task_id==$c)|[.points,.tier,.late,.late_reason]|map(tostring)|join(\",\")]|join(\";\")" '2,low,true,null' "دیرکرد بدون دلیل: ۱ × ۲"
  req PATCH "/tasks/$c" '{"status":"open"}'
  req GET "/scores?week=$week"; expect_jq "[.rows[]|select(.task_id==$c)]|length" '0' "بازکردن تسک امتیازش را برمی‌دارد"
  req PATCH "/tasks/$c" '{"status":"done","late_reason":"منتظر فایل بودم"}'
  req GET "/scores?week=$week"
  expect_jq "[.rows[]|select(.task_id==$c)|[.points,.tier,.late_reason]|map(tostring)|join(\",\")]|join(\";\")" '4,low_reason,منتظر فایل بودم' "با دلیل دیرکرد: ۲ × ۲"

  # د) تمدید مبنای امتیاز را عوض نمی‌کند
  new_task '{"title":"امتیاز د","estimate_minutes":5}'; d=$NEW_ID
  req POST "/tasks/$d/extend" '{"estimate_minutes":500,"reason":"تمدید برای آزمون امتیاز"}' >/dev/null
  req POST "/tasks/$d/timer/start" >/dev/null; req POST "/tasks/$d/timer/finish" >/dev/null
  req PATCH "/tasks/$d" '{"status":"done"}'
  req GET "/scores?week=$week"; expect_jq "[.rows[]|select(.task_id==$d)|.estimate_original]|join(\",\")" '5' "بعد از تمدید هم مبنا ۵ دقیقه‌ی اولیه است"

  # جمع هفتگی و بازمحاسبه
  req GET "/scores?week=$week&user=$me"
  expect_jq "[.totals[]|select(.user==\"$me\")|.points]|join(\",\")" "$((base + 30 + 0 + 4 + 20))" "جمع هفتگی من (۳۰ + ۰ + ۴ + ۲۰)"
  expect_jq "[.rows[]|select(.task_id==$b)|.has_timer]|join(\",\")" 'false' "تسک ب در جمع با برچسب بدون زمان‌سنج"
  req POST /scores/recalculate;                     expect_code 200 "بازمحاسبه"
  expect_jq '.changed' '0' "امتیازها از قبل درست‌اند؛ چیزی عوض نشد"
  req GET "/scores?week=$week&user=all";            expect_code 200 "نمای همه (ادمین)"
}

# هدرهای امنیتی/کش روی همه‌ی پاسخ‌های REST افزونه (موفق، خطا، بدون توکن)
expect_hdr() { echo "$HDRS" | grep -qiE "^$1: .*$2" && ok "$3" || bad "$3 (هدر $1 نیست)"; }
run_headers() {
  echo "== هدرهای امنیتی و کش"
  local label code
  for label in "بدون توکن|GET|/tasks|-|401" "با توکن|GET|/tasks|$MLT_AUTH|200" "مسیر ناموجود|GET|/nope|$MLT_AUTH|404" "ورودی نامعتبر|POST|/tasks|$MLT_AUTH|400"; do
    IFS='|' read -r name method path auth code <<<"$label"
    req "$method" "$path" "$([ "$method" = POST ] && echo '{"title":""}')" "$auth"
    expect_code "$code" "$name"
    expect_hdr 'X-Robots-Tag' 'noindex, nofollow' "  X-Robots-Tag"
    expect_hdr 'Cache-Control' 'no-store' "  Cache-Control: no-store"
    expect_hdr 'X-LiteSpeed-Cache-Control' 'no-cache' "  X-LiteSpeed-Cache-Control"
  done
}

# export / import: فقط ادمین؛ تکراری‌ها (legacy_id، یا عنوان برای ردیف بدون legacy_id) وارد نمی‌شوند
run_export() {
  echo "== export و import"
  req GET /export "" -;                            expect_code 401 "export بدون توکن"
  req POST /import '{}' -;                         expect_code 401 "import بدون توکن"
  if [ -n "${MLT_AUTH_NOCAP:-}" ]; then
    req GET /export "" "$MLT_AUTH_NOCAP";          expect_code 403 "export بدون نقش ادمین"
    req POST /import '{}' "$MLT_AUTH_NOCAP";       expect_code 403 "import بدون نقش ادمین"
  fi
  req GET /export;                                 expect_code 200 "export با ادمین"
  expect_jq '.format' 'meloraw-tasks-export' "نام قالب"
  expect_jq '(.tasks|type) + (.comments|type)' 'arrayarray' "tasks و comments آرایه‌اند"
  expect_hdr 'X-Robots-Tag' 'noindex' "  X-Robots-Tag روی export"
  echo "$BODY" | jq -e '[.. | objects | keys[] | select(test("pass|token|secret|mail|hash";"i"))] | length == 0' >/dev/null && ok "هیچ فیلد حساسی در خروجی نیست" || bad "فیلد حساس در export"
  local before; before=$(echo "$BODY" | jq '.tasks|length')

  req POST /import 'not json';                     expect_code 400 "import با متن غیر JSON"
  expect_persian "پیام JSON نامعتبر فارسی است"
  req POST /import '{"format":"meloraw-tasks-export","version":99,"tasks":[]}'; expect_code 400 "import با version نامعتبر"
  req POST /import '{"format":"meloraw-tasks-export","version":1,"tasks":[{"title":"","owner":"you"},{"title":"x","owner":"nobody"}]}'
  expect_code 200 "ردیف نامعتبر گزارش می‌شود، خطا نمی‌دهد"
  expect_jq '.tasks.invalid' '2' "دو ردیف نامعتبر"

  local key="smoke_$$_$(date +%s)"
  local file='{"format":"meloraw-tasks-export","version":1,"tasks":[{"id":1,"legacy_id":"'$key'","title":"smoke import '$key'","owner":"you"},{"id":2,"title":"smoke untitled-key '$key'","owner":"you"}],"comments":[{"id":1,"task_id":1,"body":"smoke comment"}]}'
  req POST "/import?dry_run=1" "$file";            expect_code 200 "import آزمایشی (dry_run)"
  expect_jq '.dry' 'true' "dry_run: چیزی نوشته نمی‌شود"
  req GET /export; expect_jq '.tasks|length' "$before" "بعد از dry_run تعداد تسک‌ها همان است"
  req POST /import "$file";                        expect_code 200 "import واقعی"
  expect_jq '[.tasks.created,.comments.created]|join(",")' '2,1' "دو تسک و یک کامنت ساخته شد"
  req POST /import "$file";                        expect_code 200 "import دوباره"
  expect_jq '[.tasks.created,.tasks.duplicate,.comments.created]|join(",")' '0,2,0' "هر دو تکراری‌اند (legacy_id و عنوان)، کامنتی اضافه نشد"
  req GET /export
  for id in $(echo "$BODY" | jq -r --arg k "$key" '.tasks[] | select(.title | contains($k)) | .id'); do CREATED+=("$id"); done
  expect_jq "[.tasks[] | select(.title | contains(\"$key\"))] | length" '2' "دو تسک تستی در export هست"
}

# رمزهای Claude-… و Hermes-… فقط روی /meloraw-tasks/v1 کار می‌کنند؛ namespace افزونه در فهرست عمومی نیست.
#   MLT_AUTH_IS_AGENT=1   یعنی MLT_AUTH نامش با Claude- یا Hermes- شروع می‌شود (بررسی ۴۰۳ روی مسیرهای دیگر)
#   MLT_AUTH_OTHER=user:pass   یک Application Password با نام دیگر (باید روی مسیرهای دیگر هم کار کند)
run_scope() {
  echo "== محدوده‌ی رمز ایجنت‌ها و پنهان‌بودن namespace"
  local root=${MLT_BASE%/meloraw-tasks/v1} idx
  idx=$(curl -s "$root/")
  echo "$idx" | jq -e '(.namespaces | index("meloraw-tasks/v1")) == null' >/dev/null && ok "namespace در فهرست عمومی نیست" || bad "namespace در /wp-json/ دیده می‌شود"
  [ "$(echo "$idx" | grep -c meloraw)" = 0 ] && ok "هیچ اشاره‌ای به افزونه در فهرست عمومی نیست" || bad "در فهرست عمومی اشاره به افزونه هست"
  req GET /tasks "" -;                             expect_code 401 "مسیر namespace هنوز ۴۰۱ می‌دهد"
  if [ "${MLT_AUTH_IS_AGENT:-}" = 1 ]; then
    for path in /wp/v2/users/me /wp/v2/plugins /wp/v2/users/me/application-passwords /; do
      CODE=$(curl -s -o /tmp/mlt_body.$$ -w '%{http_code}' -u "$MLT_AUTH" "$root$path"); BODY=$(cat /tmp/mlt_body.$$)
      expect_code 403 "رمز ایجنت روی $path"
    done
    expect_jq '.code' 'mlt_agent_scope' "کد خطای محدوده"
    req GET /tasks;                                expect_code 200 "رمز ایجنت روی مسیر افزونه کار می‌کند"
  fi
  if [ -n "${MLT_AUTH_OTHER:-}" ]; then
    CODE=$(curl -s -o /dev/null -w '%{http_code}' -u "$MLT_AUTH_OTHER" "$root/wp/v2/users/me"); expect_code 200 "رمز با نام دیگر روی /wp/v2 دست‌نخورده است"
  fi
}

# سقف کلی: ۱۲۰ درخواست در دقیقه برای هر کاربر. کاربر آزمون را تا یک دقیقه مسدود می‌کند، پس جزو all نیست.
run_ratelimit() {
  echo "== rate limit کلی (تا یک دقیقه طول می‌کشد)"
  local i n429=0 ok200=0 wait=
  NO_RETRY=1
  for i in $(seq 1 140); do
    req GET /tasks/999999999
    if [ "$CODE" = 429 ]; then n429=$((n429+1)); wait=$(echo "$HDRS" | awk -F': ' 'tolower($1)=="retry-after"{print $2}'); break; else ok200=$((ok200+1)); fi
  done
  [ $n429 -gt 0 ] && ok "بعد از $ok200 درخواست، ۴۲۹ آمد" || bad "تا ۱۴۰ درخواست ۴۲۹ نیامد"
  expect_jq '.code' 'mlt_rate_limited' "کد خطا"
  expect_persian "پیام ۴۲۹ فارسی است"
  [ -n "$wait" ] && [ "$wait" -ge 1 ] && [ "$wait" -le 60 ] && ok "Retry-After = $wait" || bad "Retry-After نامعتبر: '$wait'"
  expect_hdr 'X-Robots-Tag' 'noindex' "  X-Robots-Tag روی ۴۲۹"
  expect_hdr 'Cache-Control' 'no-store' "  Cache-Control روی ۴۲۹"
  req GET /tasks "" -; expect_code 401 "بدون توکن ۴۰۱ می‌ماند (نه ۴۲۹ و نه شمارش)"
  sleep "$((wait + 1))"
  req GET /tasks/999999999;                        expect_code 404 "بعد از Retry-After دوباره پاسخ عادی می‌دهد"
  NO_RETRY=
}

case "$SECTION" in
  auth) run_auth ;; tasks) run_tasks ;; comments) run_comments ;;
  flag) run_flag ;; status) run_status ;; routines) run_routines ;; notify) run_notify ;; fields) run_fields ;; estimate) run_estimate ;; timer) run_timer ;; score) run_score ;; headers) run_headers ;; scope) run_scope ;; export) run_export ;; ratelimit) run_ratelimit ;; hidden) run_hidden ;;
  all) run_auth; run_tasks; run_comments; run_flag; run_status; run_routines; run_notify; run_fields; run_estimate; run_timer; run_score; run_headers; run_scope; run_export; run_hidden ;;
  *) echo "بخش نامعتبر: $SECTION"; exit 2 ;;
esac

[ $FAIL = 0 ] && echo "همه‌ی تست‌ها گذشت." || echo "تست ناموفق داشت."
exit $FAIL
