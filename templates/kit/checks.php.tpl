<?php
/**
 * Проверка связи с {{APP_NAME}} — одна на всех (v4.63).
 *
 * Её показывают экран настроек (admin.php), установщик (install.php) и консоль
 * (`php sso/admin.php check`). До 4.63 проверок было две — в установщике и в
 * selftest.php, — и списки разошлись: одна знала про OPcache, другая про ключи
 * подписи, и ни одна не спрашивала у издателя, принимает ли он секрет.
 *
 * Строка — [что проверяли, состояние, подробности]. Состояние: ok — есть;
 * warn — не всё (работает, но не целиком или проверить не вышло); fail — нет
 * (вход, события или перенос не заработают). Секреты не печатаются никогда:
 * только «есть» и длина.
 *
 * Ходит к издателю и к самому сайту. Ничего не сохраняет и никого не впускает.
 */

require_once __DIR__ . '/bootstrap.php';

/** Короткий HTTP без зависимостей. */
function sso_http(string $method, string $url, array $post = [], int $timeout = 15, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'error' => $err];
}

/** «HTTP 0» ничего не объясняет: это не ответ, это его отсутствие. */
function sso_code_str(int $code): string
{
    return $code === 0 ? 'не отвечает' : 'HTTP ' . $code;
}

/** Только заголовки — нужны, чтобы сверить часы по Date сервера. */
function sso_head(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true,
        CURLOPT_HEADER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
    $out = (string)curl_exec($ch);
    curl_close($ch);
    return $out;
}

/**
 * Каким сайт виден снаружи. Из консоли этого не узнать — там берётся origin
 * адреса возврата, и сверка «совпадает ли он с этим сайтом» теряет смысл;
 * проверка об этом так и скажет.
 */
function sso_self_base(): string
{
    if (!empty($_SERVER['HTTP_HOST'])) {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST'];
    }
    $p = parse_url((string)(sso_config()['redirect_uri'] ?? ''));
    return isset($p['scheme'], $p['host'])
        ? $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '')
        : '';
}

/**
 * Принимает ли издатель этот секрет — не получая ничего.
 *
 * /token сверяет клиента раньше, чем код: чужой секрет получает invalid_client,
 * свой — invalid_grant на заведомо негодный код. Так ключ проверяется ещё до
 * сохранения, и опечатка при вставке не ломает вход всем.
 *
 * @return array{0: ?bool, 1: string} [принят (null — проверить не вышло), подробности]
 */
function sso_probe_secret(string $secret): array
{
    $cfg = sso_config();
    $issuer = rtrim((string)($cfg['issuer'] ?? ''), '/');
    $res = sso_http('POST', $issuer . '/token', [
        'grant_type' => 'authorization_code',
        'code' => 'sso-admin-probe',
        'redirect_uri' => (string)($cfg['redirect_uri'] ?? ''),
        'code_verifier' => str_repeat('p', 43),
        'client_id' => (string)($cfg['client_id'] ?? ''),
        'client_secret' => $secret,
    ], 10);
    if ($res['code'] === 0) return [null, 'издатель не ответил — проверить не вышло'];
    $error = (string)(json_decode($res['body'], true)['error'] ?? '');
    if ($error === 'invalid_client') {
        return [false, 'издатель не принял секрет: он не тот, что сейчас в карточке портала'];
    }
    if ($error !== '') return [true, 'издатель принимает секрет'];
    return [null, 'издатель ответил ' . sso_code_str($res['code']) . ' без кода ошибки — проверить не вышло'];
}

