#!/usr/bin/env bash
# بررسی صفحه‌های پنل با curl (بدون مرورگر). فقط روی سایت تست یا کپی اجرا شود.
#
#   MLT_SITE=https://example.com \
#   MLT_LOGIN1='user:password' MLT_LOGIN2='user2:password'   # دو ادمین (رمز ورود، نه Application Password)
#   MLT_LOGIN_NOCAP='editor:password'                       # اختیاری
#   tests/ui-check.sh [clock|banner|settings|board|routines|today|time|all]
#
# رمزها فقط از متغیر محیطی خوانده می‌شوند. فرم‌ها با همان nonce خودشان ارسال می‌شوند.

set -u
: "${MLT_SITE:?MLT_SITE را تنظیم کنید}"
: "${MLT_LOGIN1:?MLT_LOGIN1 را تنظیم کنید}"
: "${MLT_LOGIN2:?MLT_LOGIN2 را تنظیم کنید}"
SECTION="${1:-all}"
HERE=$(cd "$(dirname "$0")" && pwd)
JAR1=$(mktemp); JAR2=$(mktemp); JAR3=$(mktemp); JAR4=
FAIL=0
ok()  { echo "  ✓ $1"; }
bad() { echo "  ✗ $1"; FAIL=1; }
trap 'rm -f "$JAR1" "$JAR2" "$JAR3" "$JAR4" /tmp/mlt_ui.$$' EXIT

login() { # jar user:pass
  curl -s -c "$1" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null -d "log=${2%%:*}" --data-urlencode "pwd=${2#*:}" -d "wp-submit=Log+In&testcookie=1" "$MLT_SITE/wp-login.php"
}
page() { # jar slug [extra query]  -> HTML on stdout
  curl -s -b "$1" "$MLT_SITE/wp-admin/admin.php?page=$2${3:-}"
}
post_form() { # jar page-slug action-fields...  (finds the form on that page, submits it)  -> HTTP code
  local jar=$1 slug=$2; shift 2
  local body; body=$(page "$jar" "$slug" | python3 "$HERE/ui-form.py" "$@") || { echo "no-form"; return; }
  curl -s -b "$jar" -o /dev/null -w '%{http_code}' -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
}
has() { grep -q "$2" <<<"$1"; }

login "$JAR1" "$MLT_LOGIN1"; login "$JAR2" "$MLT_LOGIN2"
JAR4=$(mktemp); [ -n "${MLT_LOGIN_NOCAP:-}" ] && login "$JAR4" "$MLT_LOGIN_NOCAP"

run_clock() {
  echo "== نوار تاریخ و ساعت UTC"
  local slug html
  for slug in meloraw-tasks meloraw-tasks-routines meloraw-tasks-ideas meloraw-tasks-reports meloraw-tasks-log meloraw-tasks-import meloraw-tasks-settings meloraw-tasks-today meloraw-tasks-time; do
    html=$(page "$JAR1" "$slug")
    if has "$html" 'class="mlt-topbar"'; then
      local d t; d=$(grep -o 'mlt-clock-date" dir="ltr">[^<]*' <<<"$html" | sed 's/.*>//'); t=$(grep -o 'mlt-clock-time" dir="ltr">[^<]*' <<<"$html" | sed 's/.*>//')
      [ "$d" = "$(date -u '+%a %Y-%m-%d')" ] && [[ "$t" =~ ^[0-9]{2}:[0-9]{2}\ UTC$ ]] && ok "$slug: $d $t" || bad "$slug: تاریخ/ساعت نادرست ($d | $t)"
    else
      bad "$slug: نوار نیست"
    fi
    grep -qiE 'tehran|تهران|Asia/' <<<"$html" && bad "$slug: اشاره به تهران یا منطقه‌ی زمانی" || true
    grep -qP '\b1[34]\d{2}/\d{2}/\d{2}\b' <<<"$html" && bad "$slug: تاریخ شمسی" || true
  done
  local now ms; ms=$(grep -o 'data-now="[0-9]*"' <<<"$(page "$JAR1" meloraw-tasks)" | grep -o '[0-9]*'); now=$(( $(date -u +%s) * 1000 ))
  [ $(( now - ms )) -lt 5000 ] && [ $(( ms - now )) -lt 5000 ] && ok "data-now = ساعت سرور (اختلاف زیر ۵ ثانیه)" || bad "data-now با ساعت سرور نمی‌خواند"
}

