#!/bin/bash
# Выкладка выпуска на сервер — принудительная команда ключа выкладки GitHub Actions.
#
# Ключ в authorized_keys прописан так, что ничего, кроме этого скрипта, им
# сделать нельзя (см. README.md рядом):
#   command="/usr/local/sbin/sso-deploy",restrict ssh-ed25519 AAAA… github-actions-deploy
# Команды приходят в SSH_ORIGINAL_COMMAND:
#   check          — связь есть, какая версия на сайте;
#   deploy X.Y.Z   — архив выпуска (build_dist.php) на stdin: копия сайта,
#                    распаковка рядом, сверка версии, наложение, права,
#                    миграции, проверка живой установки.
#
# Настройки — в /etc/sso-deploy.conf на сервере, не в репозитории (пример —
# sso-deploy.conf.example). Вывод уходит в журнал GitHub Actions, а у
# публичного репозитория он открыт всем: путей, домена и адресов здесь не
# печатаем — только версии, размеры, счётчики и коды ответов.
#
# Панель хостинга бывает убивает долгие процессы root (kill -9 по ps): tar,
# unzip и rsync повторяются до трёх раз. Наложение идёт без --delete и мимо
# того, что принадлежит установке: config/, storage/, .user.ini, .well-known/,
# cgi-bin/. Откат — последняя копия в каталоге копий:
#   tar -xzf <копия> -C <каталог над сайтом>
set -euo pipefail
umask 022

CONF=${SSO_DEPLOY_CONF:-/etc/sso-deploy.conf}
[ -r "$CONF" ] || { echo "нет настроек выкладки"; exit 2; }
# shellcheck source=/dev/null
. "$CONF"
: "${SITE_DIR:?не задан SITE_DIR}" "${BACKUP_DIR:?не задан BACKUP_DIR}" "${SITE_OWNER:?не задан SITE_OWNER}"
: "${PHP_BIN:?не задан PHP_BIN}" "${SITE_URL:?не задан SITE_URL}"
SITE_URL=${SITE_URL%/}
MAX_ZIP_BYTES=${MAX_ZIP_BYTES:-52428800}

ver() { grep -oE "(SSO|TSOY)_VERSION', '[0-9]+\.[0-9]+\.[0-9]+" "$1/app/bootstrap.php" 2>/dev/null | grep -oE "[0-9]+\.[0-9]+\.[0-9]+" | head -1; }
retry() {
  local what="$1"; shift
  for try in 1 2 3; do
    if "$@"; then return 0; fi
    echo "  $what оборвался (попытка $try)"; sleep 2
  done
  echo "$what не удался трижды — дальше не иду"; return 1
}
code() { curl -s -o /dev/null -m 10 -w "%{http_code}" "$1"; }

