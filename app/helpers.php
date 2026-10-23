<?php
/**
 * Глобальные хелперы. Короткие, без состояния — всё тяжёлое в App\Core\*.
 */

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Setting;

/** Перевод строки: t('login.submit') или t('hello.name', ['name' => $n]). */
function t(string $key, array $params = []): string
{
    return App\Core\Lang::t($key, $params);
}

/** Текущий язык интерфейса. */
function lang(): string
{
    return App\Core\Lang::current();
}

/** Экранирование для HTML — единственный способ вывода данных в шаблонах. */
function esc(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** Настройка из БД (админка) с фоллбеком на файл-конфиг/дефолт. */
function setting(string $key, mixed $default = null): mixed
{
    return Setting::get($key, $default);
}

function is_https(): bool
{
    if (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') return true;
    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Базовый URL инсталляции (issuer). */
function base_url(string $path = ''): string
{
    $base = rtrim((string)config('app.url', ''), '/');
    if ($base === '') {
        $scheme = is_https() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = "$scheme://$host";
    }
    return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

/**
 * Кусок файла из заголовка Range: [начало, конец] включительно, null — отдать
 * целиком, false — просят то, чего нет (416).
 *
 * Только один кусок «bytes=a-b», «bytes=a-» или «bytes=-n»: видео в браузере
 * других не просит, а несколько кусков — это multipart-ответ, которого нам не
 * нужно (v4.81, видео на обложке: без Range Safari его не играет).
 *
 * @return array{0: int, 1: int}|null|false
 */
function http_range(string $header, int $size): array|null|false
{
    if (!preg_match('~^bytes=(\d*)-(\d*)$~', trim($header), $m) || ($m[1] === '' && $m[2] === '')) return null;
    if ($size <= 0) return false;
    if ($m[1] === '') {
        $n = (int)$m[2];
        if ($n === 0) return false;
        return [max(0, $size - $n), $size - 1];
    }
    $a = (int)$m[1];
    $b = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
    return $a <= $b && $a < $size ? [$a, $b] : false;
}

/** Отдать файл с диска — целиком или куском по Range. */
function send_file(string $path, string $type, string $cache): never
{
    $size = (int)filesize($path);
    $range = http_range((string)($_SERVER['HTTP_RANGE'] ?? ''), $size);
    header('Content-Type: ' . $type);
    header('Cache-Control: ' . $cache);
    header('Accept-Ranges: bytes');
    if ($range === false) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    [$a, $b] = $range ?? [0, $size - 1];
    if ($range !== null) {
        http_response_code(206);
        header("Content-Range: bytes $a-$b/$size");
    }
    header('Content-Length: ' . (string)max(0, $b - $a + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $size === 0) exit;
    /* Видео до 40 МБ не должно копиться в буфере вывода целиком. */
    while (ob_get_level() > 0) ob_end_clean();
    $fh = fopen($path, 'rb');
    if ($fh === false) exit;
    fseek($fh, $a);
    $left = $b - $a + 1;
    while ($left > 0 && !feof($fh)) {
        $chunk = (string)fread($fh, min(1 << 16, $left));
        if ($chunk === '') break;
        echo $chunk;
        $left -= strlen($chunk);
    }
    fclose($fh);
    exit;
}

/**
 * Имя сайта для глаз (v4.81): кириллический домен — буквами, а не punycode.
 * На витрине боевой карточка портала на .рф показывала
 * «xn--80aadkevhbkvnxnq8km.xn--p1ai» и ужимала само название до «К…».
 * Без intl — как есть.
 */
function idn_label(string $host): string
{
    if (!str_contains(strtolower($host), 'xn--') || !function_exists('idn_to_utf8')) return $host;
    $u = idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
    return is_string($u) && $u !== '' ? $u : $host;
}

/** Хост адреса для глаз. */
function host_label(string $url): string
{
    return idn_label((string)parse_url($url, PHP_URL_HOST));
}

/** Адрес целиком для глаз: хост — буквами, остальное как есть. */
function url_label(string $url): string
{
    $h = (string)parse_url($url, PHP_URL_HOST);
    if ($h === '') return $url;
    return (string)preg_replace('~^([a-z][a-z0-9+.-]*://(?:[^@/]*@)?)' . preg_quote($h, '~') . '~i', '${1}' . idn_label($h), $url, 1);
}

function redirect(string $to, int $code = 302): never
{
    if (!preg_match('~^https?://~i', $to)) $to = base_url($to);
    header('Location: ' . $to, true, $code);
    exit;
}

/**
 * «Вернуть туда, где человек стоял».
 *
 * Нужно там, где форма живёт не на одной странице, а во всём макете: полоса
 * «применить миграции» висит в шапке любого раздела админки. Обработчик знал
 * только собственный адрес и уводил на него — нажал в списке пользователей,
 * очнулся в инструментах. На ошибке это особенно обидно: человек теряет и
 * результат, и место.
 *
 * Адрес приезжает скрытым полем, а не из Referer: заголовок режут и браузеры,
 * и прокси, а поле мы ставим сами и знаем, что оно там есть.
 */
function back_field(): string
{
    $path = (string)($_SERVER['REQUEST_URI'] ?? '/');
    return '<input type="hidden" name="_back" value="' . esc($path) . '">';
}

/**
 * Проверенный адрес возврата или запасной.
 *
 * Поле приходит из браузера, то есть подделывается: без проверки это открытый
 * редирект на чужой сайт с нашего домена. Пускаем только путь на себя —
 * начинается со `/`, но не с `//` и не с `/\`, иначе браузер прочтёт это как
 * `//чужой.сайт` и уйдёт туда.
 */
function back_to(string $fallback): string
{
    return local_path((string)($_POST['_back'] ?? ''), $fallback);
}

/**
 * Вернуться на страницу, с которой пришли, — по Referer, но только на себя.
 *
 * Берём из заголовка путь с запросом, хост отбрасываем: уходим всё равно на
 * свой (v4.70). Раньше переключение языка брало путь без проверки — и
 * `https://чужой//evil.example` уводил на //evil.example, — а смена области
 * в админке сверяла начало строки с base_url(), под которое подходит и
 * `https://sso.example.com.evil.example`.
 */
function referer_path(string $fallback): string
{
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $path = (string)parse_url($ref, PHP_URL_PATH);
    $query = (string)parse_url($ref, PHP_URL_QUERY);
    return local_path($path . ($query !== '' ? '?' . $query : ''), $fallback);
}

/**
 * Путь на себя — или запасной. Одна проверка на всё, что уводит человека
 * по адресу из браузера или из сессии (v4.70).
 *
 * Их было три, и они разошлись: back_to() знала про `/\`, возврат после входа
 * — нет, а выдача доступа из карточки брала поле `back` как есть, и абсолютный
 * адрес уходил в Location — открытый редирект с нашего домена.
 */
function local_path(string $to, string $fallback): string
{
    if ($to === '' || strlen($to) > 400) return $fallback;
    if (!str_starts_with($to, '/') || str_starts_with($to, '//') || str_starts_with($to, '/\\')) {
        return $fallback;
    }
    /* Любой управляющий символ, а не только перевод строки: табуляцию и
       переводы браузер из адреса вырезает молча, и `/<TAB>/чужой.сайт`
       превращается у него в `//чужой.сайт`. */
    if (preg_match('~[\x00-\x1F\x7F]~', $to)) return $fallback;
    return $to;
}

function json_response(mixed $data, int $code = 200, array $headers = []): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $k => $v) header("$k: $v");
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Текущее время UTC для БД. Всё в базе — UTC, конвертация только при выводе. */
function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function now_plus(int $seconds): string
{
    return gmdate('Y-m-d H:i:s', time() + $seconds);
}

/**
 * Пояса для выбора: «GMT−12 … GMT+14». Значения — IANA Etc/GMT∓N
 * (у IANA знак инвертирован: Etc/GMT-5 = UTC+5 — здесь это уже учтено).
 */
function tz_options(): array
{
    static $opts = null;
    if ($opts === null) {
        $opts = [];
        for ($n = -12; $n <= 14; $n++) {
            $id = $n === 0 ? 'UTC' : 'Etc/GMT' . ($n > 0 ? '-' . $n : '+' . abs($n));
            $label = $n === 0 ? 'GMT±0' : 'GMT' . ($n > 0 ? '+' : '−') . abs($n);
            $opts[$id] = $label;
        }
    }
    return $opts;
}

/** «GMT+3» для любой зоны (и городской, и Etc). */
function tz_gmt_label(?string $tz): string
{
    if (!$tz) return '—';
    try {
        $offset = (new DateTimeZone($tz))->getOffset(new DateTime('now', new DateTimeZone('UTC')));
    } catch (Throwable) {
        return $tz;
    }
    $h = intdiv(abs($offset), 3600);
    $m = intdiv(abs($offset) % 3600, 60);
    if ($offset === 0) return 'GMT±0';
    return 'GMT' . ($offset > 0 ? '+' : '−') . $h . ($m ? ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) : '');
}

/** UTC-строка из БД → строка в таймзоне пользователя/инсталляции. */
function fmt_dt(?string $utc, string $format = 'd.m.Y H:i'): string
{
    if (!$utc) return '—';
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        /* Пояс установки — настройка, а не только строка в файле (v4.15). */
        $dt->setTimezone(new DateTimeZone(App\Core\Auth::user()['timezone'] ?? App\Core\Setting::timezone()));
        return $dt->format($format);
    } catch (Throwable) {
        return $utc;
    }
}

/** «5 минут назад» для лент. */
function fmt_ago(?string $utc): string
{
    if (!$utc) return '—';
    $diff = time() - strtotime($utc . ' UTC');
    /* Через t(): относительное время встречается на каждой странице списков,
       и без перевода английская админка спотыкалась об «5 мин назад». */
    if ($diff < 60) return t('только что');
    if ($diff < 3600) return t(':n мин назад', ['n' => floor($diff / 60)]);
    if ($diff < 86400) return t(':n ч назад', ['n' => floor($diff / 3600)]);
    /* Дни — словом, а не «1 дн назад» (v4.90): «дн» не сокращение, которое
       читают, а обрубок; минуты и часы сокращаются привычно, дни — нет. */
    if ($diff < 86400 * 2) return t('вчера');
    if ($diff < 86400 * 30) {
        $d = (int)floor($diff / 86400);
        return t(':n :days назад', ['n' => $d, 'days' => plural($d, 'день', 'дня', 'дней')]);
    }
    return fmt_dt($utc, 'd.m.Y');
}

function request_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** IP → VARBINARY для БД. */
function ip_bin(?string $ip = null): ?string
{
    $ip = $ip ?? request_ip();
    $bin = @inet_pton($ip);
    return $bin === false ? null : $bin;
}

/**
 * Адрес ссылкой на его страницу в админке (v4.72): кто с него приходил, что
 * делал, заблокирован ли. Не администратору — просто текстом.
 */
function ip_link(?string $bin): string
{
    $ip = ip_str($bin);
    if ($ip === '—' || !App\Core\Auth::isAdmin()) return esc($ip);
    return '<a class="mono" href="/admin/ip/' . esc(rawurlencode($ip)) . '">' . esc($ip) . '</a>';
}

function ip_str(?string $bin): string
{
    if (!$bin) return '—';
    $ip = @inet_ntop($bin);
    return $ip === false ? '—' : $ip;
}

/**
 * Замаскированный email: a***v@ya***.ru. Показывается на экране слияния —
 * человек должен узнать свой адрес, а случайный зритель не должен его прочесть.
 */
function mask_email(string $email): string
{
    if (!str_contains($email, '@')) return '***';
    [$local, $domain] = explode('@', $email, 2);
    $l = mb_strlen($local) <= 2 ? mb_substr($local, 0, 1) . '*'
        : mb_substr($local, 0, 1) . '***' . mb_substr($local, -1);
    $dot = strrpos($domain, '.');
    $tld = $dot !== false ? substr($domain, $dot) : '';
    $host = $dot !== false ? substr($domain, 0, $dot) : $domain;
    return $l . '@' . mb_substr($host, 0, 2) . '***' . $tld;
}

/** Частично замаскированный IP для показа пользователю. */
/**
 * IP для показа самому владельцу аккаунта.
 *
 * Маска бережёт от взгляда через плечо и от скриншота, отправленного в чат.
 * В админке маски не было никогда — там `ip_str()`, иначе инцидент не
 * разберёшь. Настройкой её можно снять: есть установки, где адрес свой же
 * истории хотят видеть целиком.
 */
function ip_masked(?string $bin): string
{
    $ip = ip_str($bin);
    if ($ip === '—') return $ip;
    if (\App\Core\Setting::get('policy.mask_own_ip', '1') !== '1') return $ip;
    if (str_contains($ip, '.')) {
        $p = explode('.', $ip);
        return "$p[0].$p[1].✻.✻";
    }
    $p = explode(':', $ip);
    return implode(':', array_slice($p, 0, 2)) . ':…';
}

function request_ua(): string
{
    return mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
}

/** 'Chrome · Windows' из user-agent — без библиотек, по-честному приблизительно. */
function device_label(?string $ua = null): string
{
    $ua = $ua ?? request_ua();
    $browser = 'Браузер';
    foreach (['Firefox' => 'Firefox', 'Edg' => 'Edge', 'OPR' => 'Opera', 'YaBrowser' => 'Яндекс Браузер',
              'SamsungBrowser' => 'Samsung Browser', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $needle => $name) {
        if (str_contains($ua, $needle)) { $browser = $name; break; }
    }
    $os = '';
    foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad',
              'Mac OS' => 'macOS', 'Linux' => 'Linux'] as $needle => $name) {
        if (str_contains($ua, $needle)) { $os = $name; break; }
    }
    return $os ? "$browser · $os" : $browser;
}

/**
 * Подпись устройства — на языке экрана (v4.74.1).
 *
 * Сам device_label() остаётся одинаковым на любом языке: из него собирается
 * отпечаток «знакомого устройства», он лежит в сессиях и уходит порталам в
 * вебхуке. Переведи его на месте — смена языка интерфейса выглядела бы как
 * вход с нового устройства, и человек получал бы ложное письмо. Поэтому
 * переводим только при показе: «Яндекс Браузер · Windows» → «Yandex Browser
 * · Windows». Название браузера — единственное русское в этой строке.
 */
function device_text(string $label): string
{
    return strtr($label, ['Яндекс Браузер' => t('Яндекс Браузер'), 'Браузер' => t('Браузер')]);
}

function is_mobile_ua(?string $ua = null): bool
{
    $ua = $ua ?? request_ua();
    return (bool)preg_match('~Mobi|Android|iPhone|iPad~i', $ua);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . esc(Csrf::token()) . '">';
}

/** Nonce для CSP: script-src 'self' 'nonce-…'. */
function csp_nonce(): string
{
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(12));
}

