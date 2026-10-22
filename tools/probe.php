<?php
/**
 * Прогон несчастливых путей: каждый маршрут получает мусор вместо данных.
 *
 * Батарея проверяет то, ради чего писалась, — что система делает правильно.
 * Здесь наоборот: что она делает, когда её кормят пустотой, чужими номерами и
 * буквами вместо чисел. Такие пути никто не пишет специально, и падают они
 * ровно тогда, когда до них добирается живой человек.
 *
 * Ищем два признака беды:
 *   · 500 и голый фатал — приложение не удержалось;
 *   · текст исключения в теле — удержалось, но показало кишки постороннему.
 *
 * Ожидаемое поведение: 200 со внятной страницей, 302 на вход или список,
 * 403/404/422. Всё это — ответы, а не поломки.
 *
 * Запуск: php tools/probe.php  (нужен dev-сервер на :8710 и свежая установка)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../tests/lib.php';

const SSO = 'http://localhost:8710';
$JAR = __DIR__ . '/../tests/tmp/probe.txt';
@unlink($JAR);

$passed = 0;
$failed = 0;
$found = [];

/* Маршруты берём из самого файла маршрутов, а не списком руками: список руками
   отстаёт от кода ровно с той правки, о которой забыли. */
$src = (string)file_get_contents(SSO_ROOT . '/app/routes.php');
/* Класс пишется и коротко (UsersController), и полным путём
   (App\Controllers\LockController) — берём оба вида, иначе треть маршрутов
   останется без разобранного обработчика и без проверки параметров. */
preg_match_all("~\\\$router->(get|post)\('([^']+)'(?:\s*,\s*\[([A-Za-z\\\\]+)::class\s*,\s*'([a-zA-Z]+)'\])?(?:,[^;]*?\['([a-z, ']*)'\])?~",
    $src, $m, PREG_SET_ORDER);

$routes = [];
foreach ($m as $r) {
    $cls = $r[3] ?? '';
    $short = ($p = strrpos($cls, '\\')) === false ? $cls : substr($cls, $p + 1);
    $routes[] = ['method' => strtoupper($r[1]), 'path' => $r[2],
                 'ctrl' => $short, 'action' => $r[4] ?? '', 'flags' => $r[5] ?? ''];
}

/**
 * Какие параметры адреса читает обработчик.
 *
 * Дыра, которую это закрывает: подставлялись только части ПУТИ («{id}»), а всё,
 * что приходит вопросительным знаком, не проверялось никогда. Так и вышло:
 * `/admin/logs/errors` фаззер брал «как есть» и был доволен, а с `?incident=…`
 * туда никто не заглядывал — при том, что весь смысл этого адреса именно в
 * параметре.
 *
 * Имена берём из самого обработчика: контроллер — единственный, кто знает, что
 * он читает, а список руками отстанет с первой же правки.
 *
 * @return list<string>
 */
$queryKeys = static function (string $ctrl, string $action) use (&$ctrlCache): array {
    if ($ctrl === '') return [];
    $files = (array)glob(SSO_ROOT . '/app/Controllers/{,*/}' . $ctrl . '.php', GLOB_BRACE);
    if ($files === []) return [];
    $body = (string)file_get_contents((string)$files[0]);
    /* Метод целиком: от «function имя(» до следующего «    function» того же
       уровня. Грубо, но нам нужны только имена ключей. */
    if (preg_match('~function\s+' . preg_quote($action, '~') . '\s*\(.*?(?=\n    (?:public|private|protected|/\*\*)|\z)~s',
        $body, $mm)) {
        $body = $mm[0];
    }
    preg_match_all('~\$_GET\[\'([a-z_]+)\'\]~i', $body, $g);
    return array_values(array_unique($g[1]));
};

/* Чем подставляем параметры. Каждое значение — свой вид беды: не существует,
   ноль, не число, обход каталога, инъекция. */
