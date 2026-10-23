#!/usr/bin/env bash
# Полная батарея: сам поднимает SSO и тестовый RP, гоняет всё, гасит серверы.
# Использование: bash tests/run-all.sh [порт-sso] [порт-rp]
set -u

SSO_PORT="${1:-8710}"
RP_PORT="${2:-8711}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PHP_FLAGS="-d extension=pdo_sqlite"
php -r 'exit(extension_loaded("sodium") ? 0 : 1);' 2>/dev/null || PHP_FLAGS="$PHP_FLAGS -d extension=sodium"

# config/ и storage/keys/ git не хранит (в них только то, что пишет установка):
# в свежей копии — CI, облачная сессия, новый worktree — их нет, инсталлятор
# встаёт на первом шаге, и следом краснеет вся батарея.
mkdir -p tests/tmp tests/rp storage/logs config storage/keys
FAILED=0
PIDS=()

cleanup() {
  for pid in "${PIDS[@]:-}"; do kill "$pid" 2>/dev/null || true; done
}
trap cleanup EXIT

start_server() {
  local port="$1" docroot="$2" router="${3:-}"
  # Без opcache: на Linux он включён и для php -S, а сверяет файлы раз в две
  # секунды. Батарея правит код на месте (мутации, конфиг портала в e2e) и тут
  # же идёт в сервер — тот отвечал старой версией, и мутации выходили «слепыми».
  php $PHP_FLAGS -d opcache.enable=0 -S "localhost:$port" -t "$docroot" $router > "tests/tmp/server-$port.log" 2>&1 &
  PIDS+=($!)
  for _ in $(seq 1 40); do
    if curl -s -o /dev/null --max-time 1 "http://localhost:$port/" 2>/dev/null; then return 0; fi
    sleep 0.25
  done
  echo "не поднялся сервер на :$port"; return 1
}

step() {
  echo ""
  echo "── $1 ──"
  shift
  if "$@"; then :; else FAILED=1; echo "ПРОВАЛ: $*"; fi
}

echo "=== Линт ==="
LINT_ERR=0
while IFS= read -r f; do
  out=$(php -l "$f" 2>&1 | grep -v "^No syntax errors")
  if [ -n "$out" ]; then echo "$out"; LINT_ERR=1; fi
done < <(find app install templates tests public -name "*.php" 2>/dev/null; ls cron.php build_dist.php 2>/dev/null)
[ $LINT_ERR -eq 0 ] && echo "синтаксис чистый" || FAILED=1