/**
 * Вся батарея.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function sso_checks(): array
{
    $rows = [];
    $add = static function (string $what, string $state, string $detail = '') use (&$rows): void {
        $rows[] = [$what, $state, $detail];
    };
    $filled = static fn($v): bool => is_string($v) && $v !== '' && !str_contains($v, '{{');

    $cfg = sso_config(true);
    $issuer = rtrim((string)($cfg['issuer'] ?? ''), '/');
    $clientId = (string)($cfg['client_id'] ?? '');
    $redirect = (string)($cfg['redirect_uri'] ?? '');
    $self = sso_self_base();

    /* ---------- Мост ---------- */
    $ready = $filled($issuer) && $filled($clientId);
    $add('Настройки заполнены', $ready ? 'ok' : 'fail',
        $ready ? $issuer . ' · ' . $clientId : 'в config.php нет issuer или client_id — набор скачан не из карточки портала?');

    $secret = (string)($cfg['client_secret'] ?? '');
    if (!$filled($secret)) {
        $add('Секрет клиента на месте', 'fail', 'нет — введите его на экране настроек или откройте ссылку установки');
    } else {
        $source = sso_secret_source('client_secret');
        $add('Секрет клиента на месте', $source === 'config.php' ? 'warn' : 'ok', 'длина ' . strlen($secret) . ' · '
            . ($source === 'config.php' ? 'лежит в config.php, в веб-корне — сохраните его на экране настроек, он уедет в файл вне веб-корня' : $source));
    }
    if ($ready && $filled($secret)) {
        [$accepted, $why] = sso_probe_secret($secret);
        $add('Издатель принимает секрет', $accepted === true ? 'ok' : ($accepted === false ? 'fail' : 'warn'), $why);
    }

    $scopes = ' ' . (string)($cfg['scopes'] ?? '') . ' ';
    $add('Области запрошены', str_contains($scopes, ' roles ') ? 'ok' : 'warn', trim($scopes)
        . (str_contains($scopes, ' roles ') ? '' : ' — без roles не приедут ни роли, ни уровень доступа'));

    if (PHP_SAPI === 'cli') {
        $add('Адрес возврата совпадает с этим сайтом', 'warn', $redirect . ' — из консоли не видно, каким сайт виден снаружи');
    } else {
        $expected = $self . '/sso/callback.php';
        $add('Адрес возврата совпадает с этим сайтом', $redirect === $expected ? 'ok' : 'fail', $redirect === $expected
            ? $redirect : 'в конфиге «' . $redirect . '», отсюда видно «' . $expected . '» — сравнение побайтное');
    }

    if ($filled($issuer)) {
        /* Discovery отдельно от ключей: у него привычка попадать под правила,
           которые режут всё, что начинается с точки. */
        $disc = sso_http('GET', $issuer . '/.well-known/openid-configuration', [], 8);
        $iss = rtrim((string)(json_decode($disc['body'], true)['issuer'] ?? ''), '/');
        $add('Discovery издателя', $disc['code'] === 200 && $iss === $issuer ? 'ok' : 'fail', $disc['code'] === 200
            ? 'HTTP 200, iss ' . ($iss !== '' ? $iss : '—') . ($iss === $issuer ? '' : ' — не совпадает с issuer в config.php')
            : sso_code_str($disc['code']) . ' — на стороне {{APP_NAME}} режется .well-known');

        $jwks = sso_http('GET', $issuer . '/jwks.json', [], 8);
        $keys = (array)(json_decode($jwks['body'], true)['keys'] ?? []);
        $kids = array_values(array_filter(array_map(static fn($k) => is_array($k) ? (string)($k['kid'] ?? '') : '', $keys)));
        $add('Ключи подписи (jwks.json)', $jwks['code'] === 200 && $keys !== [] ? 'ok' : 'fail', $jwks['code'] === 200
            ? 'ключей: ' . count($keys) . ($kids !== [] ? ' · kid ' . implode(', ', $kids) : '')
            : sso_code_str($jwks['code']));

        /* Знает ли издатель наш адрес возврата: /authorize без сессии отвечает
           302 на свой вход, если адрес записан в карточке, и 400 — если нет. */
        if ($filled($clientId) && $redirect !== '') {
            $auth = sso_http('GET', $issuer . '/authorize?' . http_build_query([
                'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect,
                'scope' => 'openid', 'state' => 'sso-check', 'nonce' => 'sso-check',
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', 'sso-check', true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256',
            ]), [], 8);
            $add('Издатель знает адрес возврата', $auth['code'] === 302 ? 'ok' : 'fail', $auth['code'] === 302
                ? 'HTTP 302'
                : sso_code_str($auth['code']) . ' — адреса ' . $redirect . ' нет в карточке портала или портал выключен;'
                    . ' присланный адрес {{APP_NAME}} показывает там с кнопкой «Добавить»');
        }

        /* Часы: расхождение больше минуты — и токены «протухают» сразу после
           выдачи, а сообщение об этом ни на что не намекает. */
        $skew = null;
        if (preg_match('~^Date:\s*(.+)$~mi', sso_head($issuer . '/jwks.json'), $dm)) {
            $theirs = strtotime(trim($dm[1]));
            if ($theirs) $skew = abs(time() - $theirs);
        }
        $add('Часы сходятся', $skew === null ? 'warn' : ($skew <= 60 ? 'ok' : 'fail'), $skew === null
            ? 'сверить не вышло — проверьте NTP сами'
            : 'расхождение ' . $skew . ' с' . ($skew <= 60 ? '' : ' — токены будут протухать, включите NTP'));
    }

    /* ---------- Портал ----------
       Запросы к самому себе — с коротким сроком. Если сайт обслуживает один
       процесс (встроенный сервер PHP, php-fpm с одним воркером), такой запрос
       ждёт того самого процесса, который его делает, и не дождётся. Молчание
       здесь не приговор, а «проверьте руками». */
    $man = $self !== '' ? sso_http('GET', $self . '/.well-known/sso-portal.json', [], 3) : ['code' => 0, 'body' => ''];
    $manJson = json_decode((string)$man['body'], true);
    if ($man['code'] === 200 && is_array($manJson)) {
        $add('Манифест портала', 'ok', 'HTTP 200 · ролей: ' . count((array)($manJson['roles'] ?? [])));
    } elseif ($man['code'] === 0) {
        $add('Манифест портала', 'warn', 'проверить не вышло — curl -i ' . $self . '/.well-known/sso-portal.json');
    } else {
        $add('Манифест портала', 'warn', sso_code_str($man['code'])
            . ' — без манифеста не приедут ни роли, ни перенос людей (integration.md §10)');
    }

    $bc = $self !== '' ? sso_http('POST', $self . '/sso/backchannel.php', [], 3) : ['code' => 0];
    $add('Приёмник событий отвечает', $bc['code'] === 400 ? 'ok' : ($bc['code'] === 0 ? 'warn' : 'fail'),
        $bc['code'] === 400 ? 'ждёт event_token — выход, блокировка и удаление дойдут'
            : ($bc['code'] === 0 ? 'проверить не вышло — curl -i -X POST ' . $self . '/sso/backchannel.php'
                : sso_code_str($bc['code']) . ' — путь режут правила сервера или CSRF-защита сайта: выход и удаление не дойдут'));

    /* События портал обязан обработать сам: удаление человека в {{APP_NAME}} мы
       доставим, а отключить его запись — работа портала. */
    $hooksBody = is_file(__DIR__ . '/hooks.php') ? (string)file_get_contents(__DIR__ . '/hooks.php') : '';
    $hooksDone = $hooksBody !== ''
        && preg_match('~function\s+sso_on_user_gone[^{]*\{\s*(//[^\n]*\n\s*)*return false;[ \t]*(//[^\n]*)?\s*\}~s', $hooksBody) !== 1;
    $add('Обработчик удаления написан', $hooksDone ? 'ok' : 'warn', $hooksDone
        ? 'sso_on_user_gone() что-то делает'
        : 'hooks.php — заглушка: удалённый в SSO человек останется здесь');

    $queueFile = __DIR__ . '/var/sso-events.ndjson';
    $queued = is_file($queueFile) ? count((array)file($queueFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    $add('Необработанных событий нет', $queued === 0 ? 'ok' : 'warn', $queued === 0
        ? 'очередь пуста' : "в var/sso-events.ndjson лежит $queued — их никто не разобрал");

    $var = __DIR__ . '/var';
    if (!is_dir($var)) @mkdir($var, 0750, true);
    $add('Каталог sso/var пишется', is_dir($var) && is_writable($var) ? 'ok' : 'fail',
        is_dir($var) ? (is_writable($var) ? $var : $var . ' — нет прав на запись') : 'не создан');

    /* OPcache с выключенной проверкой файлов — «поправил config.php, а ничего
       не изменилось». Диагноз редкий, а времени съедает много. */
    $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
    if (is_array($opcache) && !empty($opcache['opcache_enabled']) && !ini_get('opcache.validate_timestamps')) {
        $add('OPcache перечитывает файлы', 'warn', 'нет: правка config.php не применится до перезапуска PHP');
    } else {
        $add('OPcache перечитывает файлы', 'ok', is_array($opcache) && !empty($opcache['opcache_enabled'])
            ? 'да (revalidate_freq=' . (int)ini_get('opcache.revalidate_freq') . ' с)' : 'OPcache выключен');
    }

    $add('PHP, curl и openssl', version_compare(PHP_VERSION, '8.0', '>=') && function_exists('curl_init')
        && function_exists('openssl_verify') ? 'ok' : 'fail', 'PHP ' . PHP_VERSION);

    /* ---------- Перенос людей ---------- */
    $usersKey = (string)($cfg['users_secret'] ?? '');
    if (!$filled($usersKey)) {
        $add('Ключ переноса', 'warn', 'не выдан — перенос людей не настроен; ключ выдаётся в карточке портала, «Забрать людей»');
    } else {
        $add('Ключ переноса', 'ok', 'длина ' . strlen($usersKey) . ' · ' . sso_secret_source('users_secret'));

        $usersBody = is_file(__DIR__ . '/users.php') ? (string)file_get_contents(__DIR__ . '/users.php') : '';
        $stub = $usersBody !== ''
            && preg_match('~function\s+sso_export_users[^{]*\{\s*(//[^\n]*\n\s*)*return \[\];~s', $usersBody) === 1;
        /* Подписываем ровно так, как подписывает {{APP_NAME}}: если подпись здесь
           сошлась, «Забрать людей» тоже пройдёт. */
        $q = 'limit=1&offset=0';
        $ts = (string)time();
        $res = $self !== '' ? sso_http('GET', $self . '/sso/users.php?' . $q, [], 3, [
            'X-SSO-Client: ' . $clientId,
            'X-SSO-Timestamp: ' . $ts,
            'X-SSO-Signature: sha256=' . hash_hmac('sha256', $ts . '.' . $q, $usersKey),
        ]) : ['code' => 0, 'body' => ''];
        $json = json_decode((string)$res['body'], true);
        if ($res['code'] === 200 && is_array($json)) {
            $add('Выгрузка людей отвечает', $stub ? 'warn' : 'ok', 'HTTP 200 · подпись сошлась'
                . ($stub ? ' · sso_export_users() — заглушка, перенос отдаст пустой список' : ''));
        } elseif ($res['code'] === 0) {
            $add('Выгрузка людей отвечает', 'warn', 'проверить не вышло — проверьте из карточки портала: «Забрать людей»');
        } else {
            $add('Выгрузка людей отвечает', 'fail', sso_code_str($res['code']) . ' · '
                . (string)(is_array($json) ? ($json['error'] ?? '') : 'путь недоступен'));
        }
    }

    return $rows;
}