$fillings = [
    'нет такого' => '999999',
    'ноль' => '0',
    'буквы' => 'abc',
    'обход' => '..%2F..%2Fetc%2Fpasswd',
    'кавычка' => "1'--",
];

/* Куда не ходим: выключает сервер, ломает установку или уводит наружу. */
$skip = [
    '/admin/system/reset/run', '/admin/system/db/transfer', '/admin/system/domain/apply',
    '/logout', '/lang/{code}', '/cron/{token}',
];

/* Страницы, которые ПОКАЗЫВАЮТ чужие ошибки: журнал приложения, его выгрузка и
   сводка в «Здоровье». Текст фатала в их теле — содержимое, а не симптом, и
   проверка по словам ловила бы саму себя: записала ошибку, показала её, приняла
   за новую. Список, а не один путь: раздел «Здоровье» появился позже журнала и
   тут же дал ложную тревогу — «must be of type» из записи пятидневной давности.
   Код ответа у этих страниц проверяется как везде. */
$showErrors = ['/admin/logs/errors', '/admin/health/errors'];

/**
 * Улика: где на странице нашлась примета.
 *
 * Раньше прозвон печатал одно слово — «SQLSTATE», — и дальше приходилось
 * гадать, откуда оно: из отчёта импорта, из флеш-сообщения, из ответа
 * драйвера. В v4.58 на эту угадайку ушло четыре захода, и две догадки из трёх
 * оказались неверными. Окружение режем так же, как его видит детектор: без
 * разметки и без цитат в code/pre.
 */
function evidence(string $body, string $needle): string
{
    if (str_starts_with($needle, 'HTTP ')) return '';
    $text = (string)preg_replace('~<(code|pre)\b[^>]*>.*?</\1>~is', '', $body);
    $text = trim((string)preg_replace('~\s+~u', ' ', strip_tags($text)));
    $pos = mb_stripos($text, $needle);
    if ($pos === false) return '';
    return "\n        «…" . mb_substr($text, max(0, $pos - 90), 240) . '…»';
}

/**
 * Похоже ли на поломку.
 *
 * $showsErrors — см. $showErrors выше.
 */
function looksBroken(int $code, string $body, bool $showsErrors = false): ?string
{
    if ($code >= 500) return "HTTP $code";
    if ($showsErrors) return null;
    /* Процитированный текст — не утечка. Страница «О проекте» показывает
       changelog, а в нём разбор боевого отказа: запись про «1265 Data truncated
       for column» роняла эту проверку на ровном месте.

       Различие не косметическое: утечка приходит сырым текстом или дампом
       исключения, а цитата — внутри `code`/`pre`, потому что её туда поставили
       руками. Ослаблять список примет нельзя, а вырезать разметку цитат —
       можно: то, что оформлено как код, кодом и является. */
    $body = (string)preg_replace('~<(code|pre)\b[^>]*>.*?</\1>~is', '', $body);
    foreach (['Fatal error', 'Uncaught', 'Parse error', 'Stack trace',
              'SQLSTATE', 'PDOException', 'TypeError', 'ValueError',
              'Undefined array key', 'Undefined variable', 'Undefined property',
              'Attempt to read property', 'Trying to access array offset',
              'must be of type', 'Division by zero'] as $needle) {
        if (str_contains($body, $needle)) return $needle;
    }
    return null;
}

/* Лимит входа — пять попыток с адреса в минуту, удачные тоже считаются. Шаг
   перед нами (мутации, раз за разом гоняющие portal_connect) выбирает этот
   бюджет, и вход падал в 429 ещё до первого маршрута. Зонд проверяет маршруты,
   а не лимитер — сбрасываем счётчики, как portal_connect перед своими входами. */
App\Core\DB::run('DELETE FROM {rate_limits}');

echo "Вход администратором…\n";
[, $loginPage] = http('GET', SSO . '/login');
$csrf = csrfOf($loginPage);
[$st] = http('POST', SSO . '/login',
    ['_csrf' => $csrf, 'identity' => 'admin@test.local', 'password' => 'correct-horse-battery']);
