#!/usr/bin/env bash
# بررسی صفحه‌های پنل با curl (بدون مرورگر). فقط روی سایت تست یا کپی اجرا شود.
#
#   MLT_SITE=https://example.com \
#   MLT_LOGIN1='user:password' MLT_LOGIN2='user2:password'   # دو ادمین (رمز ورود، نه Application Password)
#   MLT_LOGIN_NOCAP='editor:password'                       # اختیاری
#   tests/ui-check.sh [clock|banner|settings|all]
#
# رمزها فقط از متغیر محیطی خوانده می‌شوند. فرم‌ها با همان nonce خودشان ارسال می‌شوند.

set -u
: "${MLT_SITE:?MLT_SITE را تنظیم کنید}"
: "${MLT_LOGIN1:?MLT_LOGIN1 را تنظیم کنید}"
: "${MLT_LOGIN2:?MLT_LOGIN2 را تنظیم کنید}"
SECTION="${1:-all}"
HERE=$(cd "$(dirname "$0")" && pwd)
JAR1=$(mktemp); JAR2=$(mktemp); JAR3=$(mktemp)
FAIL=0
ok()  { echo "  ✓ $1"; }
bad() { echo "  ✗ $1"; FAIL=1; }
trap 'rm -f "$JAR1" "$JAR2" "$JAR3" /tmp/mlt_ui.$$' EXIT

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
[ -n "${MLT_LOGIN_NOCAP:-}" ] && login "$JAR3" "$MLT_LOGIN_NOCAP"

run_clock() {
  echo "== نوار تاریخ و ساعت UTC"
  local slug html
  for slug in meloraw-tasks meloraw-tasks-routines meloraw-tasks-ideas meloraw-tasks-reports meloraw-tasks-log meloraw-tasks-import meloraw-tasks-settings; do
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
  for slug in meloraw-tasks meloraw-tasks-routines; do
    h=$(page "$JAR1" "$slug"); has "$h" 'id="mlt-why"' && ok "$slug: بنر برای کاربر تازه دیده می‌شود" || bad "$slug: بنر نیست"
  done
  for slug in meloraw-tasks-reports meloraw-tasks-log meloraw-tasks-ideas meloraw-tasks-settings; do
    h=$(page "$JAR1" "$slug"); has "$h" 'id="mlt-why"' && bad "$slug: بنر نباید اینجا باشد" || true
  done
  ok "بنر روی صفحه‌های گزارش/لاگ/ایده/تنظیمات نیست"
  h=$(page "$JAR1" meloraw-tasks); has "$h" 'چرا زمان کارهامون رو ثبت می‌کنیم؟ این زمان‌سنج برای کنترل شما نیست' && ok "متن دقیق بنر" || bad "متن بنر"
  has "$h" 'چرا زمان ثبت می‌کنیم؟</button>' && ok "لینک «چرا زمان ثبت می‌کنیم؟» در سربرگ" || bad "لینک سربرگ نیست"
  local code; code=$(post_form "$JAR1" meloraw-tasks action=mlt_banner do=hide); [ "$code" = 302 ] && ok "بستن (×) → ۳۰۲" || bad "بستن: $code"
  for slug in meloraw-tasks meloraw-tasks-routines; do
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
    curl -s -b "$JAR3" -o /dev/null -w '%{http_code}' -d "action=mlt_banner&do=hide&_wpnonce=x" "$MLT_SITE/wp-admin/admin-post.php" | grep -q 403 && ok "کاربر بدون نقش: ۴۰۳" || bad "کاربر بدون نقش پذیرفته شد"
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

case "$SECTION" in
  clock) run_clock ;; banner) run_banner ;; settings) run_settings ;;
  all) run_clock; run_banner; run_settings ;;
  *) echo "بخش نامعتبر: $SECTION"; exit 2 ;;
esac
[ $FAIL = 0 ] && echo "همه‌ی بررسی‌ها گذشت." || echo "بررسی ناموفق داشت."
exit $FAIL