run_banner() {
  echo "== بنر «چرا زمان ثبت می‌کنیم؟»"
  local h
  for slug in meloraw-tasks meloraw-tasks-routines meloraw-tasks-today; do
    h=$(page "$JAR1" "$slug"); has "$h" 'id="mlt-why"' && ok "$slug: بنر برای کاربر تازه دیده می‌شود" || bad "$slug: بنر نیست"
  done
  for slug in meloraw-tasks-reports meloraw-tasks-log meloraw-tasks-ideas meloraw-tasks-settings meloraw-tasks-time; do
    h=$(page "$JAR1" "$slug"); has "$h" 'id="mlt-why"' && bad "$slug: بنر نباید اینجا باشد" || true
  done
  ok "بنر روی صفحه‌های گزارش/لاگ/ایده/تنظیمات نیست"
  h=$(page "$JAR1" meloraw-tasks); has "$h" 'چرا زمان کارهامون رو ثبت می‌کنیم؟ این زمان‌سنج برای کنترل شما نیست' && ok "متن دقیق بنر" || bad "متن بنر"
  has "$h" 'چرا زمان ثبت می‌کنیم؟</button>' && ok "لینک «چرا زمان ثبت می‌کنیم؟» در سربرگ" || bad "لینک سربرگ نیست"
  local code; code=$(post_form "$JAR1" meloraw-tasks action=mlt_banner do=hide); [ "$code" = 302 ] && ok "بستن (×) → ۳۰۲" || bad "بستن: $code"
  for slug in meloraw-tasks meloraw-tasks-routines meloraw-tasks-today; do
    has "$(page "$JAR1" "$slug")" 'id="mlt-why"' && bad "$slug: بعد از بستن دوباره آمد" || ok "$slug: بعد از بستن نمی‌آید (ذخیره برای کاربر)"
  done
  has "$(page "$JAR1" meloraw-tasks)" 'چرا زمان ثبت می‌کنیم؟</button>' && ok "لینک سربرگ بعد از بستن هم هست" || bad "لینک سربرگ نیست"
  has "$(page "$JAR2" meloraw-tasks)" 'id="mlt-why"' && ok "کاربر دوم هنوز بنر را می‌بیند (ذخیره برای هر کاربر)" || bad "بسته‌شدن برای همه اثر کرد"
  code=$(post_form "$JAR1" meloraw-tasks action=mlt_banner do=show); [ "$code" = 302 ] && ok "باز کردن دوباره با لینک" || bad "باز کردن: $code"
  has "$(page "$JAR1" meloraw-tasks)" 'id="mlt-why"' && ok "بنر دوباره آمد" || bad "بنر برنگشت"
  curl -s -b "$JAR1" -o /dev/null -w '%{http_code}' -d "action=mlt_banner&do=hide&_wpnonce=bad" "$MLT_SITE/wp-admin/admin-post.php" | grep -q 403 && ok "بدون nonce معتبر: ۴۰۳" || bad "nonce بد پذیرفته شد"
  has "$(page "$JAR1" meloraw-tasks)" 'id="mlt-why"' && ok "و چیزی عوض نشد" || bad "با nonce بد بنر بسته شد"
  curl -s -o /dev/null -w '%{http_code}' -d "action=mlt_banner&do=hide" "$MLT_SITE/wp-admin/admin-post.php" | grep -qv 302 && ok "بدون ورود: ریدایرکت نمی‌شود/خطا" || bad "بدون ورود پذیرفته شد"
  if [ -n "${MLT_LOGIN_NOCAP:-}" ]; then
    curl -s -b "$JAR4" -o /dev/null -w '%{http_code}' -d "action=mlt_banner&do=hide&_wpnonce=x" "$MLT_SITE/wp-admin/admin-post.php" | grep -q 403 && ok "کاربر بدون نقش: ۴۰۳" || bad "کاربر بدون نقش پذیرفته شد"
  fi
}

# تنظیمات: اعلان سراسری و ساعت اوج. MLT_ID_MAP="1=you 2=maral" (شناسه‌ی حساب=هویت) تا ذخیره‌ی فرم هویت‌ها را پاک نکند.
settings_post() { # jar extra-args...  -> HTTP code
  local jar=$1; shift
  local extra=() pair
  for pair in ${MLT_ID_MAP:-}; do extra+=("+identity[${pair%%=*}]=${pair#*=}"); done
  local body; body=$(page "$jar" meloraw-tasks-settings | python3 "$HERE/ui-form.py" action=mlt_save_settings "${extra[@]}" "$@") || { echo no-form; return; }
  curl -s -b "$jar" -o /dev/null -w '%{http_code}' -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
}
run_settings() {
  echo "== تنظیمات: اعلان سراسری و ساعت اوج"
  local code h hour yest tom
  yest=$(date -u -d yesterday +%F); tom=$(date -u -d tomorrow +%F); hour=$(date -u +%-H)
  code=$(settings_post "$JAR1" '+announcement_text=اعلان <script>alert(1)</script> تست' '+announcement_active=1'); [ "$code" = 302 ] && ok "ذخیره‌ی اعلان" || bad "ذخیره: $code"
  for jar in "$JAR1" "$JAR2"; do
    h=$(page "$jar" meloraw-tasks-log)
    has "$h" 'class="mlt-announce"' && ok "اعلان برای هر دو کاربر دیده می‌شود" || bad "اعلان نیست"
  done
  grep -q 'alert' <<<"$(grep -o 'class="mlt-announce".*' <<<"$h")" && bad "کد اسکریپت در اعلان ماند" || ok "تگ و محتوای اسکریپت از اعلان حذف شد"
  has "$(page "$JAR1" meloraw-tasks-reports)" 'اعلان تیم:</strong> اعلان' && ok "متن اعلان دیده می‌شود" || bad "متن اعلان"
  settings_post "$JAR1" '+announcement_text=منقضی' '+announcement_active=1' "+announcement_expires=$yest" >/dev/null
  has "$(page "$JAR1" meloraw-tasks-log)" 'class="mlt-announce"' && bad "اعلان منقضی دیده شد" || ok "اعلان منقضی دیده نمی‌شود"
  settings_post "$JAR1" '+announcement_text=فردا' '+announcement_active=1' "+announcement_expires=$tom" >/dev/null
  has "$(page "$JAR1" meloraw-tasks-log)" 'class="mlt-announce"' && ok "اعلان با انقضای فردا دیده می‌شود" || bad "اعلان آینده نیست"
  settings_post "$JAR1" '+announcement_text=غیرفعال' >/dev/null
  has "$(page "$JAR1" meloraw-tasks-log)" 'class="mlt-announce"' && bad "اعلان غیرفعال دیده شد" || ok "اعلان غیرفعال دیده نمی‌شود"
  local long; long=$(head -c 501 /dev/zero | tr '\0' 'a')
  settings_post "$JAR1" '+announcement_text=ماندگار' '+announcement_active=1' >/dev/null
  settings_post "$JAR1" "+announcement_text=$long" '+announcement_active=1' >/dev/null
  has "$(page "$JAR1" meloraw-tasks-log)" 'ماندگار' && ok "متن بیش از ۵۰۰ نویسه رد شد و قبلی ماند" || bad "متن بلند پذیرفته شد"
  # ساعت اوج: فقط برای صاحبش و فقط در همان ساعت
  settings_post "$JAR1" '+announcement_text=' "+peak[you]=$hour" >/dev/null
  has "$(page "$JAR1" meloraw-tasks-log)" 'class="mlt-peak"' && ok "در ساعت اوج، پیام داخل پنل برای محمد" || bad "پیام ساعت اوج نیست (هویت حساب اول 'you' است؟)"
  has "$(page "$JAR2" meloraw-tasks-log)" 'class="mlt-peak"' && bad "پیام ساعت اوج برای دیگری هم آمد" || ok "برای مارال نمی‌آید"
  settings_post "$JAR1" "+peak[you]=$(( (hour + 5) % 24 ))" >/dev/null
  has "$(page "$JAR1" meloraw-tasks-log)" 'class="mlt-peak"' && bad "خارج از ساعت اوج هم آمد" || ok "خارج از ساعت اوج نمی‌آید"
  settings_post "$JAR1" '+peak[you]=' >/dev/null
  curl -s -b "$JAR1" -o /dev/null -w '%{http_code}' -d "action=mlt_save_settings&announcement_text=x" "$MLT_SITE/wp-admin/admin-post.php" | grep -q 403 && ok "ذخیره بدون nonce: ۴۰۳" || bad "ذخیره بدون nonce"
}