if ($st !== 302) {
    echo "не вошли (status $st) — дальше смысла нет\n";
    exit(1);
}
[, $home] = http('GET', SSO . '/admin');
$csrf = csrfOf($home);

$checked = 0;
foreach ($routes as $r) {
    if (in_array($r['path'], $skip, true)) continue;
    if (str_contains($r['flags'], 'api')) continue;   // у них свой формат ответа

    $variants = [];
    if (str_contains($r['path'], '{')) {
        foreach ($fillings as $why => $value) {
            $variants[$why] = (string)preg_replace('~\{[a-z_]+\}~', $value, $r['path']);
        }
    } else {
        $variants['как есть'] = $r['path'];
    }

    /* И то же самое — в параметрах адреса. Пустое значение тоже подставляем:
       «?incident=» без значения — обычное дело, когда форму отправили пустой. */
    if ($r['method'] === 'GET') {
        $base = $variants['как есть'] ?? reset($variants);
        foreach ($queryKeys($r['ctrl'], $r['action']) as $key) {
            foreach ($fillings + ['пусто' => ''] as $why => $value) {
                $variants['?' . $key . ' ' . $why] = $base . '?' . $key . '=' . rawurlencode($value);
            }
        }
    }

    foreach ($variants as $why => $path) {
        $checked++;
        if ($r['method'] === 'GET') {
            [$code, $body] = http('GET', SSO . $path);
        } else {
            /* CSRF настоящий: без него всё вернёт 419 и проверка выродится в
               проверку CSRF, а не обработчика. */
            [$code, $body] = http('POST', SSO . $path, ['_csrf' => $csrf]);
        }
        $shows = false;
        foreach ($showErrors as $p) {
            if (str_starts_with($r['path'], $p)) { $shows = true; break; }
        }
        $bad = looksBroken($code, $body, $shows);
        if ($bad === null) { $passed++; continue; }
        $failed++;
        $found[] = sprintf('%-4s %-46s %-12s → %s', $r['method'], $path, $why, $bad) . evidence($body, $bad);
    }
}

/* ---- Второй проход: то же самое, но БЕЗ сессии ----------------------------
   Первый проход отвечает «не падает ли». Этот — «а не отдаёт ли постороннему».
   Закрытый маршрут обязан ответить перенаправлением на вход, 403 или 404, но
   не страницей с данными. Проверка блокирующая: пустить чужого в админку хуже,
   чем упасть, — падение видно сразу, а утечка не видна никогда. */
$anon = SSO_ROOT . '/tests/tmp/probe-anon.txt';
@unlink($anon);
$leaked = [];
$anonChecked = 0;
foreach ($routes as $r) {
    if ($r['method'] !== 'GET') continue;
    if (!str_contains($r['flags'], 'admin') && !str_contains($r['flags'], 'auth')) continue;
    if (in_array($r['path'], $skip, true)) continue;
    $path = (string)preg_replace('~\{[a-z_]+\}~', '1', $r['path']);
    $anonChecked++;
    [$code, $body] = http('GET', SSO . $path, [], [], $anon);
    /* 200 у закрытого маршрута само по себе не приговор: страница ошибки тоже
       иногда отдаётся с 200. Смотрим на признак настоящего содержимого —
       островок навигации рисуется только вошедшему. */
    if ($code === 200 && str_contains($body, 'class="isle"')) {
        $leaked[] = sprintf('%-46s → 200 с интерфейсом', $path);
    }
}
@unlink($anon);

/* ---- Третий проход: вошедший, но БЕЗ прав ---------------------------------
   Аноним и так уводится на вход — этим вторым проходом эскалацию не поймать.
   Настоящий вопрос другой: что видит обычный человек, набравший /admin/users
   руками. Именно этот случай появляется вместе с первым живым сервисом, когда
   в базе перестают быть одни администраторы. */