/**
 * Условие поиска пользователя по свободной строке: одно на список админки и на
 * палитру Ctrl+K. Раньше эти два места держали одинаковый SQL копией, и любое
 * новое поле искалось ровно в одном из них.
 *
 * @return array{string, list<string>} фрагмент WHERE и параметры к нему
 */
function user_search_clause(string $q): array
{
    $like = '%' . $q . '%';
    $parts = ['u.email LIKE ?', 'u.login LIKE ?', 'u.public_id = ?',
              'u.last_name LIKE ?', 'u.first_name LIKE ?', 'u.display_name LIKE ?'];
    $params = [$like, $like, $q, $like, $like, $like];

    /* Номер аккаунта ищем только при точном совпадении: это идентификатор, а не
       текст, и LIKE по нему выдавал бы случайных однофамильцев по цифрам.
       Ввод нормализуется, поэтому находится и «4827-13905», и «482713905».
       Колонки может не быть — схема отстаёт от кода чаще, чем хотелось бы. */
    if (App\Core\DB::columnExists('users', 'account_number')) {
        $number = App\Core\AccountNumber::normalize($q);
        if ($number !== null) {
            $parts[] = 'u.account_number = ?';
            $params[] = $number;
        }
    }
    return ['(' . implode(' OR ', $parts) . ')', $params];
}

/** Имя пользователя для показа: display_name → Имя Фамилия → login → email. */
function user_name(array $u): string
{
    /* На нерусском интерфейсе показываем латинское написание, если человек его
       указал: иначе английская админка пестрит кириллицей в каждой строке. */
    if (lang() !== 'ru') {
        $latin = trim(((string)($u['latin_first_name'] ?? '')) . ' ' . ((string)($u['latin_last_name'] ?? '')));
        if ($latin !== '') return $latin;
    }
    if (!empty($u['display_name'])) return $u['display_name'];
    $fio = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    if ($fio !== '') return $fio;
    /* Все прочие ключи читаются с ?? — этот один был без защиты, и функция
       падала на строке, где выбраны не все колонки. Ловилось это только
       вживую: у человека с именем до `login` дело не доходит вовсе, поэтому
       узкий SELECT рядом с ней годами выглядел безобидным. */
    $fallback = ($u['login'] ?? '') ?: ($u['email'] ?? '');
    if ($fallback !== '') return (string)$fallback;
    /* Совсем безымянный: ни имени, ни логина, ни почты. Такого человека заводит
       только перенос из портала, где людей звали одним номером. Пустая строка
       вместо имени даёт в списке пустую ссылку, на которую нечем нажать. */
    if (!empty($u['account_number'])) return '№ ' . $u['account_number'];
    return t('Без имени');
}

/**
 * Чем человек назван в системе: почта → @логин → номер аккаунта.
 *
 * Строчкой под именем везде стояла почта, и это работало, пока почта была у
 * каждого. С приходом людей из портала, где регистрация шла логином и паролём,
 * половина строк опустела бы: пустая подпись под именем читается как «данные
 * потерялись», хотя терять было нечего. Логин со «собакой» отличим от адреса с
 * одного взгляда, поэтому пояснять словами ничего не нужно.
 */
function user_handle(array $u): string
{
    if (!empty($u['email'])) return (string)$u['email'];
    if (!empty($u['login'])) return '@' . $u['login'];
    if (!empty($u['account_number'])) return '№ ' . $u['account_number'];
    return '—';
}

/**
 * «Имя · контакт» одной строкой, без повтора.
 *
 * У человека без имени `user_name()` возвращает почту — ту же, что и
 * `user_handle()`. Написанные рядом, они давали «admin@test.local ·
 * admin@test.local»: одно и то же дважды через точку. Выглядит как ошибка
 * данных, хотя данные в порядке.
 */
function user_title(array $u): string
{
    $name = user_name($u);
    $handle = user_sub($u);
    return $handle === '' ? $name : $name . ' · ' . $handle;
}

/**
 * Вторая строка под именем — или пусто, если она повторила бы первую.
 *
 * Нужна там, где имя и контакт стоят друг под другом: список людей, состав
 * организации, заявки на портал. У человека без имени `user_name()` возвращает
 * почту, и обе строки показывали один и тот же адрес — выглядит как ошибка
 * данных, хотя данные в порядке.
 */
/**
 * Событие аудита на экране: название словами, машинное имя — подсказкой
 * (v4.70). Одна разметка для дашборда и журнала: раньше оба рисовали машинное
 * имя моноширинной плашкой и порознь решали, какое событие красное.
 */
function event_label(string $event, bool $withCode = false): string
{
    $title = \App\Core\Audit::title($event);
    $known = $title !== $event;
    $cls = 'event-label' . (\App\Core\Audit::alarming($event) ? ' is-alarm' : '') . ($known ? '' : ' mono');
    $out = '<span class="' . $cls . '" title="' . esc($event) . '">' . esc($title) . '</span>';
    if ($withCode && $known) $out .= '<div class="hint mono">' . esc($event) . '</div>';
    return $out;
}

function user_sub(array $u): string
{
    $name = user_name($u);
    $handle = user_handle($u);
    if ($handle === '—' || $handle === $name || '@' . ltrim($name, '@') === $handle) return '';
    return $handle;
}

/** Инициалы для дефолтного аватара. */
function user_initials(array $u): string
{
    $name = user_name($u);
    $parts = preg_split('~\s+~u', trim($name), 2);
    $ini = mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1);
    return mb_strtoupper($ini !== '' ? $ini : mb_substr($name, 0, 1));
}

/** Детерминированный градиент от hash(sub) — для аватаров-инициалов. */
function avatar_gradient(string $sub): string
{
    $h = crc32($sub);
    $hue1 = $h % 360;
    $hue2 = ($hue1 + 40) % 360;
    /* Светлота 32/26%, а не 42/30%: инициалы белые, а на жёлтых тонах (hue ~60)
       светлота 42% давала контраст 2.94 при норме 4.5 — буквы терялись. */
    return "linear-gradient(135deg, hsl($hue1 45% 32%), hsl($hue2 50% 26%))";
}

/** Абсолютный URL аватара (или null → рисуем инициалы). */
function avatar_url(array $u, int $size = 128): ?string
{
    if (empty($u['avatar_path'])) return null;
    return base_url('/avatar/' . $u['public_id'] . '/' . $size . '.webp?v=' . substr(md5($u['avatar_path']), 0, 6));
}

/**
 * Склонение: plural(5, 'пользователь', 'пользователя', 'пользователей').
 *
 * Не на русском — два числа вместо трёх и слово через словарь (v4.74). До
 * этого plural() отдавал русское слово всегда, и английская панель писала
 * «5 ключей», «3 пользователя» — одиннадцать мест, а сторож перевода их не
 * видел: слово приходило не из t('…'). Английский «one» — только единица,
 * поэтому 21 — «accounts», а не «account», как было бы через русскую форму.
 */
function plural(int $n, string $one, string $few, string $many): string
{
    /* «человек» — и одна форма, и «много»: тогда множественное берём из «few». */
    if (lang() !== 'ru') return t(abs($n) === 1 ? $one : ($many !== $one ? $many : $few));
    $n = abs($n) % 100;
    $n1 = $n % 10;
    if ($n > 10 && $n < 20) return $many;
    if ($n1 > 1 && $n1 < 5) return $few;
    if ($n1 === 1) return $one;
    return $many;
}

/**
 * Секунды словами: «3 дня», «1 час», «15 минут».
 *
 * Нужно там, где срок ЧИТАЕТ человек, а не задаёт: письмо со ссылкой обязано
 * называть настоящий срок. До этого он был вписан в текст письма руками —
 * «Ссылка действует 3 дня», — а сам срок настраивается и может быть любым от
 * пятнадцати минут. Сократив его, владелец получал письмо, которое обещает три
 * дня, и человека, у которого ссылка протухла через час.
 *
 * Берём самую крупную единицу, на которую срок делится нацело: 3600 — «1 час»,
 * а не «60 минут». Не делится — округляем и говорим «около».
 */
function fmt_duration(int $sec): string
{
    $units = [
        [86400, 'день', 'дня', 'дней'],
        [3600, 'час', 'часа', 'часов'],
        [60, 'минута', 'минуты', 'минут'],
    ];
    foreach ($units as [$unit, $one, $few, $many]) {
        if ($sec >= $unit && $sec % $unit === 0) {
            $n = intdiv($sec, $unit);
            return $n . ' ' . t(plural($n, $one, $few, $many));
        }
    }
    foreach ($units as [$unit, $one, $few, $many]) {
        if ($sec >= $unit) {
            $n = (int)round($sec / $unit);
            return t('около') . ' ' . $n . ' ' . t(plural($n, $one, $few, $many));
        }
    }
    return $sec . ' ' . t(plural($sec, 'секунда', 'секунды', 'секунд'));
}

/**
 * Кнопка в письме.
 *
 * Разметка одна и та же в шести письмах и во всех шести набрана заново — со
 * своим цветом, вписанным числом. Акцент установки при этом настраивается, и
 * письма о нём не знали: интерфейс перекрашивался, письма оставались синими.
 * Инлайн-стили здесь не небрежность: почтовые клиенты вырезают <style>.
 */
function mail_button(string $url, string $label): string
{
    $ink = App\Core\Palette::report((string)App\Core\Setting::get('branding.accent', '') ?: '#1D4ED8');
    return '<p style="text-align:center;margin:24px 0">'
        . '<a href="' . esc($url) . '" style="background:' . esc($ink['ink'])
        . ';color:' . esc($ink['on_ink'])
        . ';padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block">'
        . esc($label) . '</a></p>';
}

/** Аватар: фото или инициалы на детерминированном градиенте. */
/**
 * Размеры аватара, у которых есть правило в CSS.
 *
 * Класс `.avatar-N` для N вне этого списка не существует, а значит размер не
 * задан вовсе: `.avatar img` растянут на 100% родителя, родитель без ширины —
 * и фотография рисуется натуральной величиной, на пол-экрана. Так и вышло:
 * четыре новых списка людей просили 28, экран блокировки — 56, и ни одного
 * такого правила в CSS не было.
 */
const AVATAR_SIZES = [24, 32, 48, 96];

function avatar_html(array $u, int $size = 32): string
{
    /* Просят произвольное число — берём ближайшее из существующих, а не
       выдаём класс, которого нет. Ошибка вёрстки не должна зависеть от того,
       вспомнил ли автор шаблона список размеров. */
    if (!in_array($size, AVATAR_SIZES, true)) {
        $best = AVATAR_SIZES[0];
        foreach (AVATAR_SIZES as $s) {
            if (abs($s - $size) < abs($best - $size)) $best = $s;
        }
        $size = $best;
    }
    $cls = "avatar avatar-$size";
    $url = avatar_url($u, $size >= 96 ? 512 : 128);
    if ($url !== null) {
        return '<span class="' . $cls . '"><img src="' . esc($url) . '" alt=""></span>';
    }
    /* Инициалы — тоже картинка: рядом всегда стоит имя, и озвучивать «А Ц»
       перед «Артём Петров» незачем. У варианта со снимком alt="" стоит с самого
       начала; здесь пометки не было (v4.47). */
    return '<span class="' . $cls . '" aria-hidden="true" style="background:' . esc(avatar_gradient($u['public_id'] ?? '')) . '">'
        . esc(user_initials($u)) . '</span>';
}