# برد: نمای «من» / «همه» سمت سرور، کد و کپی، تحویل به ایجنت، ویرایش، تمدید، تایمر از روی کارت.
#   MLT_API=https://example.com/wp-json/meloraw-tasks/v1  MLT_AUTH='user:app-pass'  MLT_AUTH2='user2:app-pass'
#   (Application Passwordِ همان دو حسابی که MLT_LOGIN1/2 هستند؛ حساب اول به «you» و دوم به «maral» وصل باشد)
#   MLT_LOGIN3_UNMAPPED='user:password'  اختیاری: ادمینی که به هیچ هویتی وصل نیست
api() { local auth=$1 path=$2; shift 2; curl -s -u "$auth" -H 'Content-Type: application/json' "$@" "$MLT_API$path"; }
card()  { python3 "$HERE/ui-card.py" card "$2" <<<"$1"; }
ids_of() { python3 "$HERE/ui-card.py" ids <<<"$1" | sort -n | tr '\n' ' '; }
run_board() {
  echo "== برد: نمای من/همه، کد، تحویل به ایجنت، ویرایش، تمدید، تایمر"
  : "${MLT_API:?MLT_API را تنظیم کنید}" "${MLT_AUTH:?MLT_AUTH}" "${MLT_AUTH2:?MLT_AUTH2}"
  local past; past=$(date -u -d '3 days ago' +%F)
  local t1 t2 t3 c1 c2 c3 j
  j=$(api "$MLT_AUTH" /tasks -X POST -d '{"title":"برد-الف","owner":"you","estimate_minutes":30,"priority":"high"}'); t1=$(jq -r .id <<<"$j"); c1=$(jq -r .code <<<"$j")
  j=$(api "$MLT_AUTH" /tasks -X POST -d '{"title":"برد-ب","owner":"maral","assignees":["you","chakra"],"estimate_minutes":45,"done_criteria":"معیار ب"}'); t2=$(jq -r .id <<<"$j"); c2=$(jq -r .code <<<"$j")
  j=$(api "$MLT_AUTH" /tasks -X POST -d '{"title":"برد-ج","owner":"maral","estimate_minutes":20,"due_date":"'$past'"}'); t3=$(jq -r .id <<<"$j"); c3=$(jq -r .code <<<"$j")
  [ -n "$t1" ] && [ "$t1" != null ] && ok "سه تسک آزمون ساخته شد ($c1 $c2 $c3)" || { bad "ساخت تسک آزمون"; return; }
  local mine1 mine2 all alln h
  mine1=$(page "$JAR1" meloraw-tasks); mine2=$(page "$JAR2" meloraw-tasks)
  all=$(page "$JAR1" meloraw-tasks '&view=all')
  [ "$(ids_of "$mine1")" = "$(api "$MLT_AUTH" '/tasks?mine=true' | jq -r '[.[]|select(.kind=="phase" or .kind=="new")|select(.status!="done" or true)|.id]|sort|join(" ")') " ] && ok "نمای من (محمد) = GET /tasks?mine=true" || bad "نمای من با API نمی‌خواند"
  [ "$(ids_of "$mine2")" = "$(api "$MLT_AUTH2" '/tasks?mine=true' | jq -r '[.[]|select(.kind=="phase" or .kind=="new")|.id]|sort|join(" ")') " ] && ok "نمای من (مارال) = GET /tasks?mine=true" || bad "نمای من (مارال) با API نمی‌خواند"
  grep -q "id=\"mlt-t-$t1\"" <<<"$mine1" && ! grep -q "id=\"mlt-t-$t3\"" <<<"$mine1" && ok "تسک مارال در برد پیش‌فرض محمد نیست" || bad "فیلتر نمای من"
  grep -q "id=\"mlt-t-$t2\"" <<<"$mine1" && grep -q "id=\"mlt-t-$t2\"" <<<"$mine2" && ok "تسک چندمسئوله در نمای هر دو مسئول" || bad "چند مسئول در نمای من"
  [ "$(grep -c "id=\"mlt-t-$t2\"" <<<"$all")" = 1 ] && ok "در نمای «همه» فقط یک کارت (تکراری نیست)" || bad "کارت تکراری"
  for id in $t1 $t2 $t3; do grep -q "id=\"mlt-t-$id\"" <<<"$all" || bad "در نمای همه نیست: $id"; done; ok "نمای «همه» هر سه را دارد"
  alln=$(page "$JAR1" meloraw-tasks '&view=all&assignee=maral')
  grep -q "id=\"mlt-t-$t3\"" <<<"$alln" && ! grep -q "id=\"mlt-t-$t1\"" <<<"$alln" && ok "فیلتر مسئول سمت سرور" || bad "فیلتر مسئول"
  grep -q 'class="mlt-seg"' <<<"$mine1" && ok "کلید «تسک‌های من / همه» هست" || bad "کلید نمای من/همه نیست"
  [ -n "${MLT_LOGIN3_UNMAPPED:-}" ] && {
    login "$JAR3" "$MLT_LOGIN3_UNMAPPED"; h=$(page "$JAR3" meloraw-tasks)
    grep -q 'هنوز به هیچ‌یک از اعضای تیم وصل نشده' <<<"$h" && grep -q "id=\"mlt-t-$t3\"" <<<"$h" && ok "حساب نگاشت‌نشده: پیام ساده و برد «همه»" || bad "حساب نگاشت‌نشده"
    grep -q 'class="mlt-seg"' <<<"$h" && bad "کلید من/همه برای نگاشت‌نشده نباید باشد" || true
  }

  # کارت: کد با دکمه‌ی کپی، برچسب دیر شده / ناقص، مسئول‌ها
  h=$(card "$all" "$t3")
  grep -q "data-copy=\"$c3\"" <<<"$h" && ok "دکمه‌ی کپی کد ($c3)" || bad "دکمه‌ی کپی نیست"
  grep -q 'mlt-badge--late' <<<"$h" && ok "برچسب «دیر شده» برای سررسید گذشته" || bad "برچسب دیر شده نیست"
  grep -q 'mlt-badge--late' <<<"$(card "$all" "$t1")" && bad "تسک بدون سررسید دیر شده نشان داده شد" || ok "تسک بدون سررسید دیر شده نیست"
  api "$MLT_AUTH" /import -X POST -d '{"format":"meloraw-tasks-export","version":1,"tasks":[{"id":1,"legacy_id":"uiinc_'$$'","title":"ناقص-ui","owner":"you"}]}' >/dev/null
  local inc; inc=$(api "$MLT_AUTH" '/tasks?incomplete=true' | jq -r '.[]|select(.title=="ناقص-ui")|.id')
  grep -q 'mlt-badge--incomplete' <<<"$(card "$(page "$JAR1" meloraw-tasks '&view=all')" "$inc")" && ok "برچسب «ناقص» روی تسک بدون تخمین" || bad "برچسب ناقص نیست"
  page "$JAR1" meloraw-tasks '&view=all&incomplete=1' | python3 "$HERE/ui-card.py" ids | grep -qx "$inc" && ok "فیلتر «فقط ناقص» آن را نشان می‌دهد" || bad "فیلتر ناقص"
  page "$JAR1" meloraw-tasks '&view=all&incomplete=1' | python3 "$HERE/ui-card.py" ids | grep -qx "$t1" && bad "فیلتر ناقص تسک کامل را هم آورد" || ok "و تسک کامل را نه"

  # تحویل به ایجنت: همه‌ی بخش‌ها هست و هیچ رمزی نیست
  local ho; ho=$(python3 "$HERE/ui-card.py" handoff "$t2" <<<"$all")
  for needle in "$c2" "برد-ب" "معیار ب" "meloraw-tasks/v1" "/tasks/$c2/timer/start" "/tasks/$c2/comments" "/tasks/$c2/timer/finish" '"status": "done"' "حداقل ۱۰" "تلگرام" "45 دقیقه" "محمد"; do
    grep -qF -- "$needle" <<<"$ho" && : || bad "متن تحویل: «$needle» نیست"
  done; ok "متن تحویل کامل است (کد، عنوان، معیار، مسئول‌ها، تخمین، آدرس API، مراحل)"
  grep -qiE 'Basic [A-Za-z0-9+/=]{12,}|[A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4}|password *[:=] *[^ ]{4,}|token *[:=]' <<<"$ho" && bad "در متن تحویل چیزی شبیه رمز یا توکن است" || ok "هیچ رمز، توکن یا Application Password در متن نیست"
  [[ "${MLT_AUTH#*:}" != "" ]] && grep -qF -- "${MLT_AUTH#*:}" <<<"$all" && bad "رمز واقعی در HTML برد است" || ok "رمز آزمون در HTML برد نیست"

  # ویرایش از فرم کارت (مسئول‌ها، سررسید، معیار)
  local body code
  body=$(python3 "$HERE/ui-form.py" action=mlt_edit_task "id=$t1" "+title=برد-الف" "+owner=you" "+priority=high" "+bucket=normal" "+assignees[]=you" "+assignees[]=chakra" "+due_date=2031-05-06" "+done_criteria=معیار از فرم" <<<"$all")
  code=$(curl -s -b "$JAR1" -o /dev/null -w '%{http_code}' -d "$body" "$MLT_SITE/wp-admin/admin-post.php"); [ "$code" = 302 ] && ok "ذخیره‌ی فرم ویرایش" || bad "ویرایش: $code"
  api "$MLT_AUTH" "/tasks/$t1" | jq -e '(.assignees|join(","))=="you,chakra" and .due_date=="2031-05-06" and .done_criteria=="معیار از فرم"' >/dev/null && ok "مسئول‌ها، سررسید و معیار انجام ذخیره شد" || bad "ویرایش اعمال نشد"
  body=$(python3 "$HERE/ui-form.py" action=mlt_edit_task "id=$t1" "+title=برد-الف" "+owner=you" "+priority=high" "+bucket=normal" "+due_date=" "+done_criteria=" "+assignees_marker=1" <<<"$all")
  curl -s -b "$JAR1" -o /dev/null -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
  api "$MLT_AUTH" "/tasks/$t1" | jq -e '(.assignees|join(","))=="you" and .due_date==null and .done_criteria==null' >/dev/null && ok "برداشتن همه‌ی تیک‌ها: فقط مسئول اصلی می‌ماند؛ سررسید و معیار پاک شد" || bad "پاک‌کردن اعمال نشد"

  # تمدید از فرم کارت
  local ext_nonce; all=$(page "$JAR1" meloraw-tasks '&view=all')
  body=$(python3 "$HERE/ui-form.py" action=mlt_extend "id=$t1" "+estimate_minutes=60" "+reason=کوتاه" <<<"$all")
  curl -s -b "$JAR1" -o /dev/null -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
  [ "$(api "$MLT_AUTH" "/tasks/$t1" | jq .estimate_minutes)" = 30 ] && ok "تمدید با دلیل کوتاه رد شد" || bad "تمدید با دلیل کوتاه پذیرفته شد"
  body=$(python3 "$HERE/ui-form.py" action=mlt_extend "id=$t1" "+estimate_minutes=60" "+reason=دلیل کافی برای تمدید" <<<"$all")
  curl -s -b "$JAR1" -o /dev/null -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
  api "$MLT_AUTH" "/tasks/$t1" | jq -e '.estimate_minutes==60 and .estimate_original==30 and (.extensions|length)==1' >/dev/null && ok "تمدید با دلیل: ۶۰ فعلی، ۳۰ اولیه" || bad "تمدید اعمال نشد"
  grep -q 'estimate_minutes' <<<"$(card "$(page "$JAR1" meloraw-tasks '&view=all')" "$t1")" && ok "فرم تمدید روی کارت است" || bad "فرم تمدید نیست"
  grep -q 'دلیل کافی برای تمدید' <<<"$(card "$(page "$JAR1" meloraw-tasks '&view=all')" "$t1")" && ok "تاریخچه‌ی تمدید با دلیل روی کارت دیده می‌شود" || bad "تاریخچه‌ی تمدید"
  # ساخت از فرم: بدون زمان تقریبی پذیرفته نمی‌شود
  body=$(python3 "$HERE/ui-form.py" action=mlt_add_task "+title=بدون-تخمین" "+owner=you" "+priority=med" "+bucket=normal" "+status=open" <<<"$all")
  curl -s -b "$JAR1" -o /dev/null -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
  [ "$(api "$MLT_AUTH" '/tasks' | jq '[.[]|select(.title=="بدون-تخمین")]|length')" = 0 ] && ok "فرم ساخت بدون زمان تقریبی تسک نمی‌سازد" || bad "تسک بدون تخمین ساخته شد"

  # تایمر از روی کارت
  all=$(page "$JAR1" meloraw-tasks '&view=all')
  body=$(python3 "$HERE/ui-form.py" action=mlt_timer do=start "id=$t1" <<<"$all")
  code=$(curl -s -b "$JAR1" -o /dev/null -w '%{http_code}' -d "$body" "$MLT_SITE/wp-admin/admin-post.php"); [ "$code" = 302 ] && ok "شروع تایمر از کارت" || bad "شروع: $code"
  h=$(page "$JAR1" meloraw-tasks '&view=all')
  grep -q 'mlt-timerbar mlt-timerbar--running' <<<"$h" && grep -A3 'mlt-timer-state' <<<"$h" | grep -q "$c1" && ok "نوار تایمر با کد تسک دیده می‌شود" || bad "نوار تایمر نیست"
  grep -q 'disabled title="اول تایمر فعالت را تمام کن"' <<<"$h" && ok "شروع روی تسک‌های دیگر غیرفعال است" || bad "دکمه‌ی شروع دیگر غیرفعال نیست"
  grep -q 'title="می‌پرسیم تا ببینیم وقت کجا می‌رود' <<<"$h" && ok "tooltip «چرا می‌پرسیم» کنار دلیل پاز" || bad "tooltip نیست"
  for r in rest food offhours waiting interrupted other_task; do grep -q "<option value=\"$r\"" <<<"$h" || bad "دلیل پاز $r نیست"; done; ok "شش دلیل پاز در انتخاب هست"
  grep -q 'mlt-timerbar' <<<"$(page "$JAR2" meloraw-tasks)" && bad "تایمر محمد در صفحه‌ی مارال دیده شد" || ok "تایمر کاربر دیگر در صفحه‌ی مارال نیست"
  body=$(python3 "$HERE/ui-form.py" action=mlt_timer do=pause "id=$t1" "+reason=" <<<"$h")
  curl -s -b "$JAR1" -o /dev/null -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
  [ "$(api "$MLT_AUTH" /timer | jq -r .state)" = running ] && ok "پاز بدون دلیل ثبت نمی‌شود" || bad "پاز بدون دلیل ثبت شد"
  body=$(python3 "$HERE/ui-form.py" action=mlt_timer do=pause "id=$t1" "+reason=food" <<<"$h")
  curl -s -b "$JAR1" -o /dev/null -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
  [ "$(api "$MLT_AUTH" /timer | jq -r .state)" = paused ] && ok "پاز با دلیل غذا" || bad "پاز ثبت نشد"
  h=$(page "$JAR1" meloraw-tasks '&view=all'); body=$(python3 "$HERE/ui-form.py" action=mlt_timer do=resume "id=$t1" <<<"$h")
  curl -s -b "$JAR1" -o /dev/null -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
  [ "$(api "$MLT_AUTH" /timer | jq -r .state)" = running ] && ok "ادامه" || bad "ادامه ثبت نشد"
  body=$(python3 "$HERE/ui-form.py" action=mlt_timer do=finish "id=$t1" <<<"$h")
  curl -s -b "$JAR1" -o /dev/null -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
  [ "$(api "$MLT_AUTH" /timer | jq -r .active)" = false ] && ok "پایان" || bad "پایان ثبت نشد"
  # done از روی فرم وضعیت: معیار نشان داده می‌شود و امتیاز می‌گیرد
  all=$(page "$JAR1" meloraw-tasks '&view=all'); body=$(python3 "$HERE/ui-form.py" action=mlt_set_status "id=$t1" "+status=done" <<<"$all")
  curl -s -b "$JAR1" -o /dev/null -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
  h=$(card "$(page "$JAR1" meloraw-tasks '&view=all&status=done')" "$t1")
  grep -q 'mlt-badge--score' <<<"$h" && ok "تسک done با تایمر: برچسب امتیاز" || bad "برچسب امتیاز نیست"
  # بدون تایمر
  all=$(page "$JAR1" meloraw-tasks '&view=all'); body=$(python3 "$HERE/ui-form.py" action=mlt_set_status "id=$t2" "+status=done" <<<"$all")
  curl -s -b "$JAR2" -o /dev/null -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
  curl -s -b "$JAR1" -o /dev/null -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
  h=$(card "$(page "$JAR1" meloraw-tasks '&view=all&status=done')" "$t2")
  grep -q 'mlt-badge--notimer' <<<"$h" && grep -q 'معیار انجام:' <<<"$h" && ok "done بدون تایمر: «بدون زمان‌سنج» و معیار انجام نشان داده می‌شود" || bad "برچسب بدون زمان‌سنج/معیار"
  for id in $t1 $t2 $t3 $inc; do api "$MLT_AUTH" "/tasks/$id" -X DELETE >/dev/null; done
}