cmd=${SSH_ORIGINAL_COMMAND:-${1:-}}
case "$cmd" in
  check)
    echo "связь есть; на сайте $(ver "$SITE_DIR")"
    exit 0 ;;
  deploy\ *)
    WANT=${cmd#deploy }
    [[ "$WANT" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "версия выпуска — три числа через точку"; exit 2; } ;;
  *)
    echo "этим ключом можно только «check» и «deploy X.Y.Z»"; exit 2 ;;
esac

# Одна выкладка за раз: вторая, пришедшая поверх, ждала бы посреди rsync первой.
exec 9>/var/lock/sso-deploy.lock
flock -n 9 || { echo "уже идёт другая выкладка"; exit 3; }

WORK=$(mktemp -d /tmp/sso-deploy.XXXXXX)
trap 'rm -rf "$WORK"' EXIT
head -c "$MAX_ZIP_BYTES" > "$WORK/release.zip"
[ -s "$WORK/release.zip" ] || { echo "архив не пришёл"; exit 2; }
unzip -tq "$WORK/release.zip" >/dev/null 2>&1 || { echo "архив битый или оборван в пути"; exit 2; }
echo "== архив: $(du -h "$WORK/release.zip" | cut -f1)"

[ -f "$SITE_DIR/app/bootstrap.php" ] || { echo "в каталоге сайта нет установки"; exit 2; }
WAS=$(ver "$SITE_DIR")
echo "== было: $WAS"

# 1. Полная копия — вне корня сайта, чтобы не отдавалась наружу.
NAME=$(basename "$SITE_DIR")
TS=$(date -u +%Y%m%d-%H%M%S)
mkdir -p "$BACKUP_DIR"
retry "tar" tar -czf "$BACKUP_DIR/$NAME-$TS.tar.gz" -C "$(dirname "$SITE_DIR")" "$NAME"
echo "== копия: $(du -h "$BACKUP_DIR/$NAME-$TS.tar.gz" | cut -f1), метка $TS"

# 2. Распаковать рядом и убедиться, что это наша поставка нужной версии.
retry "unzip" unzip -oq "$WORK/release.zip" -d "$WORK/st"
GOT=$(ver "$WORK/st")
[ "$GOT" = "$WANT" ] || { echo "в архиве $GOT, а выкладываем $WANT — не тот архив"; exit 2; }
echo "== в архиве: $GOT"

# 3. Наложить поверх, не трогая то, что принадлежит установке.
retry "rsync" rsync -a \
  --exclude=/config/ --exclude=/storage/ --exclude=/.user.ini \
  --exclude=/.well-known/ --exclude=/cgi-bin/ \
  "$WORK/st"/ "$SITE_DIR"/
chown -R "$SITE_OWNER:$SITE_OWNER" "$SITE_DIR"
echo "== стало: $(ver "$SITE_DIR")"

# 4. Права: каталоги 755, файлы 644 — кроме того, что выровнено отдельно.
find "$SITE_DIR" \( -path "$SITE_DIR/config" -o -path "$SITE_DIR/storage" -o -path "$SITE_DIR/.well-known" -o -path "$SITE_DIR/cgi-bin" \) -prune \
  -o -type d -exec chmod 755 {} +
find "$SITE_DIR" \( -path "$SITE_DIR/config" -o -path "$SITE_DIR/storage" -o -path "$SITE_DIR/.well-known" -o -path "$SITE_DIR/cgi-bin" \) -prune \
  -o -type f -exec chmod 644 {} +
echo "== права: открыто на запись всем — $(find "$SITE_DIR" -perm -o+w | wc -l), не от владельца — $(find "$SITE_DIR" -not -user "$SITE_OWNER" | wc -l)"

# 5. Миграции: сначала статус; неприменённые — от владельца сайта.
if (cd "$SITE_DIR" && "$PHP_BIN" migrate.php --status) >"$WORK/migrate.txt" 2>&1; then
  echo "== миграции: $(tail -1 "$WORK/migrate.txt")"
else
  echo "== применяю миграции"
  su -s /bin/sh "$SITE_OWNER" -c "cd '$SITE_DIR' && '$PHP_BIN' migrate.php" 2>&1 | tail -3 | sed 's/^/  /'
fi

# 6. Живая установка.
FAIL=0
echo "== живая установка"
for u in /login /jwks.json /.well-known/openid-configuration; do
  c=$(code "$SITE_URL$u"); printf "  %-36s %s\n" "$u" "$c"
  [ "$c" = "200" ] || FAIL=1
done
KEYS=$(curl -s -m 10 "$SITE_URL/jwks.json" | "$PHP_BIN" -r '$j = json_decode(stream_get_contents(STDIN), true); echo count($j["keys"] ?? []);')
echo "  ключей подписи: $KEYS"; [ "${KEYS:-0}" -ge 1 ] || FAIL=1
TOP=$(curl -s -m 10 "$SITE_URL/about" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
echo "  верхний выпуск на «О проекте»: $TOP"; [ "$TOP" = "$WANT" ] || FAIL=1
echo "  form-action вне потока входа: $(curl -s -o /dev/null -D - -m 10 "$SITE_URL/login" | grep -i '^content-security-policy' | grep -o "form-action 'self'[^;]*" | sed -E "s#https?://[^ ;]+#<адрес>#g")"

# Вход в портал посреди потока (по желанию: PROBE_CLIENT_ID и PROBE_REDIRECT_URI
# в настройках). Проверочный код PKCE — одним процессом PHP: цепочку
# head|base64|openssl панель рвёт, и пустой code_challenge давал ложную тревогу.
if [ -n "${PROBE_CLIENT_ID:-}" ] && [ -n "${PROBE_REDIRECT_URI:-}" ]; then
  J="$WORK/jar"
  CH=$("$PHP_BIN" -r '$v = rtrim(strtr(base64_encode(random_bytes(48)), "+/", "-_"), "="); echo rtrim(strtr(base64_encode(hash("sha256", $v, true)), "+/", "-_"), "=");')
  RU=$("$PHP_BIN" -r 'echo rawurlencode($argv[1]);' "$PROBE_REDIRECT_URI")
  ORIGIN=$("$PHP_BIN" -r '$p = parse_url($argv[1]); echo $p["scheme"] . "://" . $p["host"] . (isset($p["port"]) ? ":" . $p["port"] : "");' "$PROBE_REDIRECT_URI")
  if [ ${#CH} -ne 43 ]; then
    echo "  проба портала: code_challenge не собрался (процесс оборван) — пропускаю"
  else
    Q="response_type=code&client_id=$PROBE_CLIENT_ID&redirect_uri=$RU&scope=openid&state=probe&nonce=probe-n&code_challenge=$CH&code_challenge_method=S256"
    L=$(curl -s -o /dev/null -c "$J" -b "$J" -m 10 -w "%{redirect_url}" "$SITE_URL/authorize?$Q")
    FA=$(curl -s -o /dev/null -D - -c "$J" -b "$J" -m 10 "$L" | grep -i '^content-security-policy' | grep -o 'form-action [^;]*' || true)
    case "$FA" in *"$ORIGIN"*) echo "  проба портала: на странице входа form-action пускает адрес возврата";;
      *) echo "  проба портала: form-action НЕ пускает адрес возврата"; FAIL=1;; esac
  fi
fi

# Выход без подсказки уводит на главную, а на чужой адрес — нет (до 4.60 вёл на /login).
E=$("$PHP_BIN" -r 'echo rawurlencode("https://evil.example/");')
R=$(curl -s -o /dev/null -m 10 -w "%{redirect_url}" "$SITE_URL/logout?post_logout_redirect_uri=$E")
echo "  выход на чужой адрес → ${R#"$SITE_URL"}"
case "$R" in "$SITE_URL"/*) ;; *) FAIL=1;; esac

if [ $FAIL -ne 0 ]; then
  echo "== ВНИМАНИЕ: живая установка отвечает не так, как ждали. Откат — копия с меткой $TS"
  exit 1
fi
echo "== готово: $WAS → $WANT"