/** URL ассета с версией от mtime — обновления CSS/JS не застревают в кэше браузера. */
function asset(string $path): string
{
    static $cache = [];
    if (!isset($cache[$path])) {
        $file = SSO_ROOT . '/public/' . ltrim($path, '/');
        $v = is_file($file) ? (string)filemtime($file) : SSO_VERSION;
        $cache[$path] = '/' . ltrim($path, '/') . '?v=' . $v;
    }
    return $cache[$path];
}

/**
 * Иконка из спрайта public/assets/icons.svg. Наследует currentColor и тему.
 * Эмодзи оставляем только там, где они контент (письма), не элемент управления.
 */
function icon(string $name, int $size = 20, string $class = ''): string
{
    return '<svg class="icon' . ($class !== '' ? ' ' . esc($class) : '') . '"'
        . ' width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">'
        . '<use href="' . esc(asset('assets/icons.svg')) . '#i-' . esc($name) . '"></use></svg>';
}

/** Иконка статуса ok/warn/fail — единый язык для health, инсталлятора, статусов. */
function status_icon(string $status, int $size = 20): string
{
    return match ($status) {
        'ok' => icon('check-circle', $size, 'icon-ok'),
        'warn' => icon('alert-triangle', $size, 'icon-warn'),
        default => icon('x-circle', $size, 'icon-danger'),
    };
}

/** Логотип инсталляции: загруженный файл или «● Название». */
function branding_logo_html(string $classes = ''): string
{
    $name = App\Core\Setting::appName();
    $v = (string)setting('branding.logo_v', '');
    if ($v !== '' && is_file(SSO_ROOT . '/storage/avatars/branding/logo.webp')) {
        return '<img src="' . esc(base_url('/branding/logo.webp?v=' . $v)) . '" alt="' . esc($name)
            . '" style="height:26px;width:auto;display:block"' . ($classes ? ' class="' . esc($classes) . '"' : '') . '>';
    }
    return '<span class="logo-mark">●</span> ' . esc($name);
}

/**
 * Акцент установки. Пустая строка — дефолт из tokens.css.
 * Один источник правды: отсюда берут и <style>, и theme-color, и favicon.
 */
function branding_accent(): string
{
    return trim((string)App\Core\Setting::get('branding.accent'));
}

/**
 * Инлайн-стиль с производными акцента для обеих тем.
 * Считается на сервере, потому что подбор читаемого текста поверх акцента —
 * это арифметика по WCAG, а не догадка (см. App\Core\Palette).
 */
function branding_accent_style(): string
{
    $accent = branding_accent();
    if ($accent === '') return '';
    return '<style>' . App\Core\Palette::css($accent) . '</style>';
}

/** Цвет строки состояния мобильного браузера — тоже по акценту, а не константой. */
function branding_theme_color(): string
{
    $accent = branding_accent();
    return $accent !== '' ? App\Core\Palette::normalize($accent) : '#1D4ED8';
}

/**
 * Путь к загруженному favicon или null.
 *
 * Проверяются и настройка, и файл: настройка — признак того, что картинку
 * действительно загрузили через админку, файл — что она не потерялась при
 * переносе. Одного признака мало, поэтому источник правды здесь один и им
 * пользуются и <link rel=icon>, и иконки PWA.
 */
function branding_favicon_file(): ?string
{
    $v = (string)setting('branding.favicon_v', '');
    $path = SSO_ROOT . '/storage/avatars/branding/favicon.png';
    return ($v !== '' && is_file($path)) ? $path : null;
}

/** href favicon'а: загруженный или дефолтный inline-SVG в цвете акцента. */
function branding_favicon_href(): string
{
    if (branding_favicon_file() !== null) {
        return base_url('/branding/favicon.png?v=' . (string)setting('branding.favicon_v', ''));
    }
    /* «#» в data-URI начал бы фрагмент и обрезал бы картинку — только %23. */
    $accent = branding_theme_color();
    $fill = '%23' . ltrim($accent, '#');
    $text = '%23' . ltrim(App\Core\Palette::derive($accent)['on_ink'], '#');
    return "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'>"
        . "<rect width='100' height='100' rx='22' fill='$fill'/>"
        . "<text x='50' y='68' font-size='52' text-anchor='middle' fill='$text'"
        . " font-family='sans-serif' font-weight='600'>ID</text></svg>";
}

/**
 * Подпись под иконкой на домашнем экране. Больше 12 знаков система обрежет
 * сама, поэтому режем по границе слова: «Комбинат Заря (dev)» станет «Комбинат Заря», а не
 * «Комбинат Заря (dev» с оборванной скобкой.
 */
