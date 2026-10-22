#!/usr/bin/env bash
# E2E-прогон инсталлятора на dev-сервере (sqlite). Запуск из корня проекта.
set -e
BASE="http://localhost:8710"
JAR="tests/tmp/cookies.txt"
mkdir -p tests/tmp
rm -f "$JAR" storage/dev-test.sqlite config/config.php config/installed.lock
rm -rf storage/keys/* 2>/dev/null || true

get() { curl -s -b "$JAR" -c "$JAR" "$BASE$1"; }
post() { local url="$1"; shift; curl -s -b "$JAR" -c "$JAR" -X POST "$@" "$BASE$url"; }

# CSRF из страницы
PAGE=$(get "/install?step=0")
CSRF=$(echo "$PAGE" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
echo "CSRF=$CSRF"
[ -n "$CSRF" ] || { echo "FAIL: no csrf"; exit 1; }

post "/install?step=0" -d "_csrf=$CSRF" -o /dev/null -w "step0 %{http_code} -> %{redirect_url}\n"
post "/install?step=1" -d "_csrf=$CSRF" -o /dev/null -w "step1 %{http_code} -> %{redirect_url}\n"

# AJAX проверка БД
echo -n "db_test: "
post "/install?step=2" -d "_csrf=$CSRF" -d "_ajax_action=db_test" -d "db_driver=sqlite" -d "db_name=storage/dev-test.sqlite"
echo
post "/install?step=2" -d "_csrf=$CSRF" -d "db_driver=sqlite" -d "db_name=storage/dev-test.sqlite" -d "db_prefix=sso_" -o /dev/null -w "step2 %{http_code} -> %{redirect_url}\n"
post "/install?step=3" -d "_csrf=$CSRF" -d "mode=install" -o /dev/null -w "step3 %{http_code} -> %{redirect_url}\n"
post "/install?step=4" -d "_csrf=$CSRF" -o /dev/null -w "step4 %{http_code} -> %{redirect_url}\n"
post "/install?step=5" -d "_csrf=$CSRF" -d "email=admin@test.local" -d "login=admin" -d "password=correct-horse-battery" -d "first_name=Иван" -d "last_name=Петров" -o /dev/null -w "step5 %{http_code} -> %{redirect_url}\n"
post "/install?step=6" -d "_csrf=$CSRF" -d "issuer=http://localhost:8710" -d "app_name=SSO (dev)" -d "mail_driver=log" -d "skip_mail=1" -o /dev/null -w "step6 %{http_code} -> %{redirect_url}\n"

# Финальный экран
FINAL=$(get "/install?step=7")
echo "$FINAL" | grep -q "Готово" && echo "FINAL SCREEN OK" || { echo "FAIL: no final screen"; echo "$FINAL" | head -30; exit 1; }

# Артефакты
[ -f config/config.php ] && echo "config.php OK" || echo "FAIL config"
[ -f config/installed.lock ] && echo "lock OK" || echo "FAIL lock"
ls storage/keys/*.pem >/dev/null 2>&1 && echo "keys OK" || echo "FAIL keys"
php -d extension=pdo_sqlite -r '$p=new PDO("sqlite:storage/dev-test.sqlite");$t=$p->query("SELECT name FROM sqlite_master WHERE type=\"table\"")->fetchAll(PDO::FETCH_COLUMN);echo "tables: ".count($t)." — ".implode(", ",array_slice($t,0,6))."…\n";$u=$p->query("SELECT email,status FROM sso_users")->fetchAll(PDO::FETCH_ASSOC);var_export($u);echo "\n";'
echo "INSTALL E2E DONE"