# روتین‌ها: قالب، متوقف کردن، ادامه، ناقص
run_routines() {
  echo "== صفحه‌ی روتین‌ها"
  : "${MLT_API:?MLT_API}" "${MLT_AUTH:?MLT_AUTH}"
  local today key h body rid
  today=$(date -u +%F); key="uir$$"
  api "$MLT_AUTH" /import -X POST -d '{"format":"meloraw-tasks-export","version":1,"tasks":[{"id":1,"legacy_id":"routine_'$key'_'$today'","title":"روتین-ui","owner":"you","kind":"routine","recurrence":"daily","period_key":"'$today'","meta":{"routine_id":"'$key'"}}]}' >/dev/null
  rid=$(api "$MLT_AUTH" '/tasks?kind=routine' | jq -r --arg k "routine_${key}_$today" '.[]|select(.legacy_id==$k)|.id')
  h=$(page "$JAR1" meloraw-tasks-routines)
  grep -A6 "id=\"mlt-r-$key\"" <<<"$h" | grep -q 'mlt-badge--incomplete' && ok "روتین بدون تخمین: برچسب ناقص" || bad "برچسب ناقص نیست"
  has "$(page "$JAR1" meloraw-tasks-routines '&incomplete=1')" "mlt-r-$key" && ok "فیلتر «فقط ناقص»" || bad "فیلتر ناقص"
  body=$(python3 "$HERE/ui-form.py" action=mlt_routine_template "key=$key" do=save "+estimate_minutes=15" "+done_criteria=معیار روتین از فرم" "+recurrence_until=" <<<"$h")
  [ "$(curl -s -b "$JAR1" -o /dev/null -w '%{http_code}' -d "$body" "$MLT_SITE/wp-admin/admin-post.php")" = 302 ] && ok "ذخیره‌ی قالب" || bad "ذخیره‌ی قالب"
  api "$MLT_AUTH" /routines | jq -e --arg k "$key" '.[]|select(.key==$k)|.estimate_minutes==15 and .done_criteria=="معیار روتین از فرم" and .incomplete==false' >/dev/null && ok "قالب ذخیره شد و ناقص نیست" || bad "قالب اعمال نشد"
  h=$(page "$JAR1" meloraw-tasks-routines)
  body=$(python3 "$HERE/ui-form.py" action=mlt_routine_template "key=$key" do=stop <<<"$h")
  [ "$(curl -s -b "$JAR1" -o /dev/null -w '%{http_code}' -d "$body" "$MLT_SITE/wp-admin/admin-post.php")" = 302 ] && ok "متوقف کردن" || bad "متوقف کردن"
  h=$(page "$JAR1" meloraw-tasks-routines)
  api "$MLT_AUTH" /routines | jq -e --arg k "$key" '.[]|select(.key==$k)|.stopped==true' >/dev/null && ok "روتین متوقف شد" || bad "توقف اعمال نشد"
  grep -q 'متوقف‌شده' <<<"$h" && ok "در بخش «متوقف‌شده»" || bad "بخش متوقف‌شده نیست"
  python3 "$HERE/ui-form.py" action=mlt_routine_status "key=$key" <<<"$h" >/dev/null 2>&1 && bad "روتین متوقف هنوز فرم وضعیت دارد" || ok "روتین متوقف فرم تغییر وضعیت ندارد"
  body=$(python3 "$HERE/ui-form.py" action=mlt_routine_template "key=$key" do=resume <<<"$h")
  curl -s -b "$JAR1" -o /dev/null -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
  api "$MLT_AUTH" /routines | jq -e --arg k "$key" '.[]|select(.key==$k)|.stopped==false' >/dev/null && ok "ادامه‌ی روتین" || bad "ادامه اعمال نشد"
  [ -n "$rid" ] && api "$MLT_AUTH" "/tasks/$rid" -X DELETE >/dev/null
}