$plainEmail = 'probe-plain@test.local';
$plainPass = 'probe-plain-' . bin2hex(random_bytes(4));
$plain = \App\Models\User::findByEmail($plainEmail);
if (!$plain) {
    $pid = \App\Models\User::create([
        'email' => $plainEmail, 'status' => 'active',
        'password_hash' => password_hash($plainPass, PASSWORD_ARGON2ID),
    ]);
    $role = \App\Models\Role::findBySlug('user');
    if ($role) \App\Models\Role::assign($pid, (int)$role['id']);
} else {
    \App\Models\User::update((int)$plain['id'],
        ['password_hash' => password_hash($plainPass, PASSWORD_ARGON2ID), 'status' => 'active']);
}

$plainJar = SSO_ROOT . '/tests/tmp/probe-plain.txt';
@unlink($plainJar);
[, $plp] = http('GET', SSO . '/login', [], [], $plainJar);
[$plainSt] = http('POST', SSO . '/login',
    ['_csrf' => csrfOf($plp), 'identity' => $plainEmail, 'password' => $plainPass], [], $plainJar);

$escalated = [];
$plainChecked = 0;
if ($plainSt === 302) {
    foreach ($routes as $r) {
        /* Отбор по АДРЕСУ, а не по объявленному флагу. Флаг — то самое, что
           забывают поставить, и проверка «беру маршруты с флагом admin» на
           забытом флаге просто перестаёт видеть маршрут. Всё под /admin/
           обязано быть закрыто по факту существования, а не по объявлению. */
        if ($r['method'] !== 'GET' || !str_starts_with($r['path'], '/admin')) continue;
        if (in_array($r['path'], $skip, true)) continue;
        $path = (string)preg_replace('~\{[a-z_]+\}~', '1', $r['path']);
        $plainChecked++;
        [$code] = http('GET', SSO . $path, [], [], $plainJar);
        /* Признак — сам код ответа. Замер показал: сегодня ВСЕ тридцать
           админских маршрутов отвечают обычному человеку 403, без единого
           исключения. Значит любой успешный ответ здесь — отклонение, и
           проверять содержимое не нужно.
           Признак «есть ли в теле админская навигация» не годился: её рисуют
           по роли смотрящего, и у обычного человека её не будет даже на
           странице, которую ему показали по ошибке. */
        if ($code >= 200 && $code < 300) {
            $escalated[] = sprintf('%-46s → HTTP %d вместо отказа', $path, $code);
        }
    }
} else {
    $escalated[] = 'обычный пользователь не смог войти — проход не выполнен';
}
@unlink($plainJar);
/* Подопытного за собой убираем: проба гоняется в батарее, и оставленный
   аккаунт менял бы числа тем проверкам, которые считают людей. */
$plainRow = \App\Models\User::findByEmail($plainEmail);
if ($plainRow) {
    \App\Core\DB::run('DELETE FROM {user_roles} WHERE user_id = ?', [(int)$plainRow['id']]);
    \App\Core\DB::run('DELETE FROM {sessions} WHERE user_id = ?', [(int)$plainRow['id']]);
    \App\Core\DB::run('DELETE FROM {users} WHERE id = ?', [(int)$plainRow['id']]);
}

echo "Проверено обращений: $checked (со входом) + $anonChecked (без входа)"
    . " + $plainChecked (без прав)\n\n";
if ($escalated !== []) {
    echo 'ПУСКАЕТ БЕЗ ПРАВ ' . count($escalated) . ":\n";
    foreach ($escalated as $line) echo "  ✗ $line\n";
    $found = array_merge($found, $escalated);
}
if ($leaked !== []) {
    echo 'ОТДАЁТ ПОСТОРОННЕМУ ' . count($leaked) . ":\n";
    foreach ($leaked as $line) echo "  ✗ $line\n";
    $found = array_merge($found, $leaked);
}
if ($found === []) {
    echo "ЧИСТО: ни одного фатала, ни одной 500-й, ни одной утечки\n";
    exit(0);
}
echo "НАЙДЕНО " . count($found) . ":\n";
foreach ($found as $line) echo "  ✗ $line\n";
exit(1);