# Свежая установка на SQLite (инсталлятор + миграции)
rm -f config/config.php config/installed.lock storage/dev-test.sqlite
rm -rf storage/keys/* 2>/dev/null || true
# Роутер вместо public/index.php: он возвращает рабочий каталог в корень проекта,
# иначе относительный путь к sqlite в config.php ведёт в public/ (см. tests/router.php).
start_server "$SSO_PORT" public tests/router.php || exit 1
step "Инсталлятор (e2e)" bash tests/install_e2e.sh > tests/tmp/install.log 2>&1
tail -1 tests/tmp/install.log

step "Целостность шаблонов и статики" php tests/templates_test.php
step "Покрытие английского словаря" php tools/i18n_coverage.php
# Проводка: каталог обещает переключатель — код обязан его слушать. Дефект
# этого жанра прожил в проекте два года: тумблер включался и не делал ничего.
step "Ревизия проводки" php $PHP_FLAGS tools/wiring_audit.php
# Три ревизии, которые существовали и НЕ ЗАПУСКАЛИСЬ ни разу за прогон (v4.45).
# audit.php и settings_audit.php к тому же не умели провалиться: печатали
# найденное и заканчивались с кодом 0. Проверка, которую никто не зовёт и
# которая не может сказать «плохо», — не проверка, а её изображение.
step "Ревизия проекта (маршруты, шаблоны, мёртвый код)" php $PHP_FLAGS tools/audit.php
step "Ревизия настроек" php $PHP_FLAGS tools/settings_audit.php
# Схему пишем на MySQL, а гоняем на SQLite через транслятор: боевой движок
# миграции не выполняет ни разу за всю батарею. Дважды это стоило аварии.
step "DDL против правил MySQL" php $PHP_FLAGS tools/mysql_lint.php
# «Читается» — число, а не мнение. Пары «текст × подложка» считаются по всему
# набору токенов: цвет, посчитанный только для белого фона, ломается о первую
# же подкраску — так --text-3 брал 5.04 на белом и 3.50 на гостевых экранах.
step "Контраст текста и подложек" php $PHP_FLAGS tools/contrast_audit.php
# Заголовки — оглавление страницы, а не размер шрифта: по ним ходит чтение с
# экрана и режим чтения браузера. Четыре самых показываемых экрана продукта
# (вход, регистрация, восстановление, согласие) шли без единого h1.
step "Устройство страницы (заголовки, ориентиры, фокус)" php $PHP_FLAGS tools/structure_audit.php
step "Юнит-тесты (RFC-векторы)" php $PHP_FLAGS tests/unit.php
# Код обновляют раньше, чем гоняют миграции. Обычная батарея этого не ловит:
# она всегда на свежей схеме. На боевой так молча умер весь журнал аудита.
step "Отставшая схема (неприменённая миграция)" php $PHP_FLAGS tests/lagging_schema.php
step "WebAuthn (синтетические векторы)" php $PHP_FLAGS tests/webauthn_test.php

start_server "$RP_PORT" tests/rp || exit 1
step "E2E полного цикла" php $PHP_FLAGS tests/e2e.php
step "Состязательный OIDC" php $PHP_FLAGS tests/oidc_adversarial.php
# Ревизия ядра v4.70: одноразовое гасится один раз, повтор кода отзывает
# выданное по нему, pending держит токены, TOTP-код не входит дважды, возврат
# только на себя. Всё это было «проверить, потом пометить» двумя запросами.
step "Ядро: одноразовое, повторы, редиректы" php $PHP_FLAGS tests/core_hardening.php
step "Смоук админки" php $PHP_FLAGS tests/admin_smoke.php

# Admin API уровня суперадмина: может ли программа то же, что человек.
# До v4.51 токен умел заводить человека и портал, но не мог соединить их:
# ни ролей, ни организаций, ни доступов — автоматизировалась половина работы.
step "Admin API целиком (сценарий агента)" php $PHP_FLAGS tests/api_superadmin.php

# Систематизация выдачи ролей: список собирался «свои плюс системные» и
# вырастал до двенадцати пунктов с дублями и ролями чужих областей.
step "Что предлагать к выдаче (роли)" php $PHP_FLAGS tests/role_offer.php

# Заглушка в корне: единственное, что отвечает, когда не работает переписывание
# адресов. На боевой .htaccess не доехал при заливке дважды; с v4.52 заглушка
# создаёт его сама, и проверять её надо запуском, а не чтением исходника.
step "Заглушка в корне (разбор и починка)" php $PHP_FLAGS tests/stub_test.php

# Подключение сервиса: адрес возврата без догадок, причина недоступного манифеста,
# кнопка под htmx/Turbo, заготовки .well-known. Всё — с подключения Архива.
step "Подключение сервиса" php $PHP_FLAGS tests/portal_connect.php

# Удаление и отзыв доступа доходят до порталов, и портал знает, что случилось:
# человек может вернуться — или стереть всё. Живой случай 11.09: «Убрать» в
# карточке Архива не сказало Архиву ничего (v4.62).
step "Удаление доходит до порталов" php $PHP_FLAGS tests/deletion_events.php

# Экран входа в оформлении портала: параметры, а не CSS; контраст считается на
# фоне портала, шрифты — ключами из нашего списка, знак SSO остаётся (v4.65).
step "Тема портала на экране входа" php $PHP_FLAGS tests/portal_theme.php

# Роли портала при переносе людей: только в области портала, общие роли SSO —
# никогда. Живой случай 12.09: людей Портфолио перенесли без ролей (v4.66).
step "Роли при переносе людей" php $PHP_FLAGS tests/portal_roles.php

# Карточки объектов: карта ролей без двойных ключей, люди организации одним
# списком, шапка без повтора имени (v4.69, замечания владельца 13.09).
step "Карточки объектов в админке" php $PHP_FLAGS tests/admin_cards.php

# Экран входа настраивается в SSO (v4.71): редактор в карточке портала и в
# оформлении сайта, живой предпросмотр во фрейме, обложка, способы входа.
step "Экран входа: редактор и предпросмотр" php $PHP_FLAGS tests/login_look.php

# Защита (v4.72): подтверждение почты новыми, блокировка адресов, журнал
# активности, логин из почты; вход окном поверх портала и «Вы вошли». Живой
# случай 21.09: непрошеный гость входил с одного адреса, закрыть его было нечем.
step "Защита: почта, адреса, активность; вход окном" php $PHP_FLAGS tests/guard.php

# Вход окном на странице портала (v4.73): экран входа SSO во фрейме — своя
# cookie на чужом сайте, встраивание только этим порталом и только на экранах
# входа, адрес возврата отдаётся странице портала. Живой случай 24.09.
step "Вход окном на странице портала" php $PHP_FLAGS tests/frame.php
step "Обложка портала на витрине" php $PHP_FLAGS tests/portal_cover.php
step "Оформление сайта: главная и весь сайт" php $PHP_FLAGS tests/site_look.php
step "Шрифты Google и готовые схемы" php $PHP_FLAGS tests/fonts_presets.php
step "Конструктор оформления и свои схемы" php $PHP_FLAGS tests/look_builder.php
step "Живая обложка: картинки, видео, YouTube" php $PHP_FLAGS tests/cover_set.php
step "Контрольный прогон 26.09: находки" php $PHP_FLAGS tests/ui_fixes.php
step "Фон страниц: вход, кабинет, админка" php $PHP_FLAGS tests/backdrop.php
step "Кнопки сервисов входа и idp_hint" php $PHP_FLAGS tests/social_buttons.php
step "Окно входа: Telegram переходом, без лишних окон" php $PHP_FLAGS tests/login_window.php
step "Дубли: подсказка, «Войти и привязать», объединение в админке" php $PHP_FLAGS tests/duplicates.php
step "Списки порталов и организаций" php $PHP_FLAGS tests/admin_lists.php
step "Экраны людей: роли, сессии, действия" php $PHP_FLAGS tests/admin_people.php
step "Журналы: без статики и стуков сканеров" php $PHP_FLAGS tests/admin_logs.php
# Боты на регистрации (v4.95): 64 за шесть дней с девяти подсетей, удалять — по одному.
step "Боты: отказ, «Похоже на ботов», адреса" php $PHP_FLAGS tests/admin_bots.php
# Регистрация по шагам (v4.96): почта → код → имя; аккаунт — последним шагом.
step "Регистрация по шагам" php $PHP_FLAGS tests/signup_steps.php
# Вкладки входа без перезагрузки и вход на алиасе портала (v4.97).
step "Вкладки входа и алиас портала" php $PHP_FLAGS tests/login_swap.php

# Ревизия кода (v4.98): права делегированного админа, API, SCIM; вход и токены.
# До неё ни одна проверка не ходила в админку не суперадмином — и приглашение
# портала делало его админа суперадмином.
step "Границы прав: приглашения, API, SCIM, порталы" php $PHP_FLAGS tests/access_bounds.php
step "Вход и токены: 2FA соцвхода, замок, FedCM, refresh" php $PHP_FLAGS tests/login_bounds.php
step "Инфраструктура: журнал почты, cron, хранение, установщик" php $PHP_FLAGS tests/infra_bounds.php

# Письма и интерфейс на одном языке (v4.74): английское письмо без русских
# букв, тосты и заголовки — переводом при выводе, письмо за человека — на его
# языке. Замечание владельца 24.09: «часть письма на русском, часть на английском».
step "Письма и интерфейс на одном языке" php $PHP_FLAGS tests/mail_i18n.php

# Обрыв посреди многошаговой записи: ломаем операцию настоящим отказом базы и
# смотрим, осталась ли половина. До v4.45 помощник транзакций был объявлен и
# не использовался нигде — удаление пользователя шло семнадцатью запросами.
step "Атомарность многошаговых записей" php $PHP_FLAGS tests/atomicity.php

# Проверка проверок. Зелёный прогон значит либо «дефектов нет», либо «их никто
# не видит», и различить это можно только вернув дефект нарочно. Три проверки в
# этом проекте были зелены годами именно потому, что были слепы.
# Файлы правятся на месте и возвращаются в finally со сверкой побайтно.
step "Проверка проверок (мутации)" php $PHP_FLAGS tests/mutations.php

# Прогон несчастливых путей идёт ПОСЛЕДНИМ: он бьёт по каждому маршруту пустыми
# POST'ами и портит состояние базы, а всё, что смотрит на состояние, уже отработало.
step "Мусор на входе (все маршруты)" php $PHP_FLAGS tools/probe.php

echo ""
if [ $FAILED -eq 0 ]; then
  echo "═══ ВСЁ ЗЕЛЁНОЕ ═══"
else
  echo "═══ ЕСТЬ ПРОВАЛЫ ═══"
fi
exit $FAILED