# «امروز من»: ترتیب، ۳ تمرکز اصلی، فقط تسک‌های خودم، نگاشت‌نشده
run_today() {
  echo "== امروز من"
  : "${MLT_API:?MLT_API}" "${MLT_AUTH:?MLT_AUTH}" "${MLT_AUTH2:?MLT_AUTH2}"
  local today past h ids exp k mk
  today=$(date -u +%F); past=$(date -u -d '3 days ago' +%F)
  declare -A T
  mk() { T[$1]=$(api "$MLT_AUTH" /tasks -X POST -d "$2" | jq -r .id); }
  mk A '{"title":"ام-A","owner":"you","estimate_minutes":10,"priority":"high"}'
  mk B '{"title":"ام-B","owner":"you","estimate_minutes":10,"priority":"low","due_date":"'$today'"}'
  mk C '{"title":"ام-C","owner":"you","estimate_minutes":10,"priority":"med","due_date":"'$past'"}'
  mk D '{"title":"ام-D","owner":"you","estimate_minutes":10,"priority":"high"}'
  mk E '{"title":"ام-E","owner":"you","estimate_minutes":10,"priority":"low"}'
  mk F '{"title":"ام-F","owner":"you","estimate_minutes":10,"priority":"med"}'
  mk G '{"title":"ام-G (مارال)","owner":"maral","estimate_minutes":10,"priority":"high"}'
  mk H '{"title":"ام-H (انجام‌شده)","owner":"you","estimate_minutes":10,"priority":"high"}'
  for k in B D F; do api "$MLT_AUTH" "/tasks/${T[$k]}" -X PATCH -d '{"status":"in_progress"}' >/dev/null; done
  api "$MLT_AUTH" "/tasks/${T[H]}" -X PATCH -d '{"status":"done"}' >/dev/null
  h=$(page "$JAR1" meloraw-tasks-today)
  ids=$(python3 "$HERE/ui-card.py" ids <<<"$h" | tr '\n' ' ')
  exp="${T[B]} ${T[C]} ${T[D]} ${T[A]} ${T[F]} ${T[E]} "
  # در صفحه‌ی امروز تسک‌های دیگرِ همین کاربر (از اجراهای قبلی) هم ممکن است باشند؛ ترتیب شش تسک آزمون را می‌سنجیم
  local mine; mine=$(for id in $ids; do for k in A B C D E F; do [ "$id" = "${T[$k]}" ] && echo -n "$id "; done; done)
  [ "$mine" = "$exp" ] && ok "ترتیب: موعددار/دیرشده، اولویت بالا، در حال انجام (B C D A F E)" || bad "ترتیب نادرست: $mine (انتظار $exp)"
  grep -q "id=\"mlt-t-${T[G]}\"" <<<"$h" && bad "تسک مارال در «امروز من» محمد آمد" || ok "تسک دیگران نیست"
  grep -q "id=\"mlt-t-${T[H]}\"" <<<"$h" && bad "تسک انجام‌شده آمد" || ok "تسک انجام‌شده نیست"
  local focus; focus=$(printf '%s' "$h" | python3 -c '
import re,sys
h=sys.stdin.read()
m=re.search(r"تمرکز اصلی.*?</section>",h,re.S)
print(" ".join(re.findall(r"id=\"mlt-t-(\d+)\"",m.group(0))) if m else "")
')
  local first3; first3=$(echo "$ids" | awk '{print $1" "$2" "$3}')
  [ "$focus" = "$first3" ] && [ "$(wc -w <<<"$focus")" = 3 ] && ok "«تمرکز اصلی» دقیقاً سه تسک اول است" || bad "تمرکز اصلی: '$focus' (سه تای اول '$first3')"
  grep -q '۸۰٪' <<<"$h" && grep -q '۲۰٪' <<<"$h" && ok "نسبت ۸۰/۲۰ نوشته شده" || bad "نسبت ۸۰/۲۰ نیست"
  h=$(page "$JAR2" meloraw-tasks-today)
  grep -q "id=\"mlt-t-${T[G]}\"" <<<"$h" && ! grep -q "id=\"mlt-t-${T[A]}\"" <<<"$h" && ok "مارال فقط تسک‌های خودش را می‌بیند" || bad "امروز مارال"
  [ -n "${MLT_LOGIN3_UNMAPPED:-}" ] && { login "$JAR3" "$MLT_LOGIN3_UNMAPPED"; has "$(page "$JAR3" meloraw-tasks-today)" 'ساخته نمی‌شود' && ok "حساب نگاشت‌نشده: پیام ساده" || bad "پیام نگاشت‌نشده نیست"; }
  for k in A B C D E F G H; do api "$MLT_AUTH" "/tasks/${T[$k]}" -X DELETE >/dev/null; done
}

# گزارش زمان و امتیاز: دسترسی، نمودار بدون کتابخانه، پاز بر اساس دلیل، برچسب سرعت قابل خاموش شدن
run_time() {
  echo "== گزارش زمان و امتیاز"
  : "${MLT_API:?MLT_API}" "${MLT_AUTH:?MLT_AUTH}" "${MLT_AUTH2:?MLT_AUTH2}"
  local id h body week
  id=$(api "$MLT_AUTH" /tasks -X POST -d '{"title":"گزارش-ui","owner":"you","estimate_minutes":5}' | jq -r .id)
  api "$MLT_AUTH" "/tasks/$id/timer/start" -X POST >/dev/null; sleep 1
  api "$MLT_AUTH" "/tasks/$id/timer/pause" -X POST -d '{"reason":"interrupted"}' >/dev/null; sleep 1
  api "$MLT_AUTH" "/tasks/$id/timer/resume" -X POST >/dev/null; sleep 1
  api "$MLT_AUTH" "/tasks/$id/timer/pause" -X POST -d '{"reason":"other_task"}' >/dev/null; sleep 1
  api "$MLT_AUTH" "/tasks/$id/timer/resume" -X POST >/dev/null
  api "$MLT_AUTH" "/tasks/$id" -X PATCH -d '{"status":"done"}' >/dev/null
  h=$(page "$JAR1" meloraw-tasks-time)
  has "$h" 'زمان خالص کار' && has "$h" 'تعداد پاز' && has "$h" 'امتیاز هفته' && ok "اعداد خلاصه (زمان خالص، پاز، تعداد، امتیاز)" || bad "خلاصه نیست"
  has "$h" 'قطع شدن / کار دیگر' || true
  grep -q 'قطع شدن / کار فوری' <<<"$h" && grep -q 'کار دیگر' <<<"$h" && ok "پاز «قطع شدن» و «کار دیگر» جدا نشان داده می‌شود" || bad "دلیل‌های جدا"
  grep -q '<svg viewBox="0 0 100 10"' <<<"$h" && ok "نمودار با SVG ساده" || bad "SVG نیست"
  local region; region=$(printf '%s' "$h" | python3 -c '
import sys,re
h=sys.stdin.read(); m=re.search(r"<div class=\"mlt\" dir=\"rtl\">.*?(?=<div class=\"clear\">|<div id=\"wpfooter\")",h,re.S); print(m.group(0) if m else "")
')
  grep -qE '<script|src="https?://|href="https?://[^"]*(cdn|googleapis|unpkg)' <<<"$region" && bad "کتابخانه/اسکریپت خارجی در گزارش" || ok "بدون اسکریپت یا کتابخانه‌ی خارجی"
  has "$h" 'تخمین اولیه' && ok "مقایسه با تخمین اولیه" || bad "مقایسه نیست"
  grep -q 'گزارش-ui\|MH-' <<<"$h" && ok "تسک تایمردار در جدول/نمودار هست" || bad "تسک در گزارش نیست"
  grep -qE '🐇|🚶|🐢' <<<"$h" && ok "برچسب سرعت در گزارش خودِ فرد" || bad "برچسب سرعت نیست"
  # مارال گزارش محمد را می‌بیند (ادمین)، ولی برچسب سرعت فقط در گزارش خود فرد است
  h=$(curl -s -b "$JAR2" "$MLT_SITE/wp-admin/admin.php?page=meloraw-tasks-time&user=${MLT_LOGIN1%%:*}")
  has "$h" 'زمان خالص کار' && ok "ادمین دیگر گزارش را می‌بیند" || bad "گزارش دیگری دیده نشد"
  grep -qE '🐇|🚶|🐢' <<<"$h" && bad "برچسب سرعت در گزارش دیگران نباید باشد" || ok "برچسب سرعت در گزارش دیگران نیست"
  if [ -n "${MLT_LOGIN_NOCAP:-}" ]; then
    [ "$(curl -s -b "$JAR4" -o /dev/null -w '%{http_code}' "$MLT_SITE/wp-admin/admin.php?page=meloraw-tasks-time")" = 403 ] && ok "کاربر بدون نقش: ۴۰۳" || bad "کاربر بدون نقش گزارش را دید"
  fi
  # خاموش/روشن کردن برچسب
  body=$(python3 "$HERE/ui-form.py" action=mlt_report_action do=speed_hide <<<"$(page "$JAR1" meloraw-tasks-time)")
  [ "$(curl -s -b "$JAR1" -o /dev/null -w '%{http_code}' -d "$body" "$MLT_SITE/wp-admin/admin-post.php")" = 302 ] && ok "خاموش کردن برچسب سرعت" || bad "خاموش کردن"
  grep -qE '🐇|🚶|🐢' <<<"$(page "$JAR1" meloraw-tasks-time)" && bad "برچسب هنوز دیده می‌شود" || ok "برچسب دیگر نیست (ذخیره برای خودِ فرد)"
  body=$(python3 "$HERE/ui-form.py" action=mlt_report_action do=speed_show <<<"$(page "$JAR1" meloraw-tasks-time)")
  curl -s -b "$JAR1" -o /dev/null -d "$body" "$MLT_SITE/wp-admin/admin-post.php"
  grep -qE '🐇|🚶|🐢' <<<"$(page "$JAR1" meloraw-tasks-time)" && ok "دوباره روشن شد" || bad "روشن نشد"
  # بازمحاسبه
  body=$(python3 "$HERE/ui-form.py" action=mlt_report_action do=recalc <<<"$(page "$JAR1" meloraw-tasks-time)")
  [ "$(curl -s -b "$JAR1" -o /dev/null -w '%{http_code}' -d "$body" "$MLT_SITE/wp-admin/admin-post.php")" = 302 ] && ok "بازمحاسبه‌ی امتیازها" || bad "بازمحاسبه"
  curl -s -b "$JAR1" -o /dev/null -d "action=mlt_report_action&do=recalc&_wpnonce=bad" "$MLT_SITE/wp-admin/admin-post.php" -w '%{http_code}' | grep -q 403 && ok "بدون nonce: ۴۰۳" || bad "nonce"
  api "$MLT_AUTH" "/tasks/$id" -X DELETE >/dev/null
}

case "$SECTION" in
  clock) run_clock ;; today) run_today ;; time) run_time ;; banner) run_banner ;; settings) run_settings ;; board) run_board ;; routines) run_routines ;;
  all) run_clock; run_banner; run_settings; run_board; run_routines; run_today; run_time ;;
  *) echo "بخش نامعتبر: $SECTION"; exit 2 ;;
esac
[ $FAIL = 0 ] && echo "همه‌ی بررسی‌ها گذشت." || echo "بررسی ناموفق داشت."
exit $FAIL