function pwa_short_name(string $name): string
{
    $name = trim($name);
    if (mb_strlen($name) <= 12) return $name;
    $out = '';
    foreach (preg_split('~\s+~u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
        $next = $out === '' ? $word : $out . ' ' . $word;
        if (mb_strlen($next) > 12) break;
        $out = $next;
    }
    /* Первое слово само длиннее лимита — режем как есть, иначе подписи не будет */
    return $out !== '' ? $out : mb_substr($name, 0, 12);
}

/**
 * Мета для установки на домашний экран. Один вызов на все layout'ы, иначе
 * набор тегов расползётся по трём файлам и разойдётся.
 *
 * apple-touch-icon отдельной строкой: Safari не читает манифест и берёт иконку
 * только оттуда. Оба -capable указаны потому, что Apple свой вариант не
 * переименовала, а стандарт назвал тег иначе.
 */
function pwa_head(): string
{
    return '<link rel="manifest" href="/manifest.webmanifest">'
        . '<link rel="apple-touch-icon" href="/pwa/icon-192.png">'
        . '<meta name="mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-status-bar-style" content="default">'
        . '<meta name="apple-mobile-web-app-title" content="'
        . esc(pwa_short_name(App\Core\Setting::appName())) . '">';
}

/**
 * Ссылки на включённые правовые документы — для подвала.
 * Выключенных здесь нет: их страницы отдают 404, и ссылка вела бы в никуда.
 *
 * @return array<string, string> заголовок => адрес
 */
function legal_links(): array
{
    $out = [];
    foreach (array_keys(App\Core\Legal::DOCS) as $key) {
        if (App\Core\Legal::enabled($key)) $out[App\Core\Legal::title($key)] = "/legal/$key";
    }
    return $out;
}
/** Текст подвала: настройка или дефолт. */
function branding_footer(): string
{
    $custom = trim((string)setting('branding.footer', ''));
    return $custom !== '' ? $custom
        : App\Core\Setting::appName() . ' · ' . t('common.footer_tagline');
}

/** Иконка портала: загруженная картинка или буква на градиенте от слага. */
/**
 * Верх карточки портала на витрине (v4.76): его картинка, а без неё — его
 * цвет и знак. Раньше значок 64×64 растягивался на всю карточку, а без
 * значка оставалась штриховка. Откуда картинка — App\Core\PortalCover.
 */
function portal_card_shot(array $client, string $class = 'pcard-shot'): string
{
    $url = \App\Core\PortalCover::url($client);
    if ($url !== null) {
        return '<div class="' . esc($class) . '"><img src="' . esc($url) . '" alt="" loading="lazy"></div>';
    }
    $mark = !empty($client['icon_path'])
        ? '<img src="' . esc(base_url('/client-icon/' . (int)$client['id'] . '?v=' . substr(md5((string)$client['icon_path']), 0, 6))) . '" alt="">'
        : esc(mb_strtoupper(mb_substr((string)($client['name'] ?? '?'), 0, 1)));
    return '<div class="' . esc($class) . ' pcard-poster" style="--pc:' . esc(\App\Core\PortalCover::tint($client)) . '">'
        . '<span class="pcard-mark">' . $mark . '</span></div>';
}

function client_icon_html(array $client, int $size = 24): string
{
    $style = "width:{$size}px;height:{$size}px;border-radius:" . max(6, (int)($size / 4)) . 'px';
    if (!empty($client['icon_path'])) {
        return '<img src="' . esc(base_url('/client-icon/' . (int)$client['id'] . '?v=' . substr(md5($client['icon_path']), 0, 6)))
            . '" alt="" style="' . $style . ';object-fit:cover" loading="lazy">';
    }
    /* Буква — картинка, а не текст (v4.47). У варианта со снимком стоит
       alt="", то есть «декоративно, не озвучивать», а у запасного варианта
       такой пометки не было — и первая буква названия зачитывалась вслух
       вместе с ним. На экране согласия заголовок звучал «Т Вход в Тестовый
       портал»: имя сервиса рядом, буква ничего не добавляет. */
    $letter = mb_strtoupper(mb_substr($client['name'] ?? '?', 0, 1));
    return '<span aria-hidden="true" style="' . $style . ';display:inline-flex;align-items:center;justify-content:center;flex:none;'
        . 'background:' . esc(avatar_gradient($client['slug'] ?? '')) . ';color:#fff;font-weight:600;'
        . 'font-size:' . (int)($size * 0.5) . 'px">' . esc($letter) . '</span>';
}

/**
 * Знак организации (v4.90) — в той же форме, что знак портала: скруглённый
 * квадрат с цветом от слага и двумя буквами названия («Комбинат Заря» → «КЗ»).
 * Раньше в списке у организаций знака не было вовсе, а в карточке был бледный
 * круг — один и тот же объект выглядел двумя разными.
 */
function org_icon_html(array $org, int $size = 24): string
{
    $name = trim((string)($org['name'] ?? ''));
    $words = preg_split('~[\s·—«»"-]+~u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $letters = count($words) >= 2 ? mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1) : mb_substr($name, 0, 2);
    $style = "width:{$size}px;height:{$size}px;border-radius:" . max(6, (int)($size / 4)) . 'px';
    return '<span aria-hidden="true" style="' . $style . ';display:inline-flex;align-items:center;justify-content:center;flex:none;'
        . 'background:' . esc(avatar_gradient('org:' . ($org['slug'] ?? $name))) . ';color:#fff;font-weight:600;letter-spacing:-.02em;'
        . 'font-size:' . (int)($size * 0.4) . 'px">' . esc(mb_strtoupper($letters !== '' ? $letters : '?')) . '</span>';
}

/** Доступ человека к порталу — одной плашкой везде (v4.90): в карточке человека стоял голый «active». */
function access_badge(string $status): string
{
    [$cls, $label] = match ($status) {
        'active' => ['badge-ok', t('открыт')],
        'pending' => ['badge-warn', t('заявка')],
        'blocked' => ['badge-danger', t('закрыт')],
        default => ['badge-muted', $status],
    };
    return '<span class="badge ' . $cls . '">' . esc($label) . '</span>';
}

/** Письмо в очереди — одной плашкой (v4.90): в журнале почты стоял голый «sent». */
function mail_status_badge(string $status): string
{
    [$cls, $label] = match ($status) {
        'sent' => ['badge-ok', t('отправлено')],
        'failed' => ['badge-danger', t('не ушло')],
        'pending' => ['badge-warn', t('в очереди')],
        default => ['badge-muted', $status],
    };
    return '<span class="badge ' . $cls . '">' . esc($label) . '</span>';
}

/** Дата без таймзонной конвертации (ДР и прочие «чистые» даты). */
function fmt_date(?string $date, string $format = 'd.m.Y'): string
{
    if (!$date) return '—';
    $ts = strtotime($date);
    return $ts === false ? $date : date($format, $ts);
}

/**
 * Callout: info | success | warning | danger. Единый компонент вместо
 * россыпи secret-plate и самодельных плашек.
 */
function callout(string $text, string $type = 'info', bool $raw = false): string
{
    $icons = ['info' => 'info', 'success' => 'check-circle', 'warning' => 'alert-triangle', 'danger' => 'alert-triangle'];
    $colors = ['info' => 'icon-ink', 'success' => 'icon-ok', 'warning' => 'icon-warn', 'danger' => 'icon-danger'];
    $type = isset($icons[$type]) ? $type : 'info';
    return '<div class="callout callout-' . $type . '">'
        . icon($icons[$type], 17, $colors[$type])
        . '<div>' . ($raw ? $text : esc($text)) . '</div></div>';
}

/** KeyValue: ['Логин' => '@ivan', 'sub' => '…'] → аккуратный список пар. */
function kv_list(array $pairs, array $monoKeys = []): string
{
    $out = '<dl class="kv-list">';
    foreach ($pairs as $k => $v) {
        if ($v === null || $v === '') continue;
        $cls = in_array($k, $monoKeys, true) ? ' class="mono"' : '';
        $out .= '<dt>' . esc(t((string)$k)) . '</dt><dd' . $cls . '>' . esc((string)$v) . '</dd>';
    }
    return $out . '</dl>';
}

/** Stepper: подписи шагов + текущий (1-based). */
function stepper(array $labels, int $current): string
{
    $out = '<div class="stepper">';
    foreach (array_values($labels) as $i => $label) {
        $n = $i + 1;
        $state = $n < $current ? ' is-done' : ($n === $current ? ' is-current' : '');
        $dot = $n < $current ? icon('check', 15) : (string)$n;
        $out .= '<div class="stepper-item' . $state . '">'
            . '<span class="stepper-dot">' . $dot . '</span>'
            . '<span class="stepper-label">' . esc($label) . '</span></div>';
    }
    return $out . '</div>';
}

/**
 * Медиа-панель экрана входа: снимок владельца или акцентная заставка.
 *
 * Это единственная страница SSO, которую человек видит на чужом сайте — и
 * видит на каждом сайте группы. Панель принадлежит владельцу установки и по
 * умолчанию пуста: белая марка означает, что большинство ничего не загрузит,
 * поэтому «пусто» оформлено состоянием, а не дырой.
 *
 * Подпись стоит ПОД снимком, а не поверх него. Поверх она читалась бы только
 * на тёмных кадрах: градиент к верху блока почти прозрачен, и белый заголовок
 * на снегу или светлом офисе давал бы около 2:1. Здесь у подписи свой фон, и
 * гарантия контраста та же, что у всего остального интерфейса, — что бы
 * владелец ни загрузил.
 */
function auth_media_pane(?array $client = null): string
{
    /* Человек пришёл с портала, у которого своя тема и своя цитата, — левая
       панель его (v4.65). Знак и имя установки остаются внизу панели и в связке
       над формой: страница по-прежнему наша. Присланное — только текстом. */
    $theme = $client !== null ? App\Core\Bridge::themeOf($client) : null;
    /* Панель портала — только у его собственного оформления: из манифеста или
       заданного в карточке. Портал на родном виде установки получает и её
       панель. Обложку может загрузить только администратор (v4.71). */
    $own = $theme !== null && in_array($theme['source'], ['portal', 'custom'], true);
    /* Обложка — картинка, галерея или видео (v4.81); только у заданного в
       карточке: из манифеста её не прислать. */
    $cover = $own && $theme['source'] === 'custom' ? App\Core\CoverSet::frame((int)$client['id']) : null;
    if ($own && ($theme['headline'] !== null || $cover !== null)) {
        $quote = $theme['headline'] !== null ? '<p class="portal-pane-quote">' . esc($theme['headline']) . '</p>' : '';
        $note = $theme['headline_note'] !== null
            ? '<div class="portal-pane-note">' . esc($theme['headline_note']) . '</div>' : '';
        $foot = '<div class="portal-pane-foot">' . brand_mark() . '<span>'
            . esc(t('Вход через :app', ['app' => Setting::appName()])) . '</span></div>';
        if ($cover !== null) {
            /* Цитата ПОД обложкой, как подпись к снимку установки: поверх кадра
               она читалась бы только на тёмных снимках. */
            return '<div class="media-pane portal-cover"><div class="media-frame">'
                . $cover . '</div>'
                . '<div class="media-caption portal-cover-caption">'
                . '<div class="portal-pane-name">' . esc((string)$client['name']) . '</div>'
                . $quote . $note . $foot . '</div></div>';
        }
        return '<div class="media-blank portal-pane"><div class="portal-pane-inner">'
            . '<div class="portal-pane-name">' . esc((string)$client['name']) . '</div>'
            . $quote . $note . $foot
            . '</div></div>';
    }

    ['title' => $title, 'text' => $text] = App\Core\Bridge::sitePane();
    $cover = App\Core\CoverSet::frame(null);

    if ($cover === null) {
        return '<div class="media-blank"><div class="media-blank-inner">'
            . '<div class="mb-16">' . brand_mark('mark-xl') . '</div>'
            /* Не заголовок: панель слева — оформление, а не раздел
               страницы. Пока это был h2, он оказывался ПЕРВЫМ заголовком
               на экранах входа и представлял страницу названием
               установки вместо её собственного имени (v4.47). */
            . '<div class="media-blank-title">' . esc($title !== '' ? $title : Setting::appName()) . '</div>'
            . '<p class="muted mb-0">' . esc($text !== '' ? $text : t('Один аккаунт на все сервисы.')) . '</p>'
            . '</div></div>';
    }

    $caption = '';
    if ($title !== '' || $text !== '') {
        $caption = '<div class="media-caption">'
            . ($title !== '' ? '<div class="media-caption-title">' . esc($title) . '</div>' : '')
            . ($text !== '' ? '<p>' . esc($text) . '</p>' : '')
            . '</div>';
    }
    return '<div class="media-pane"><div class="media-frame">' . $cover . '</div>' . $caption . '</div>';
}

/**
 * Поле капчи — своей картинкой или сторонним виджетом.
 *
 * Разметка была скопирована дважды в одном шаблоне входа и ещё по разу в
 * регистрации и восстановлении. Копии разошлись бы при первой правке, и
 * разошлись бы молча: форма продолжила бы отправляться, просто проверка на
 * одном из экранов перестала бы показываться.
 *
 * Капча не висит на форме всегда — она появляется после порога неудач или на
 * экранах, где владелец включил её явно. Постоянная капча это налог на тех,
 * кто ни в чём не виноват, а против скриптов она работает не лучше счётчика
 * попыток.
 *
 * $id нужен, потому что на экране входа полей два: у формы со ссылкой и у
 * формы с паролем, а одинаковые id ломают связь label с полем.
 *
 * $why — почему проверка тут (v4.96): на входе её вызывают неудачи, на
 * регистрации и восстановлении — настройка владельца, и объяснение про
 * «неудачные попытки» там было бы неправдой.
 */
function captcha_field(string $id = 'captcha', ?string $why = null): string
{
    $why = '<div class="captcha-why">' . icon('info', 15, 'icon-muted')
        . '<div>' . esc($why ?? t('Проверка появилась после нескольких неудачных попыток и снимется после успешного входа.'))
        . '</div></div>';

    if (\App\Core\Captcha::widgetReady()) {
        /* Сам виджет мы не стилизуем: у него свой размер и свой фон. Наше дело
           — рамка под него и объяснение, почему он вообще тут.
           Скрипт подключается здесь же, а не в макете: он нужен ровно там, где
           виджет нарисован, и на остальных экранах не грузится вовсе. */
        $turnstile = \App\Core\Captcha::provider() === 'turnstile';
        $attr = $turnstile ? 'cf-turnstile' : 'h-captcha';
        $src = $turnstile
            ? 'https://challenges.cloudflare.com/turnstile/v0/api.js'
            : 'https://js.hcaptcha.com/1/api.js';
        return '<div class="captcha"><div class="captcha-slot">'
            . '<div class="' . $attr . '" data-sitekey="' . esc(\App\Core\Captcha::siteKey()) . '"></div>'
            . '</div>' . $why . '</div>'
            . '<script src="' . $src . '" async defer></script>';
    }

    /* Подпись стоит НАД парой, а картинка — в одной строке с полем.
       Раньше картинка была первой в ряду и равнялась по верху с подписью: её
       середина оказывалась на 19px выше середины поля, и ряд читался кривым.
       Размеры теперь ровно те, в которых картинка рождается (150×48): было
       объявлено 148×56, то есть её растягивали по высоте и сжимали по ширине —
       отсюда и косые цифры. */
    return '<div class="captcha"><div class="field mb-0">'
        . '<label for="' . esc($id) . '">' . esc(t('Проверка: сколько получится?')) . '</label>'
        . '<div class="captcha-row">'
        . '<img class="captcha-img" src="/captcha.png?' . time() . '" width="150" height="48"'
        . ' alt="' . esc(t('Арифметическая задача')) . '">'
        . '<input class="input" id="' . esc($id) . '" name="captcha" inputmode="numeric"'
        . ' autocomplete="off" required>'
        . '</div></div>'
        /* «Другая картинка» обязательна: примерно каждый десятый не разберёт
           первую, и без кнопки единственный выход — уйти со страницы. */
        . '<div class="captcha-actions"><button type="button" data-captcha-new>'
        . esc(t('Другая картинка')) . '</button></div>'
        . $why . '</div>';
}

/**
 * Шапка карточки объекта: знак, имя, машинный идентификатор, состояние, действия.
 *
 * В админке девять разделов, и каждый был своим экраном со своей вёрсткой. Но
 * сущности устроены одинаково: у каждой есть имя, идентификатор, состояние,
 * связи с другими объектами и история. Отличается только содержимое одной
 * вкладки — поэтому шапка одна на всех.
 *
 * Идентификатор стоит рядом с именем, а не прячется во вкладке: `org_4`,
 * `vitrina`, `usr_204517883` копируют в тикеты, ищут в логах и подставляют в
 * конфиг портала. Это значение, а не подпись.
 *
 * @param list<string> $ids машинные идентификаторы, как есть
 */
function obj_head(string $mark, string $name, array $ids, string $badges = '', string $actions = ''): string
{
    $out = '<div class="obj-head">'
        . '<div class="obj-mark">' . $mark . '</div>'
        . '<div class="obj-main"><h1 class="obj-name">' . esc($name) . '</h1>';
    if ($ids !== [] || $badges !== '') {
        $out .= '<div class="obj-id">';
        foreach ($ids as $id) $out .= '<code>' . esc($id) . '</code>';
        $out .= $badges . '</div>';
    }
    $out .= '</div>';
    if ($actions !== '') $out .= '<div class="obj-actions">' . $actions . '</div>';
    return $out . '</div>';
}

/**
 * Связи объекта: «входит в», «содержит», «использует», «выдана кому».
 *
 * Один компонент на все отношения — иначе каждая сущность заводит свой список
 * со своей вёрсткой, и из карточки перестаёт быть видно, что сломается при
 * удалении.
 *
 * @param list<array{href?:string,label:string,badge?:string}> $items
 */
/**
 * Роли человека в области — плашки и выдача, одним вызовом (v4.50).
 *
 * Обёртка над `admin/_role_cell`, которая берёт на себя всё, что иначе
 * пришлось бы повторять на каждой странице: спросить у RoleOffer, что здесь
 * можно выдать, взять уже выданное и подставить вопрос при снятии.
 *
 * Ставится в любую строку, где виден человек: список людей сервиса, состав
 * организации, карточка человека. Раньше это была разметка внутри одного
 * шаблона — то есть «поставить в другом месте» означало «скопировать», а
 * скопированное расходится.
 *
 * $granted — уже выданное этому человеку в этой области. Передаётся снаружи
 * намеренно: на списке из двухсот строк спрашивать выдачи построчно значило бы
 * двести запросов, поэтому вызывающий берёт их одним RoleOffer::grantsByUser().
 */
function role_cell(
    int $userId,
    string $userName,
    \App\Core\Scope $scope,
    string $actionUrl,
    array $granted = [],
    ?array $offer = null,
    ?bool $mayGrant = null
): string {
    $mayGrant ??= \App\Core\Auth::can('roles.write', $scope);
    $offer ??= ($mayGrant ? \App\Core\RoleOffer::inScope($scope) : []);
    $ask = match ($scope->type) {
        \App\Core\Scope::CLIENT => t('Отозвать роль в этом сервисе?'),
        \App\Core\Scope::ORG    => t('Отозвать роль в этой организации?'),
        default                 => t('Снять роль?'),
    };
    return \App\Core\View::fetch('admin/_role_cell', [
        'userId' => $userId, 'userName' => $userName,
        'granted' => $granted, 'offer' => $offer,
        'actionUrl' => $actionUrl, 'mayGrant' => $mayGrant, 'revokeAsk' => $ask,
    ]);
}

function rel_group(string $kind, array $items, string $empty = ''): string
{
    $out = '<div class="rel-group"><div class="rel-kind">' . esc($kind) . '</div>';
    if ($items === []) {
        return $out . '<div class="hint">' . esc($empty !== '' ? $empty : t('Связей нет')) . '</div></div>';
    }
    $out .= '<div class="rel-list">';
    foreach ($items as $it) {
        $inner = ($it['badge'] ?? '') . '<span>' . esc($it['label']) . '</span>';
        $out .= isset($it['href'])
            ? '<a class="rel-item" href="' . esc($it['href']) . '">' . $inner . '</a>'
            : '<span class="rel-item">' . $inner . '</span>';
    }
    return $out . '</div></div>';
}

/**
 * История объекта строками «когда — что — кто».
 *
 * Читается из журнала аудита под другим углом (App\Core\ObjectHistory): нового
 * хранилища нет, события не дублируются.
 */
function obj_history(array $rows): string
{
    if ($rows === []) {
        return empty_state('clock', t('Событий пока нет'), '', 'pad-16');
    }
    $out = '<div class="hist">';
    foreach ($rows as $r) {
        $out .= '<div class="hist-row">'
            . '<span class="hist-when">' . esc(fmt_dt($r['created_at'], 'd.m.Y H:i')) . '</span>'
            . '<span class="hist-what"><b>' . esc(\App\Core\ObjectHistory::title($r['event'])) . '</b>'
            /* Пусто — значит сделала система: cron, вебхук, миграция. Словом,
               как в журнале аудита (v4.83): прочерк после заголовка с тире
               внутри («…сессии закрыты —») читался оборванной строкой. */
            . ' <span class="hint">' . esc(($r['actor'] ?? '') !== '' ? (string)$r['actor'] : t('система')) . '</span></span>'
            . '</div>';
    }
    return $out . '</div>';
}

/**
 * Разделы навигации — один список на островок, нижний док и меню «Ещё».
 *
 * Раньше список жил прямо в макете, и это работало, пока рисовало его одно
 * место. Островок и док показывают одно и то же в двух формах; два списка
 * рядом разошлись бы при первой же правке, и разошлись бы молча — на телефоне
 * раздел просто перестал бы существовать.
 *
 * `primary` — не больше пяти: столько влезает в док на 360px без промахов
 * пальцем, и столько же читается в островке, не превращаясь в кашу. Всё, что
 * не поместилось, уходит в `more`, а не выкидывается: раздел, до которого
 * нельзя дойти, хуже раздела, до которого нужно два нажатия.
 *
 * Дашборда в списке нет намеренно — на него ведёт сам знак слева, как «домой».
 *
 * @return array{primary: list<array{href:string,label:string,icon:string}>,
 *               more: list<array{href:string,label:string,icon:string}>}
 */
function nav_sections(bool $adminArea): array
{
    $primary = [];
    $more = [];

    if ($adminArea) {
        /* Порядок по вложенности, от крупного к мелкому: организация объединяет
           порталы, портал — людей. Организации видит только глобальный админ,
           поэтому у админа портала ряд просто начинается с порталов —
           вложенность при этом не ломается. */
        if (\App\Core\Auth::isAdmin()) {
            $primary[] = ['href' => '/admin/orgs', 'label' => t('Организации'), 'icon' => 'users'];
        }
        $primary[] = ['href' => '/admin/clients', 'label' => t('Порталы'), 'icon' => 'portal'];
        $primary[] = ['href' => '/admin/users', 'label' => t('Пользователи'), 'icon' => 'user'];
        if (\App\Core\Auth::isAdmin()) {
            $primary[] = ['href' => '/admin/roles', 'label' => t('Роли'), 'icon' => 'role'];
            $primary[] = ['href' => '/admin/logs', 'label' => t('Логи'), 'icon' => 'log'];
            /* Меню «Ещё» здесь было и опустело осознанно.
               В нём лежали пять пунктов — оформление, вебхуки, здоровье,
               инструменты, система, — и после v4.10 все пять стали разделами
               одного экрана настроек. То есть кнопка «Ещё» превратилась в
               короткую копию колонки, которая и так открывается шестерёнкой:
               пять пунктов из шестнадцати, выбранных по тому, что когда-то они
               были отдельными страницами. Две двери в одну комнату, причём
               одна показывает треть комнаты.
               Осталась шестерёнка: она ведёт в настройки целиком. Разделы
               по-прежнему находятся поиском — он берёт их из SettingsMap. */
        }
        return ['primary' => $primary, 'more' => $more];
    }

    return ['primary' => [
        ['href' => '/profile', 'label' => t('Профиль'), 'icon' => 'user'],
        ['href' => '/profile/security', 'label' => t('Безопасность'), 'icon' => 'shield-check'],
        ['href' => '/profile/sessions', 'label' => t('Устройства'), 'icon' => 'desktop'],
        ['href' => '/profile/services', 'label' => t('Сервисы'), 'icon' => 'portal'],
        ['href' => '/profile/settings', 'label' => t('Настройки'), 'icon' => 'settings'],
    ], 'more' => []];
}

/**
 * Знак установки: загруженный логотип или квадрат с инициалами.
 *
 * Квадрат со срезанным углом — цитата уголка документа, а не рисунок. Он
 * нужен там, где логотипа может не быть вовсе: белая марка означает, что
 * большинство установок ничего не загрузит, и «пусто» — это состояние по
 * умолчанию, а не исключение.
 *
 * Инициалы берём из названия: «Комбинат Заря» → «КЗ», «Единый вход» → «ЕВ».
 * Одно слово даёт две первых буквы, иначе знак выглядит опечаткой.
 */
function brand_mark(string $size = ''): string
{
    if (Setting::get('branding.logo_v')) return branding_logo_html();

    $name = trim(Setting::appName());
    $words = preg_split('~[\s·—-]+~u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $letters = count($words) >= 2
        ? mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1)
        : mb_substr($name, 0, 2);

    return '<span class="mark' . ($size !== '' ? ' ' . esc($size) : '') . '">'
        . esc(mb_strtoupper($letters)) . '</span>';
}

/**
 * Заголовок экрана входа: название и одна строка пояснения.
 *
 * Шесть экранов писали его тремя разными способами — `h2.text-center.fs-18`,
 * `h1.fs-22.text-center` и просто `h2`. Разнобой виден именно там, где человек
 * ходит между экранами подряд: подтвердил почту, попал на второй фактор,
 * оттуда на пароль — и каждый раз заголовок другого кегля.
 *
 * Пояснение — необязательное и ровно одно: если экран нельзя объяснить одной
 * строкой, объяснять надо не заголовком, а самой формой.
 */
/**
 * Плашка «куда вы входите»: значок сервиса, предлог и его название.
 *
 * Была скопирована в трёх шаблонах — согласие, регистрация, приглашение — с
 * одинаковой разметкой и разными предлогами. Копий стало три, а правило одно,
 * и держать его в трёх местах значит однажды поправить в двух.
 *
 * `$heading` решает, заголовок это или подпись. На экранах согласия и
 * приглашения вкладок нет, и «Вход в такой-то» — настоящее имя страницы,
 * поэтому там h1. На регистрации имя даёт подсвеченная вкладка, и второй
 * заголовок был бы враньём об устройстве страницы.
 */
function auth_context(array $client, string $prefix, bool $heading = true): string
{
    $tag = $heading ? 'h1' : 'div';
    return '<' . $tag . ' class="auth-context">'
        . client_icon_html($client, 24)
        . '<span>' . esc($prefix) . '</span> '
        . '<strong>' . esc((string)($client['name'] ?? '')) . '</strong>'
        . '</' . $tag . '>';
}

/**
 * Первая ссылка страницы: «сразу к содержимому».
 *
 * Тот, кто ходит клавиатурой, начинает КАЖДУЮ страницу с прохода по всей
 * навигации: островок сверху, док снизу, боковая колонка разделов. До первого
 * поля формы набегало по два десятка нажатий — и так на каждом экране.
 *
 * Видна только под фокусом: мышью её никто не ищет, а спрятать насовсем
 * нельзя — ссылка, которую не видно даже когда на ней фокус, оставляет
 * человека в недоумении, куда он только что попал.
 */
function skip_link(): string
{
    return '<a class="skip-link" href="#main">' . esc(t('Сразу к содержимому')) . '</a>';
}

/**
 * Образец шрифта «Аа Вход», написанный самим шрифтом (v4.78). Стек — переменной
 * --ff: у шрифтов из Google классов под каждый не заведёшь.
 */
function font_sample(string $key, string $class = 'font-sample'): string
{
    return '<span class="' . esc($class) . ' ff-var" style="--ff:' . esc(\App\Core\Bridge::fontStack($key)) . '">Аа Вход</span>';
}

/**
 * Перевод, в который подставляется разметка: выделенный адрес, ссылка.
 *
 * Сам текст экранируется, подставленное — уже готовый HTML, его собирает
 * шаблон (и экранирует данные внутри сам). Ключи — как у t(), без двоеточия.
 * Нужно письмам (v4.74): фраза «смена почты на :email» переводится целиком, а
 * адрес в ней выделен — склеить её из кусков значило бы снова получить
 * полписьма на одном языке, полписьма на другом.
 */
function t_html(string $key, array $html = []): string
{
    $map = [];
    foreach ($html as $k => $v) $map[':' . $k] = (string)$v;
    return strtr(esc(t($key)), $map);
}

/**
 * Строки, которые рисует app.js, — на языке страницы.
 *
 * Словарь живёт в PHP, поэтому подписи приезжают в страницу, а не дублируются
 * в скрипте отдельным списком. До 4.74 его выдавала только раскладка панели:
 * на экранах входа его не было, и английская форма после щелчка «show»
 * писала «скрыть», а кнопка темы подписывалась «Тема: авто». Теперь его
 * зовут все четыре раскладки.
 */
function js_i18n(): string
{
    $strings = [
        'filters' => t('Фильтры'),
        'copied' => t('common.copied'),
        'permChanged' => t('изменено'),
        'show' => t('показать'),
        'hide' => t('скрыть'),
        'permDerived' => t('Проставлено само: изменять или удалять, не видя, нельзя'),
        'themeAuto' => t('Тема: авто'),
        'themeDark' => t('Тема: тёмная'),
        'themeLight' => t('Тема: светлая'),
        'close' => t('Закрыть'),
        'details' => t('Подробности'),
        'detailsKey' => t('Детали'),
        'checking' => t('Проверяю…'),
        'done' => t('Готово'),
        'error' => t('Ошибка'),
        'requestFailed' => t('Сбой запроса — посмотрите логи.'),
        'digit' => t('Цифра :n'),
        'checkDigit' => t('Контрольная цифра, считается сама'),
        'typeDigits' => t('Наберите :n цифр — последняя посчитается сама.'),
        'firstZero' => t('Первая цифра не может быть нулём: номер потеряет её при копировании.'),
        'wholeNumber' => t('Номер целиком: :n'),
        /* Свои схемы оформления (v4.79) */
        'schemeName' => t('Как назвать схему?'),
        'schemeDrop' => t('Убрать схему «:name»?'),
        /* passkey.js (v4.75) */
        'pkUnsupported' => t('Браузер не поддерживает passkeys'),
        'pkWaiting' => t('Ждём подтверждение…'),
        'pkFailed' => t('Не получилось — попробуйте ещё раз.'),
        'pkCancelled' => t('Отменено или время вышло.'),
        'pkError' => t('Ошибка: :e'),
        'pkFollow' => t('Следуйте подсказкам браузера…'),
        'pkName' => t('Как назвать этот ключ?'),
        'pkDefaultName' => t('Мой passkey'),
        'pkFailedShort' => t('Не получилось.'),
        /* palette.js (v5.0) */
        'paletteTitle' => t('Поиск по системе'),
        'paletteHint' => t('Раздел, пользователь, портал или действие…'),
        'paletteFailed' => t('Не удалось получить результаты'),
        'paletteNone' => t('Ничего не нашлось'),
    ];
    return '<script nonce="' . esc(csp_nonce()) . '">window.ssoI18n = '
        . json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';
}

function auth_head(string $title, string $sub = '', bool $center = false): string
{
    return '<div class="auth-head' . ($center ? ' text-center' : '') . '">'
        . '<h1>' . esc($title) . '</h1>'
        /* Перевод строки в пояснении — перенос (v4.88): «Яндекс ID подтвердил
           вашу личность» и «аккаунта ещё нет» — две мысли, и каждой своя строка. */
        . ($sub !== '' ? '<p>' . nl2br(esc($sub), false) . '</p>' : '')
        . '</div>';
}

/**
 * Шапка экранов входа с портала: два знака связки и «Вы входите в X» (v5.3.1).
 *
 * Стояла только на «Вход»; «Регистрация» показывала плашку другого вида, а «Не
 * помню» — знак установки вместо портала. Вкладки переключаются без нового
 * документа, и шапка прыгала на каждом щелчке, а во фрейме на странице
 * портала «Не помню» выглядела чужой. Теперь шапка одна на три вкладки и
 * меняется только подпись.
 */
function auth_portal_head(array $client, string $lead): string
{
    $bridge = App\Core\Bridge::brandingOf($client);
    return '<div class="connector-head mb-24">' . portal_connector($client)
        . '<div class="connector-caption">' . esc($lead) . ' <b>' . esc((string)($client['name'] ?? '')) . '</b>'
        . ($bridge && $bridge['tagline'] ? '<div class="hint">' . esc($bridge['tagline']) . '</div>' : '')
        . '</div></div>';
}

/**
 * Два знака связки: портал слева, SSO справа, пунктир между ними (v4.4.6).
 *
 * $done — вход состоялся (экран «Вы вошли», v4.72): точка на стыке
 * становится галочкой. Один помощник на оба экрана — иначе знак портала на
 * «Вы вошли» разошёлся бы с тем, что человек видел секунду назад на входе.
 */
function portal_connector(array $client, bool $done = false): string
{
    $bridge = \App\Core\Bridge::brandingOf($client);
    $logo = $bridge && $bridge['logo']
        ? '<img class="bridge-logo" src="' . esc($bridge['logo']) . '" alt="" width="34" height="34">'
        : client_icon_html($client, 34);
    $dot = $done
        ? '<span class="connector-dot is-done">' . icon('check', 13) . '</span>'
        : '<span class="connector-dot"></span>';
    return '<div class="connector"><span class="connector-node">' . $logo . '</span>'
        . '<span class="connector-link"></span>' . $dot . '<span class="connector-link"></span>'
        . '<span class="connector-node">' . brand_mark() . '</span></div>';
}

/**
 * Вкладки на экранах входа: вход · регистрация · не помню.
 *
 * Раньше «Нет аккаунта? Создать» стояло последней строкой под формой, хотя
 * заметная доля людей приходит именно регистрироваться. Вкладка ставит оба
 * пути в один вес.
 *
 * Это ссылки, а не кнопки с ролью `tab`: `/login`, `/register` и `/recover` —
 * три настоящих маршрута со своей серверной валидацией, а не три панели одной
 * страницы. Роль `tab` подразумевает панели рядом и переключение без перехода;
 * здесь честнее обычная навигация с `aria-current`.
 *
 * Подсветку ставит сервер: позиция плашки считается из индекса вкладки, а не
 * из замеров в браузере, — поэтому она верна и до того, как выполнится скрипт,
 * и там, где скрипта нет вовсе.
 */
function auth_tabs(string $current): string
{
    $tabs = [
        'login' => ['/login', t('Вход')],
        'register' => ['/register', t('Регистрация')],
        'recover' => ['/recover', t('Не помню')],
    ];
    $index = array_search($current, array_keys($tabs), true);
    /* Ключ возврата едет по всем трём вкладкам: человек, пришедший из сервиса,
       свободно ходит между «Вход», «Регистрация» и «Не помню», и на каждом
       шаге обязан сохранить право вернуться туда, откуда пришёл. Без этого
       переход на регистрацию терял портал, и человек оказывался в личном
       кабинете SSO. */
    $rt = App\Core\Resume::fromRequest();
    /* Имя экрана — заголовком, но невидимым (v4.47).
       Глазами экран называет подсвеченная вкладка, и видимый h1 под ней
       повторял бы то же слово вдвое крупнее — так и записано в шаблоне
       восстановления. А вот в СТРУКТУРЕ страницы имени не было вовсе: /login,
       /register и /recover шли без единого h1, и первым заголовком выходило
       название установки из декоративной панели слева. Тот, кто ходит по
       заголовкам, слышал чужое имя вместо «Вход».
       Заголовок стоит здесь, а не в трёх шаблонах: вкладки и так знают, какой
       экран открыт, и третьего места для рассинхрона не появляется. */
    $out = '<h1 class="sr-only">' . esc($tabs[$current][1] ?? '') . '</h1>'
        . '<nav class="auth-tabs" aria-label="' . esc(t('Способ входа')) . '">'
        . '<span class="auth-tabs-ink" style="--i:' . (int)$index . ';--n:' . count($tabs) . '"></span>';
    foreach ($tabs as $key => [$href, $label]) {
        if ($rt !== '') $href .= '?rt=' . urlencode($rt);
        $out .= '<a href="' . esc($href) . '"'
            . ($key === $current ? ' aria-current="page"' : '') . '>' . esc($label) . '</a>';
    }
    return $out . '</nav>';
}

/**
 * Таблицы стилей одной строкой.
 *
 * Три макета (base, auth, minimal) повторяли один и тот же список ссылок.
 * Пока файлов было три, это выглядело безобидно; четвёртый пришлось бы
 * добавлять в трёх местах, и однажды в одном из них его бы не оказалось —
 * страница отрисовалась бы без слоя, без единой ошибки в консоли.
 *
 * Порядок значим: токены → шрифты → база → айдентика. Слой айдентики
 * дополняет базу и опирается на её переменные, поэтому идёт последним.
 */
function styles_head(): string
{
    $out = '';
    foreach (['assets/css/tokens.css', 'assets/fonts/fonts.css',
              'assets/css/app.css', 'assets/css/identity.css'] as $href) {
        $out .= '<link rel="stylesheet" href="' . esc(asset($href)) . '">' . "\n";
    }
    return $out;
}

/**
 * Полоса действий формы: кнопка сохранения, которая не уезжает вверх.
 *
 * Кнопка стояла последней строкой формы, и на длинных страницах — права роли,
 * настройки, карточка портала — до неё нужно было домотать. Человек правит
 * галочку в середине таблицы и не видит, чем закончить; хуже того, не видит,
 * что несохранённое вообще есть.
 *
 * `position: sticky` вместо `fixed` и вместо скрипта: пока форма длиннее
 * экрана, полоса висит внизу; когда домотал до конца — встаёт на своё место
 * последней строкой. Формы на скрытых вкладках ничего не показывают сами
 * собой, поэтому семь форм настроек не дают семи полос.
 *
 * Восемнадцать кнопок в девяти шаблонах были написаны пятью разными наборами
 * классов — здесь ровно поэтому один вызов, а не образец для копирования.
 */
function form_actions(string $label = '', string $extra = ''): string
{
    return '<div class="form-actions">'
        . '<button class="btn btn-primary" type="submit">'
        . esc($label !== '' ? $label : t('Сохранить изменения')) . '</button>'
        . $extra . '</div>';
}

/**
 * Кнопка отправки В ОДНОЙ СТРОКЕ с полями.
 *
 * `form_actions()` — это НИЖНЯЯ ПОЛОСА формы: липкая, с чертой сверху, во всю
 * ширину. Её ставили рядом с двумя селектами в `.row`, и получалось ровно то,
 * что видно глазом: короткая черта в воздухе над кнопкой и кнопка, съехавшая
 * относительно полей. Полоса не виновата — её просто применили не там.
 *
 * Здесь кнопка выравнивается по НИЗУ строки: у поля сверху подпись, у кнопки
 * её нет, и совпасть они могут только нижними краями.
 */
function field_submit(string $label, string $extra = ''): string
{
    return '<div class="field field-submit">'
        . '<button class="btn btn-primary" type="submit">' . esc($label) . '</button>'
        . $extra . '</div>';
}

/**
 * Пустое состояние: иконка, объяснение и, если есть куда вести, кнопка.
 *
 * Пятнадцать пустых состояний были написаны четырьмя разными способами: где-то
 * внутри карточки, где-то голым текстом на сером фоне. Из-за этого «Порталов
 * пока нет» и «Организаций пока нет» — одна и та же мысль — выглядели как
 * страницы из разных продуктов.
 */
function empty_state(string $icon, string $text, string $action = '', string $class = ''): string
{
    return '<div class="empty' . ($class !== '' ? ' ' . esc($class) : '') . '">'
        . '<div class="glyph">' . icon($icon, 32, 'icon-muted') . '</div>'
        . '<div class="empty-text">' . esc($text) . '</div>'
        . ($action !== '' ? '<div class="empty-action">' . $action . '</div>' : '')
        . '</div>';
}

/**
 * Хлебные крошки: [['Пользователи', '/admin/users'], ['Иван Петров', null]].
 * Последний элемент — текущая страница, без ссылки.
 */
function breadcrumbs(array $items): string
{
    $parts = [];
    foreach ($items as $i => [$label, $url]) {
        $last = $i === count($items) - 1;
        $parts[] = $url !== null && !$last
            ? '<a href="' . esc($url) . '">' . esc($label) . '</a>'
            : '<span aria-current="page">' . esc($label) . '</span>';
    }
    return '<nav class="breadcrumbs" aria-label="' . esc(t('Хлебные крошки')) . '">'
        . implode(icon('chevron-right', 14, 'icon-muted'), $parts) . '</nav>';
}

/** active-класс для навигации. */
function nav_active(string $prefix): string
{
    $uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($prefix === '/profile' || $prefix === '/admin') {
        return $uri === $prefix ? ' class="active"' : '';
    }
    return str_starts_with($uri, $prefix) ? ' class="active"' : '';
}

function str_starts_uuid(string $s): bool
{
    return (bool)preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $s);
}

/* ---------- Поля формы одним объявлением (v4.9) ----------------------------
   Поле в разметке — это метка, подпись, класс, обёртка, атрибуты и маска.
   Шесть строк на каждое, и все шесть пишутся заново в каждом шаблоне. Пока
   полей два десятка, расхождения незаметны; на сотне они уже есть: где-то нет
   `for` у метки, где-то подпись стоит над полем, а не под ним, где-то маска
   забыта. Отсюда одно объявление вместо шести строк — и одинаковый результат
   везде по построению, а не по внимательности.                              */

/**
 * Текстовое поле.
 *
 * $opt: hint, placeholder, max, type (text|email|tel|url|password), mask,
 *       required, mono, readonly, autocomplete.
 */
function field_text(string $name, string $label, string|int|null $value = '', array $opt = []): string
{
    $value = (string)$value;
    $id = $opt['id'] ?? 'f-' . preg_replace('~[^a-z0-9_-]~i', '-', $name);
    $type = $opt['type'] ?? 'text';
    $attrs = '';
    /* list — подсказки из <datalist> (v4.78: каталог шрифтов Google). */
    foreach (['placeholder' => 'placeholder', 'autocomplete' => 'autocomplete',
              'inputmode' => 'inputmode', 'pattern' => 'pattern', 'list' => 'list'] as $k => $a) {
        if (!empty($opt[$k])) $attrs .= ' ' . $a . '="' . esc($opt[$k]) . '"';
    }
    if (!empty($opt['max'])) $attrs .= ' maxlength="' . (int)$opt['max'] . '"';
    /* Границы числа — атрибутами, а не проверкой после отправки: браузер не
       даст ввести 0 использований у приглашения. Названы low/high, а не
       min/max, потому что max в этом же списке уже занят длиной строки, и
       два разных смысла под одним именем однажды перепутают. */
    foreach (['low' => 'min', 'high' => 'max', 'step' => 'step'] as $k => $a) {
        if (isset($opt[$k])) $attrs .= ' ' . $a . '="' . esc((string)$opt[$k]) . '"';
    }
    if (!empty($opt['required'])) $attrs .= ' required';
    if (!empty($opt['readonly'])) $attrs .= ' readonly';
    /* Курсор в первое поле мастера: единственное поле экрана «подключить
       сервис» — адрес, и набирать его начинают сразу. */
    if (!empty($opt['autofocus'])) $attrs .= ' autofocus';
    /* Маска — атрибутом, а не скриптом на месте: разметку рисует сервер, а
       поведение навешивает один обработчик на всю страницу (см. app.js). */
    if (!empty($opt['mask'])) $attrs .= ' data-mask="' . esc($opt['mask']) . '"';

    /* Ошибка поля сама делает его красным: раньше `is-invalid` ставили руками
       рядом с выводом текста ошибки, и в половине мест ставили только одно из
       двух — поле краснело без объяснения либо объяснение висело под обычным
       полем. */
    $invalid = !empty($opt['invalid']) || !empty($opt['error']);
    $cls = 'input' . (!empty($opt['mono']) ? ' mono' : '') . ($invalid ? ' is-invalid' : '');
    $notes = field_notes($id, $opt);
    return '<div class="field' . (!empty($opt['class']) ? ' ' . esc($opt['class']) : '') . '">'
        . field_label($id, $label, $opt)
        . '<input class="' . $cls . '" type="' . esc($type) . '" id="' . esc($id) . '"'
        . field_aria($label, $opt) . $notes['attrs']
        . ' name="' . esc($name) . '" value="' . esc($value) . '"' . $attrs . '>'
        . $notes['blocks']
        . '</div>';
}

/** Многострочное поле. Те же правила, что у однострочного. */
function field_textarea(string $name, string $label, string|int|null $value = '', array $opt = []): string
{
    $value = (string)$value;
    $id = $opt['id'] ?? 'f-' . preg_replace('~[^a-z0-9_-]~i', '-', $name);
    $rows = (int)($opt['rows'] ?? 2);
    $notes = field_notes($id, $opt);
    return '<div class="field' . (!empty($opt['class']) ? ' ' . esc($opt['class']) : '') . '">'
        . field_label($id, $label, $opt)
        . '<textarea class="input' . (!empty($opt['mono']) ? ' mono' : '') . '"'
        . field_aria($label, $opt) . $notes['attrs']
        . ' id="' . esc($id) . '" name="' . esc($name) . '" rows="' . $rows . '"'
        . (!empty($opt['max']) ? ' maxlength="' . (int)$opt['max'] . '"' : '')
        . (!empty($opt['placeholder']) ? ' placeholder="' . esc($opt['placeholder']) . '"' : '')
        . '>' . esc($value) . '</textarea>'
        . $notes['blocks']
        . '</div>';
}

/**
 * Тумблер настройки.
 *
 * Именно тумблер, а не «чекбокс с подписью»: у настройки два СОСТОЯНИЯ, и
 * подпись обязана объяснять, что случится при включении, — иначе её включают
 * наугад. Поэтому пояснение не необязательный довесок, а часть объявления.
 *
 * Граница с галочкой проведена по смыслу, а не по вкусу: тумблер — состояние
 * настройки («показывать витрину»), галочка — выбор из списка («какие порталы
 * показывать»). До этого в проекте жили оба вида вперемешку: в одном разделе
 * капча включалась переключателем, а «требовать цифру» — галочкой, хотя это
 * одно и то же действие.
 */
function field_toggle(string $name, string $label, bool $on, string $why = '', array $opt = []): string
{
    $id = $opt['id'] ?? 'f-' . preg_replace('~[^a-z0-9_-]~i', '-', $name);
    return '<div class="field-toggle">'
        . '<label class="toggle"><input type="checkbox" id="' . esc($id) . '"'
        . ' name="' . esc($name) . '" value="1"' . ($on ? ' checked' : '')
        . (!empty($opt['disabled']) ? ' disabled' : '') . '><i></i>'
        . '<span>' . esc($label) . '</span></label>'
        . ($why !== '' ? '<p class="hint mt-4 mb-0">' . esc($why) . '</p>' : '')
        . '</div>';
}

/** Выпадающий список: $options — значение => подпись. */
/**
 * Подпись поля — или ничего, если подписи нет.
 *
 * Пустая подпись встречается там, где поле стоит в ячейке таблицы: колонка уже
 * названа заголовком, и повторять название в каждой строке незачем. Но пустой
 * `<label>` — не «без подписи», а подпись из пустоты: экранный диктор объявляет
 * поле безымянным, а щелчок по невидимой метке уводит фокус в соседнюю строку.
 * Нет подписи — нет и элемента; имя для доступности уходит в aria-label.
 */
function field_label(string $id, string $label, array $opt = []): string
{
    if ($label === '') return '';
    return '<label for="' . esc($id) . '">' . esc($label)
        . (!empty($opt['note']) ? ' <span class="hint">' . esc($opt['note']) . '</span>' : '')
        . '</label>';
}

/** Имя для тех, кто не видит: подпись есть — берётся она, нет — из aria. */
function field_aria(string $label, array $opt = []): string
{
    if ($label !== '') return '';
    $aria = (string)($opt['aria'] ?? '');
    return $aria === '' ? '' : ' aria-label="' . esc($aria) . '"';
}

/**
 * Подсказка и ошибка поля — привязанные к самому полю (v4.47).
 *
 * Раньше они просто лежали рядом отдельными блоками. Глазами связь очевидна:
 * красный текст под красной рамкой. Программе чтения — нет: она называет
 * подпись поля и замолкает, а объяснение, ПОЧЕМУ поле не принято, остаётся
 * непрочитанным. Человек слышит «Почта, поле ввода» и не знает, что не так.
 *
 * Здесь блоки получают идентификаторы, а поле — ссылку на них и пометку
 * `aria-invalid`. Место одно на все виды полей: разойтись им негде.
 *
 * @return array{attrs: string, blocks: string}
 */
function field_notes(string $id, array $opt): array
{
    $ids = [];
    $blocks = '';
    if (!empty($opt['hint'])) {
        $hid = $id . '-hint';
        $ids[] = $hid;
        $blocks .= '<div class="hint mt-4" id="' . esc($hid) . '">' . esc($opt['hint']) . '</div>';
    }
    if (!empty($opt['error'])) {
        $eid = $id . '-error';
        $ids[] = $eid;
        /* role="alert": ошибка, появившаяся после отправки формы, зачитывается
           сразу, а не ждёт, пока до поля дойдут табуляцией. */
        $blocks .= '<div class="field-error" id="' . esc($eid) . '" role="alert">'
            . esc($opt['error']) . '</div>';
    }
    $attrs = $ids === [] ? '' : ' aria-describedby="' . esc(implode(' ', $ids)) . '"';
    if (!empty($opt['error'])) $attrs .= ' aria-invalid="true"';
    return ['attrs' => $attrs, 'blocks' => $blocks];
}

function field_select(string $name, string $label, array $options, string|int|null $value = '', array $opt = []): string
{
    $value = (string)$value;
    $id = $opt['id'] ?? 'f-' . preg_replace('~[^a-z0-9_-]~i', '-', $name);
    $out = '';
    foreach ($options as $v => $l) {
        $out .= '<option value="' . esc((string)$v) . '"' . ((string)$v === $value ? ' selected' : '')
            . '>' . esc((string)$l) . '</option>';
    }
    /* «Малый» вид — для строк таблицы: там полноразмерный список раздувает
       строку и встаёт ступенькой рядом с малой кнопкой (было 40 против 27). */
    $small = !empty($opt['small']);
    $notes = field_notes($id, $opt);
    return '<div class="field' . ($small ? ' field-s' : '')
        . (!empty($opt['class']) ? ' ' . esc($opt['class']) : '') . '">'
        . field_label($id, $label, $opt)
        . '<select class="select' . ($small ? ' select-s' : '') . '" id="' . esc($id) . '" name="' . esc($name) . '"'
        . (!empty($opt['required']) ? ' required' : '')
        . field_aria($label, $opt) . $notes['attrs'] . '>' . $out . '</select>'
        . $notes['blocks']
        . '</div>';
}

/**
 * Номер аккаунта: клетки под цифры и контрольная цифра сама.
 *
 * Раньше здесь стояло обычное текстовое поле. Чтобы сменить номер, человек
 * должен был знать формат наизусть, посчитать, сколько цифр в каждой группе,
 * поставить тире в нужных местах и вычислить контрольную цифру — а на ошибке
 * получить отказ уже после отправки формы. Это не ввод, это экзамен.
 *
 * Клетки задают длину собой: лишнюю цифру некуда поставить. Контрольная
 * считается на месте и показывается отдельно — её не набирают, на неё смотрят.
 */
function field_account_number(string $name, string $label, string|int|null $value = '', array $opt = []): string
{
    $digits = preg_replace('~\D+~', '', (string)$value) ?? '';
    $len = (int)($opt['len'] ?? App\Core\AccountNumber::LENGTH);
    $suggest = (string)($opt['suggest'] ?? '');
    return '<div class="field">'
        . '<label>' . esc($label) . '</label>'
        . '<div class="digit-field" data-digits="' . esc($name) . '" data-len="' . $len . '"'
        . ($suggest !== '' ? ' data-suggest="' . esc($suggest) . '"' : '') . '>'
        . '<div class="digit-boxes"></div>'
        . '<input type="hidden" name="' . esc($name) . '" value="' . esc($digits) . '">'
        . ($suggest !== ''
            ? '<button class="btn btn-s btn-secondary" type="button" data-digit-suggest>'
              . icon('refresh', 15) . ' ' . esc(t('Подобрать')) . '</button>'
            : '')
        . '</div>'
        . '<div class="hint mt-4" data-digit-status></div>'
        . '</div>';
}

/**
 * Поле пароля с глазом и, если нужно, полосой надёжности.
 *
 * Пароль набирают вслепую, и опечатку видно только по отказу входа — поэтому
 * глаз здесь не украшение. Узор набран отдельно на четырёх экранах (вход,
 * регистрация, сброс, смена в профиле), и уже разошёлся: где-то `minlength`
 * есть, где-то нет; где-то `autocomplete` говорит `new-password`, где-то
 * `current-password` — а от этого зависит, предложит менеджер паролей новый
 * или подставит старый.
 *
 * $opt: new (пароль новый — тогда полоса надёжности и минимум из политики),
 *       autofocus, required (по умолчанию да), error, hint, note, meter.
 */
function field_password(string $name, string $label, array $opt = []): string
{
    $id = $opt['id'] ?? preg_replace('~[^a-z0-9_-]~i', '-', $name);
    $new = !empty($opt['new']);
    $min = $new ? max(1, \App\Core\Setting::passwordMin()) : 0;
    $meter = $new ? ($opt['meter'] ?? $id . '-meter') : '';
    $attrs = ' autocomplete="' . ($new ? 'new-password' : 'current-password') . '"';
    if ($opt['required'] ?? true) $attrs .= ' required';
    if (!empty($opt['autofocus'])) $attrs .= ' autofocus';
    if ($new) {
        $attrs .= ' minlength="' . $min . '" data-pw-meter="' . esc($meter) . '"'
            . ' data-pw-min="' . $min . '"';
    }
    $notes = field_notes($id, $opt);
    return '<div class="field' . (!empty($opt['class']) ? ' ' . esc($opt['class']) : '') . '">'
        . '<label for="' . esc($id) . '">' . esc($label)
        . (!empty($opt['note']) ? ' <span class="hint">' . esc($opt['note']) . '</span>' : '')
        . '</label>'
        . '<div class="input-wrap">'
        . '<input class="input' . (!empty($opt['error']) ? ' is-invalid' : '') . '" type="password"' . $notes['attrs']
        . ' id="' . esc($id) . '" name="' . esc($name) . '"' . $attrs . '>'
        . '<button class="input-eye" type="button" data-pw-toggle="' . esc($id) . '">'
        . esc(t('показать')) . '</button>'
        . '</div>'
        . ($new ? '<div class="pw-meter" id="' . esc($meter) . '"><i></i></div>' : '')
        . $notes['blocks']
        . '</div>';
}

/**
 * Замок опасной зоны: пароль, кнопка и объяснение, зачем он здесь.
 *
 * Одна и та же форма стояла на трёх экранах — портал, организация, человек, —
 * и все три набраны отдельно. Расхождение тут дороже обычного: если на одном
 * экране забыть `required` или `autocomplete`, менеджер паролей подставит не
 * то, а зона откроется. Форма одна, и различается только адрес.
 */
function danger_gate(string $action, string $class = '', array $hidden = [], bool $autofocus = true): string
{
    /* $hidden и $autofocus — с 4.88: объединение аккаунтов открывается тем же
       паролем, но стоит под таблицей сторон, и фокус утащил бы страницу вниз,
       мимо объяснения, что именно произойдёт. */
    $fields = '';
    foreach ($hidden as $k => $v) $fields .= '<input type="hidden" name="' . esc((string)$k) . '" value="' . esc((string)$v) . '">';
    return '<form method="post" action="' . esc($action) . '"'
        . ($class !== '' ? ' class="' . esc($class) . '"' : '') . '>'
        . csrf_field() . $fields
        . field_text('password', t('Ваш пароль'), '', [
            'id' => 'f-danger-pw', 'type' => 'password', 'class' => 'mw-sm',
            'autocomplete' => 'current-password', 'required' => true, 'autofocus' => $autofocus])
        . '<button class="btn btn-secondary" type="submit">'
        . esc(t('Открыть опасную зону')) . '</button>'
        . '<p class="hint mt-8">'
        . esc(t('Пароль спрашиваем именно здесь: от чужой открытой вкладки CSRF-токен не защищает. Зона откроется на 10 минут.'))
        . '</p></form>';
}

/**
 * Подтверждение набором имени: «наберите слаг, чтобы удалить».
 *
 * Поле нарочно моноширинное и с подсказкой-плейсхолдером: человек списывает
 * строку глазами, и «l» от «1» тут обязано отличаться.
 */
function field_confirm_name(string $label, string $expected, array $opt = []): string
{
    return field_text('confirm', $label, '', [
        'id' => $opt['id'] ?? 'f-danger-confirm', 'mono' => true, 'class' => 'mw-sm',
        'autocomplete' => 'off', 'autofocus' => true, 'placeholder' => $expected]);
}

/**
 * Список галочек: выбор нескольких из перечня.
 *
 * Именно галочки, а не тумблеры: у каждой строки нет своего состояния «вкл» —
 * есть общий набор, из которого отмечают нужное (события хука, права токена,
 * порталы в витрине). Граница та же, что описана у field_toggle.
 *
 * $options — значение => подпись или значение => [подпись, машинное имя].
 * Машинное имя показывается моноширинным рядом: в списках вроде `user.created`
 * человек ищет глазами именно его.
 */
function field_checks(string $name, string $label, array $options, array $checked, array $opt = []): string
{
    /* Идентификатор группы — как у остальных полей: от него берут имена
       подсказка и ошибка, на которые ссылается сам fieldset. */
    $id = $opt['id'] ?? 'f-' . preg_replace('~[^a-z0-9_-]~i', '-', $name);
    $rows = '';
    foreach ($options as $value => $label2) {
        [$text, $mono] = is_array($label2) ? $label2 : [$label2, ''];
        $rows .= '<label class="checkbox"><input type="checkbox" name="' . esc($name) . '[]"'
            . ' value="' . esc((string)$value) . '"'
            . (in_array((string)$value, array_map('strval', $checked), true) ? ' checked' : '') . '>'
            . '<span>' . esc((string)$text)
            . ($mono !== '' ? ' <span class="hint mono">' . esc((string)$mono) . '</span>' : '')
            . '</span></label>';
    }
    /* Группа галочек — fieldset с legend, а не div с подписью (v4.47).
       Подпись группы была обычным <label> без адресата: такой label не
       принадлежит ни одному полю, и программа чтения просто не связывает его
       с галочками — человек слышит перечень значений без названия перечня.
       У fieldset это встроенное поведение, и aria-describedby есть куда
       повесить: у группы нет одного органа, к которому его можно было бы
       привязать иначе. */
    $notes = field_notes($id, $opt);
    return '<fieldset class="field field-group' . (!empty($opt['class']) ? ' ' . esc($opt['class']) : '') . '"'
        . $notes['attrs'] . '>'
        . ($label !== '' ? '<legend>' . esc($label) . '</legend>' : '')
        . '<div class="' . esc($opt['layout'] ?? 'stack') . '">' . $rows . '</div>'
        . $notes['blocks']
        . '</fieldset>';
}

/**
 * Вкладки журналов — одним местом (v4.72).
 *
 * Полоса из трёх ссылок была набрана руками в трёх шаблонах; четвёртая
 * вкладка («Запросы») означала бы четыре копии, расходящиеся при первой
 * правке. Запросы видит только глобальный администратор: журнал активности
 * не делится по порталам.
 */
function logs_tabs(string $current): string
{
    $tabs = ['audit' => ['/admin/logs', t('Аудит')]];
    if (App\Core\Auth::isAdmin()) $tabs['requests'] = ['/admin/logs/requests', t('Запросы')];
    $tabs += ['errors' => ['/admin/logs/errors', t('Журнал приложения')], 'mail' => ['/admin/logs/mail', t('Почта')]];
    /* Адреса (v4.95): их видят в журналах и закрывают, глядя на журналы.
       Антифрод (v4.98) — рядом: сводка над теми же журналами. Не пунктом
       навигации: в доке на 360px пять мест, и все заняты. */
    if (App\Core\Auth::isAdmin() && App\Core\Auth::can('settings.read')) {
        $tabs['fraud'] = ['/admin/fraud', t('Антифрод')];
        $tabs['blocks'] = ['/admin/blocks', t('Адреса')];
    }
    $out = '<div class="tabs">';
    foreach ($tabs as $key => [$href, $label]) {
        $out .= '<a' . ($key === $current ? ' class="active"' : '') . ' href="' . esc($href) . '">' . esc($label) . '</a>';
    }
    return $out . '</div>';
}

/**
 * Разница к прошлому периоду у плитки (v4.99; общий с v5.2). Рост неудач —
 * плохо, рост входов — хорошо: цвет говорит «хорошо/плохо», а не «вверх/вниз».
 */
function kpi_delta(array $pair, bool $upIsGood, string $prevLabel): string
{
    $d = App\Core\Stats::delta($pair[0], $pair[1]);
    if ($d === null) return '';
    $good = $upIsGood ? $d >= 0 : $d <= 0;
    return ' · <span class="kpi-delta ' . ($d === 0 ? '' : ($good ? 'is-good' : 'is-bad')) . '">'
        . ($d > 0 ? '+' : '') . $d . ' %</span> ' . esc($prevLabel);
}

/**
 * Переключатель периода ссылками (v4.99; общий с v5.2): адрес можно отправить,
 * «назад» работает. Один на пульт, аналитику и карточку портала.
 */
function period_switch(string $base, int $days): string
{
    $out = '<nav class="period-switch" aria-label="' . esc(t('Период')) . '">';
    foreach (App\Core\Stats::PERIODS as $p) {
        $out .= '<a href="' . esc($base) . '?p=' . $p . '"' . ($p === $days ? ' aria-current="true"' : '') . '>'
            . esc($p === 1 ? t('Сегодня') : t(':n дн.', ['n' => $p])) . '</a>';
    }
    return $out . '</nav>';
}

/**
 * Вкладки «Пульт | Аналитика | Система» и период (v5.1). Период едет
 * с вкладки на вкладку: смотрел «30 дн.» на пульте — и аналитика за 30.
 */
function dash_head(string $current, int $days, string $title): string
{
    $tabs = ['pult' => ['/admin', t('Пульт')], 'analytics' => ['/admin/analytics', t('Аналитика')],
             'system' => ['/admin/analytics/system', t('Система')]];
    $out = '<div class="page-head"><h1>' . esc($title) . '</h1>' . period_switch($tabs[$current][0], $days) . '</div>'
        . '<div class="tabs mb-16">';
    foreach ($tabs as $key => [$href, $label]) {
        $out .= '<a' . ($key === $current ? ' class="active" aria-current="page"' : '') . ' href="' . $href . '?p=' . $days . '">' . esc($label) . '</a>';
    }
    return $out . '</div>';
}

/**
 * Выбор одного из нескольких — карточками с образцом (v4.71).
 *
 * Для выбора, который надо ВИДЕТЬ, а не читать: шрифт показывается шрифтом,
 * раскладка — схемой, скругление — уголком. Выпадающий список с «Inter» и
 * «EB Garamond» заставлял помнить, как они выглядят. Под капотом — обычные
 * радиокнопки в fieldset: работает без скрипта, стрелками и чтением с экрана.
 *
 * $options: значение => ['title' => подпись, 'sample' => HTML образца (только
 * наш, собранный в шаблоне), 'hint' => строка под подписью].
 */
function field_choices(string $name, string $label, array $options, string $value, array $opt = []): string
{
    $id = $opt['id'] ?? 'f-' . preg_replace('~[^a-z0-9_-]~i', '-', $name);
    $cards = '';
    foreach ($options as $v => $o) {
        $cards .= '<label class="choice">'
            . '<input type="radio" name="' . esc($name) . '" value="' . esc((string)$v) . '"'
            . ((string)$v === $value ? ' checked' : '') . (!empty($opt['disabled']) ? ' disabled' : '') . '>'
            . '<span class="choice-body">'
            . (isset($o['sample']) ? '<span class="choice-sample" aria-hidden="true">' . $o['sample'] . '</span>' : '')
            . '<span class="choice-title">' . esc((string)$o['title']) . '</span>'
            . (!empty($o['hint']) ? '<span class="choice-hint">' . esc((string)$o['hint']) . '</span>' : '')
            . '</span></label>';
    }
    $notes = field_notes($id, $opt);
    return '<fieldset class="field field-group' . (!empty($opt['class']) ? ' ' . esc($opt['class']) : '') . '"'
        . $notes['attrs'] . '>'
        . ($label !== '' ? '<legend>' . esc($label) . '</legend>' : '')
        . '<div class="choices' . (!empty($opt['cols']) ? ' choices-' . (int)$opt['cols'] : '') . '">' . $cards . '</div>'
        . $notes['blocks']
        . '</fieldset>';
}

/**
 * Машинная строка, которую забирают отсюда и вставляют в другое место.
 *
 * Client_id, секрет, одноразовая ссылка, redirect URI, секрет TOTP — девять
 * таких мест в пяти файлах, и все девять набраны руками. Различались при этом
 * не задумки, а мелочи: где-то моноширинный шрифт, где-то нет; где-то на
 * кнопке «Копировать», где-то знак ⧉; где-то поле с подписью, где-то без.
 *
 * Шрифт здесь всегда моноширинный: это не текст, это строка символ-в-символ,
 * и в ней важно отличать ноль от буквы O.
 *
 * $value только показывается — само поле readonly. Значение уходит на сервер
 * не отсюда, поэтому имени у поля нет вовсе, есть только id для кнопки.
 */
function field_copy(string $id, string $label, string|int|null $value, array $opt = []): string
{
    $value = (string)$value;
    $notes = field_notes($id, $opt);
    return '<div class="field' . (!empty($opt['class']) ? ' ' . esc($opt['class']) : '') . '">'
        . ($label !== ''
            ? '<label for="' . esc($id) . '">' . esc($label)
              . (!empty($opt['note']) ? ' <span class="hint">' . esc($opt['note']) . '</span>' : '')
              . '</label>'
            : '')
        /* Многострочное — блок JSON и подобное (v4.71): в однострочном поле
           переводы строк пропадали бы, и скопированное было бы не тем, что видно. */
        . '<div class="copyfield' . (!empty($opt['rows']) ? ' copyfield-block' : '') . '">'
        . (!empty($opt['rows'])
            ? '<textarea class="input mono" id="' . esc($id) . '" rows="' . (int)$opt['rows'] . '" readonly' . $notes['attrs'] . '>' . esc($value) . '</textarea>'
            : '<input class="input mono" id="' . esc($id) . '" readonly' . $notes['attrs'] . ' value="' . esc($value) . '">')
        . '<button class="btn btn-secondary" type="button" data-copy="' . esc($id) . '">'
        . esc(t('Копировать')) . '</button>'
        . '</div>'
        . $notes['blocks']
        . '</div>';
}

/**
 * Экран настроек в общей оболочке.
 *
 * Раздел рисует своё содержимое, а колонку разделов, заголовок и крошки
 * ставит оболочка — одна на все шесть бывших отдельных экранов. Без этого
 * каждый экран заводил свою шапку, и они разъезжались при первой же правке.
 */
/**
 * Раздел организации в её оболочке — близнец settings_page().
 *
 * Отдельная функция, а не параметр к той: у оболочек разные карты, разные
 * хлебные крошки и разный источник заголовка. Общее у них — форма, и она
 * общая в шаблонах, а не в вызове.
 */
function object_page(string $map, array $object, string $section, string $template,
                     array $data = [], string $headActions = '', array $counts = [],
                     string $backTo = ''): never
{
    /** @var class-string<\App\Core\ObjectMap> $map */
    $back = $map::backLink();
    /* Возврат в СВОЙ список: с фильтрами, из которых человек сюда пришёл.
       Без этого «назад» ведёт в неотфильтрованный список, и найденное надо
       искать заново — ради чего фильтры и запоминаются (v4.7). */
    if ($backTo !== '') $back[1] = $backTo;
    \App\Core\View::render('admin/object_shell', [
        'map' => $map,
        'object' => $object,
        'section' => $section,
        'counts' => $counts,
        'headActions' => $headActions,
        'backTitle' => $back[0],
        'backTo' => $back[1],
        'inner' => \App\Core\View::fetch($template, $data + ['object' => $object]),
    ] + $data);
}

/** Раздел организации. Тот же механизм, знакомое имя у вызова. */
function org_page(array $org, string $section, string $template, array $data = [],
                  string $headActions = '', array $counts = []): never
{
    object_page(\App\Core\OrgMap::class, $org, $section, $template,
        $data + ['org' => $org], $headActions, $counts);
}

function settings_page(string $section, string $template, array $data = [], string $headActions = ''): never
{
    \App\Core\View::render('admin/settings_shell', [
        'section' => $section,
        'headActions' => $headActions,
        'inner' => \App\Core\View::fetch($template, $data),
    ] + $data);
}

/**
 * Загрузка картинки: превью, поле выбора и снятие — одним объявлением.
 *
 * Узор один и тот же в трёх местах — логотип, favicon, обложка панели, — и
 * везде он был набран руками. Различались при этом мелочи: где-то превью
 * стояло в рамке, где-то без неё, где-то размеры зашиты в style прямо в
 * шаблоне. Различия эти никто не задумывал — они накопились.
 *
 * $preview — готовая разметка превью (картинка или знак): что показывать
 * вместо пустоты, решает вызывающий, а не компонент.
 */
function field_image(string $name, string $label, string $preview, bool $has, array $opt = []): string
{
    $id = $opt['id'] ?? 'f-' . preg_replace('~[^a-z0-9_-]~i', '-', $name);
    $notes = field_notes($id, $opt);
    return '<div class="field">'
        . '<label for="' . esc($id) . '">' . esc($label)
        . (!empty($opt['note']) ? ' <span class="hint">' . esc($opt['note']) . '</span>' : '')
        . '</label>'
        . '<div class="field-image">'
        . '<span class="field-image-preview">' . $preview . '</span>'
        . '<input class="input" id="' . esc($id) . '" type="file" name="' . esc($name) . '"' . $notes['attrs']
        . ' accept="' . esc($opt['accept'] ?? 'image/*') . '">'
        . '</div>'
        /* Галочка «убрать» появляется только когда есть что убирать: у пустого
           места она предлагает снять то, чего нет. */
        . ($has
            ? '<label class="checkbox mt-8"><input type="checkbox" name="' . esc($name) . '_delete" value="1">'
              . '<span>' . esc($opt['remove'] ?? t('Убрать')) . '</span></label>'
            : '')
        . $notes['blocks']
        . '</div>';
}

/**
 * Акцентный цвет: поле с hex и пипетка рядом.
 *
 * Пипетка и поле обязаны ходить парой: значение правят и так, и так, а
 * рассинхронизация даёт цвет, который человек видит в квадрате, но не в
 * сохранённой строке.
 */
function field_color(string $name, string $label, string $value, string $resolved, array $opt = []): string
{
    $id = $opt['id'] ?? 'f-' . preg_replace('~[^a-z0-9_-]~i', '-', $name);
    $notes = field_notes($id, $opt);
    return '<div class="field">'
        . '<label for="' . esc($id) . '">' . esc($label)
        . (!empty($opt['note']) ? ' <span class="hint">' . esc($opt['note']) . '</span>' : '')
        . '</label>'
        . '<div class="copyfield">'
        . '<input class="input mono" id="' . esc($id) . '" name="' . esc($name) . '"' . $notes['attrs']
        . ' value="' . esc($value) . '" placeholder="' . esc($opt['placeholder'] ?? '#1D4ED8') . '"'
        . ' maxlength="7" data-accent-hex>'
        . '<input class="input-color" type="color" value="' . esc($resolved) . '"'
        . ' aria-label="' . esc(t('Выбрать цвет пипеткой')) . '" data-accent-pick>'
        . '</div>'
        . $notes['blocks']
        . '</div>';
}
