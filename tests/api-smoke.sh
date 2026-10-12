#!/usr/bin/env bash
# تست curl برای API افزونه. فقط روی سایت تست یا کپی اجرا شود؛ تسک‌های تست را در پایان حذف می‌کند.
#
#   MLT_BASE=https://example.com/wp-json/meloraw-tasks/v1 \
#   MLT_AUTH='user:xxxx xxxx xxxx xxxx xxxx xxxx' \
#   [MLT_AUTH_NOCAP='editor:xxxx ...'] \
#   tests/api-smoke.sh [auth|tasks|comments|flag|status|routines|notify|hidden|all]
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

# req METHOD PATH [JSON] [AUTH|-]  → sets CODE and BODY
req() {
  local method=$1 path=$2 data=${3:-} auth=${4:-$MLT_AUTH}
  local args=(-s -o /tmp/mlt_body.$$ -w '%{http_code}' -X "$method" -H 'Content-Type: application/json')
  [ "$auth" != "-" ] && args+=(-u "$auth")
  [ -n "$data" ] && args+=(--data "$data")
  CODE=$(curl "${args[@]}" "$MLT_BASE$path")
  BODY=$(cat /tmp/mlt_body.$$)
}

expect_code() { [ "$CODE" = "$1" ] && ok "$2 → $CODE" || bad "$2 → $CODE (انتظار $1): $BODY"; }
# پیام خطا باید فارسی باشد و متن انگلیسی وردپرس در آن نباشد
expect_persian() { echo "$BODY" | jq -e '.message | test("[آ-ی]") and (test("Invalid|parameter|No route|Missing|Sorry") | not)' >/dev/null && ok "$1" || bad "$1: $(echo "$BODY" | jq -c '.message')"; }
expect_jq()   { [ "$(echo "$BODY" | jq -r "$1")" = "$2" ] && ok "$3" || bad "$3: $(echo "$BODY" | jq -c "$1")"; }

# new_task JSON → sets NEW_ID (no subshell, so CREATED is kept for cleanup)
new_task() {
  req POST /tasks "$1"
  NEW_ID=$(echo "$BODY" | jq -r '.id // empty')
  [ -n "$NEW_ID" ] && CREATED+=("$NEW_ID")
}

cleanup() {
  for id in "${CREATED[@]}"; do req DELETE "/tasks/$id"; done
  rm -f /tmp/mlt_body.$$
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
  req POST /tasks '{"title":"تست <b>API</b>","description":"خط اول\nخط دوم","owner":"maral","bucket":"boost","priority":"high"}'
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
  expect_jq 'all(.[]; (keys | sort) == ["description","frequency","key","owner","period_key","status","task_id","title"])' 'true' "ساختار هر روتین"
  expect_jq 'all(.[]; .frequency as $f | (.period_key | test(if $f == "daily" then "^[0-9]{4}-[0-9]{2}-[0-9]{2}$" elif $f == "weekly" then "^[0-9]{4}-W[0-9]{2}$" else "^[0-9]{4}-[0-9]{2}$" end)))' 'true' "کلید دوره با تناوب می‌خواند"
  req GET "/routines?period=yearly";               expect_code 400 "تناوب نامعتبر"
  expect_jq '.code' 'mlt_invalid_param' "کد خطای تناوب نامعتبر"
  expect_persian "پیام تناوب نامعتبر فارسی است"
  req PATCH /routines/zz-none '{"status":"archived"}'; expect_code 400 "وضعیت نامعتبر روتین"
  req PATCH /routines/zz-none '{}';                expect_code 400 "روتین بدون وضعیت"
  expect_jq '.code' 'mlt_missing_param' "کد خطای وضعیت الزامی"
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

case "$SECTION" in
  auth) run_auth ;; tasks) run_tasks ;; comments) run_comments ;;
  flag) run_flag ;; status) run_status ;; routines) run_routines ;; notify) run_notify ;; hidden) run_hidden ;;
  all) run_auth; run_tasks; run_comments; run_flag; run_status; run_routines; run_notify; run_hidden ;;
  *) echo "بخش نامعتبر: $SECTION"; exit 2 ;;
esac

[ $FAIL = 0 ] && echo "همه‌ی تست‌ها گذشت." || echo "تست ناموفق داشت."
exit $FAIL
