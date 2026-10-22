<?php
/**
 * Общее начало для всех файлов набора: конфиг, секреты, сессия.
 *
 * Три вещи, каждая из которых иначе ломается тихо.
 *
 * 1. КОНФИГ ЧИТАЕТСЯ В ОДНОМ МЕСТЕ. Пока «настроен ли вход» и «где взять
 *    секрет» отвечают разные куски кода, они рано или поздно расходятся — и
 *    получается кнопка входа, которая на нажатие отвечает «вход не настроен».
 *    Все файлы набора ходят сюда, и переопределение источника — одна правка.
 *
 * 2. СЕКРЕТЫ ЛЕЖАТ ВНЕ ВЕБ-КОРНЯ. Каталог sso/ кладут в веб-корень и, как
 *    правило, в git. Секрет внутри него — это секрет в репозитории. Установщик
 *    (install.php) и экран настроек (admin.php) пишут их отдельным файлом
 *    уровнем выше; здесь мы его ищем и пишем.
 *
 * 3. СЕССИЯ ОТКРЫВАЕТСЯ ВАША, А НЕ НАША. Голый session_start() у сайта со
 *    своим session_name или своим обработчиком заводит ДРУГУЮ сессию: вход
 *    проходит, $_SESSION['sso_user'] пишется — и приложение его не видит.
 *    Симптом обманчивый: «вошёл, но я гость», без единой ошибки.
 */

/**
 * Открыть сессию приложения.
 *
 * ЕСЛИ У ВАШЕГО САЙТА ЕСТЬ СВОЙ БУТСТРАП — подключите его здесь, вместо
 * session_start(): нужно, чтобы это была та же сессия, в которой живёт
 * остальное приложение.
 *
 *     require_once __DIR__ . '/../app/bootstrap.php';   // ваш бутстрап
 *
 * Если сайт плоский и своей сессии у него нет — оставьте как есть.
 */
function sso_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_start();
}

/**
 * Конфиг набора. Единственная точка: и вход, и виджет, и проверка
 * «показывать ли кнопку» спрашивают здесь.
 *
 * $fresh — перечитать: после того как экран настроек сохранил новый секрет,
 * проверка в том же запросе должна видеть уже его, а не прежний.
 *
 * @return array<string, mixed>
 */
function sso_config(bool $fresh = false): array
{
    static $cfg = null;
    if ($cfg !== null && !$fresh) return $cfg;

    $cfg = require __DIR__ . '/config.php';

    /* Секреты снаружи важнее того, что лежит в config.php: файл в веб-корне
       остаётся с плейсхолдерами, а настоящие значения приходят из хранилища,
       которое положил установщик или экран настроек. */
    foreach (sso_secrets($fresh) as $k => $v) {
        if (is_string($v) && $v !== '') $cfg[$k] = $v;
    }
    return $cfg;
}

/**
 * Переменная окружения набора: SSO_* с версии 4.89, прежняя TSOY_* — до 5.0.
 *
 * @return array{0: string, 1: string}  [значение, имя переменной, из которой оно]
 */
function sso_env(string $name): array
{
    foreach (['SSO_' . $name, 'TSOY_' . $name] as $var) {
        $v = getenv($var);
        if (is_string($v) && $v !== '') return [$v, $var];
    }
    return ['', 'SSO_' . $name];
}

/**
 * Где искать файл секретов — в этом порядке, первый читаемый выигрывает:
 * путь из SSO_SECRETS_FILE → ../sso-secrets.php → ../../sso-secrets.php →
 * sso/var/secrets.php (запасной, когда уровнем выше писать нельзя — тогда
 * доступ к нему закрывает .htaccess, который кладётся туда же). Прежние имена
 * (TSOY_SECRETS_FILE, tsoy-secrets.php — до 4.89) читаются до 5.0: портал,
 * установленный раньше, продолжает работать со своим файлом.
 *
 * @return list<string>
 */
function sso_secret_paths(): array
{
    return array_values(array_filter([
        sso_env('SECRETS_FILE')[0] ?: null,
        dirname(__DIR__) . '/sso-secrets.php',
        dirname(__DIR__) . '/tsoy-secrets.php',
        dirname(__DIR__, 2) . '/sso-secrets.php',
        dirname(__DIR__, 2) . '/tsoy-secrets.php',
        __DIR__ . '/var/secrets.php',
    ]));
}

/**
 * Секреты: переменные окружения, затем файл вне веб-корня, если он есть.
 *
 * @return array<string, string>
 */
function sso_secrets(bool $fresh = false): array
{
    static $found = null;
    if ($found !== null && !$fresh) return $found;

    $found = [];
    foreach (['client_secret' => 'CLIENT_SECRET', 'users_secret' => 'USERS_SECRET'] as $key => $env) {
        $v = sso_env($env)[0];
        if ($v !== '') $found[$key] = $v;
    }

    foreach (sso_secret_paths() as $p) {
        if (!is_file($p) || !is_readable($p)) continue;
        $data = require $p;
        if (is_array($data)) $found += array_filter($data, 'is_string');
        break;
    }
    return $found;
}

/**
 * Откуда пришло значение секрета — для экрана настроек и проверки связи.
 * Само значение не возвращается никогда. 'config.php' — дословно: так проверка
 * узнаёт секрет, лежащий в веб-корне.
 */
function sso_secret_source(string $key): string
{
    $env = ['client_secret' => 'CLIENT_SECRET', 'users_secret' => 'USERS_SECRET'][$key] ?? '';
    if ($env !== '') {
        [$v, $var] = sso_env($env);
        if ($v !== '') return 'из переменной окружения ' . $var;
    }
    foreach (sso_secret_paths() as $p) {
        if (!is_file($p) || !is_readable($p)) continue;
        $data = require $p;
        return is_array($data) && is_string($data[$key] ?? null) && $data[$key] !== ''
            ? 'из файла ' . $p : 'config.php';
    }
    return 'config.php';
}

/**
 * Записать секреты — поверх уже лежащих (v4.63).
 *
 * Одна функция на установщика и на экран настроек. Раньше писал только
 * установщик, и сменить секрет или вписать ключ переноса после установки было
 * нечем, кроме редактора на сервере.
 *
 * Пишем туда, где файл уже лежит: новый файл в другом месте оказался бы в тени
 * старого и не поменял бы ничего. Файла нет — сперва выше веб-корня, потом в
 * sso/var/ под .htaccess. Записанное читаем обратно: файл, который записался,
 * но не читается, хуже незаписанного — он выглядит сделанным.
 *
 * @param array<string, string> $changes
 * @return array{ok: bool, path: ?string, error: ?string}
 */
function sso_save_secrets(array $changes): array
{
    $current = [];
    $existing = null;
    foreach (sso_secret_paths() as $p) {
        if (!is_file($p) || !is_readable($p)) continue;
        $data = require $p;
        if (is_array($data)) $current = array_filter($data, 'is_string');
        $existing = $p;
        break;
    }
    $data = array_filter(array_merge($current, $changes), static fn($v) => is_string($v) && $v !== '');
    $body = "<?php\n/* Реквизиты подключения к {{APP_NAME}}. Записано " . date('Y-m-d H:i')
        . ".\n   Файл секретный: не в git, не в веб-корень, права 0600. */\nreturn "
        . var_export($data, true) . ";\n";

    /* Есть файл — пишем в него, как бы он ни назывался (у порталов, установленных
       до 4.89, это tsoy-secrets.php). Нет — заводим под новым именем. */
    $targets = $existing !== null ? [$existing] : array_values(array_filter([
        sso_env('SECRETS_FILE')[0] ?: null,
        dirname(__DIR__, 2) . '/sso-secrets.php',
        dirname(__DIR__) . '/sso-secrets.php',
        __DIR__ . '/var/secrets.php',
    ]));

    foreach ($targets as $path) {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (!is_dir($dir) || !is_writable($dir) || (is_file($path) && !is_writable($path))) continue;
        if (@file_put_contents($path, $body, LOCK_EX) === false) continue;
        @chmod($path, 0600);
        /* Запасной путь лежит внутри веб-корня — закрываем его как умеем.
           Это второй пояс: первый — что файл ничего не печатает. */
        if (str_starts_with($path, __DIR__ . DIRECTORY_SEPARATOR . 'var')
            || str_starts_with($path, __DIR__ . '/var')) {
            @file_put_contents(dirname($path) . '/.htaccess',
                "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        /* Файл секретов — PHP, и OPcache отдал бы прежнюю версию ещё до
           revalidate_freq: «сохранил, а работает старый ключ». */
        if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
        $check = @include $path;
        foreach ($data as $k => $v) {
            if (!is_array($check) || ($check[$k] ?? null) !== $v) {
                return ['ok' => false, 'path' => $path, 'error' => 'файл записан, но не читается обратно'];
            }
        }
        sso_config(true);
        return ['ok' => true, 'path' => $path, 'error' => null];
    }
    return ['ok' => false, 'path' => null, 'error' => $existing !== null
        ? 'файл ' . $existing . ' не открыт на запись'
        : 'некуда записать: ни один из каталогов не открыт на запись'];
}

/**
 * Настроен ли вход. Спрашивать надо ИМЕННО ЭТУ функцию — и на странице входа
 * тоже: кнопка, которая показывается по одному условию, а работает по другому,
 * ведёт человека в тупик.
 */
function sso_ready(): bool
{
    $cfg = sso_config();
    foreach (['issuer', 'client_id', 'client_secret', 'redirect_uri'] as $k) {
        $v = (string)($cfg[$k] ?? '');
        if ($v === '' || str_contains($v, '{{')) return false;
    }
    return true;
}
