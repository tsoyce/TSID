<?php
/**
 * Общая обвязка тестовых батарей.
 *
 * Заведён после того, как одна и та же ошибка поймала трижды: `http()` жил в
 * двух копиях — в e2e и в смоуке — и возвращал в них РАЗНЫЕ кортежи. Тело
 * ответа лежало то во второй ячейке, то в первой, редирект то в третьей, то во
 * второй. Проверка при этом не падала, а тихо сравнивала не то: пустая строка
 * не содержит искомой подстроки, и `ok()` честно писал «✗» про совсем другое.
 *
 * Теперь функция одна на обе батареи, и разойтись ей негде.
 * Порядок ячеек: **[статус, тело, редирект, тип, заголовки]**.
 */

declare(strict_types=1);

/** Счётчики принадлежат вызывающему файлу — каждая батарея считает свои. */
function ok(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ✓ $name\n";
    } else {
        $failed++;
        echo "  ✗ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/**
 * HTTP с банкой cookie, без автоматического перехода по редиректу: половина
 * проверок смотрит именно на 302 и на Location, а не на то, что за ними.
 *
 * @param  array|string $post   массив — form-urlencoded, строка — как есть (JSON)
 * @param  ?string      $jar    своя банка cookie — для запросов «от другого лица»
 * @return array{0:int,1:string,2:string,3:string,4:string}
 *         [статус, тело, редирект, тип содержимого, сырые заголовки]
 */
function http(string $method, string $url, array|string $post = [], array $headers = [], ?string $jar = null): array
{
    global $JAR;
    $jar = $jar ?? $JAR;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($post !== [] && $post !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post);
    }
    $resp   = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hSize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $ctype  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $rawHeaders = substr($resp, 0, $hSize);
    preg_match('~^Location:\s*(\S+)~mi', $rawHeaders, $m);
    return [$status, substr($resp, $hSize), $m[1] ?? '', $ctype, $rawHeaders];
}

/** multipart POST (загрузка файлов). Кортеж тот же. */
function upload(string $url, array $fields, array $files): array
{
    global $JAR;
    $post = $fields;
    foreach ($files as $name => [$path, $mime, $fname]) {
        $post[$name] = new CURLFile($path, $mime, $fname);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $JAR, CURLOPT_COOKIEFILE => $JAR,
        CURLOPT_TIMEOUT => 30, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
    ]);
    $resp   = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hSize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $ctype  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $rawHeaders = substr($resp, 0, $hSize);
    preg_match('~^Location:\s*(\S+)~mi', $rawHeaders, $m);
    return [$status, substr($resp, $hSize), $m[1] ?? '', $ctype, $rawHeaders];
}

function csrfOf(string $html): string
{
    return preg_match('~name="_csrf" value="([^"]+)"~', $html, $m) ? $m[1] : '';
}

/** Страница отдана целиком, а не с фаталом посередине. */
function pageClean(string $body): bool
{
    return !str_contains($body, 'Fatal error') && !str_contains($body, 'Warning:')
        && !str_contains($body, 'Uncaught') && !str_contains($body, 'Что-то сломалось');
}

/** Последнее письмо на адрес — темой и раскодированным телом (лог-драйвер почты). */
function lastMailTo(string $email): string
{
    /* По имени: в нём время с микросекундами (v4.96). filemtime — целые
       секунды, и два письма в одну секунду шли в случайном порядке. */
    $files = (array)glob(SSO_ROOT . '/storage/logs/mail/*.eml');
    rsort($files);
    foreach ($files as $f) {
        $m = (string)file_get_contents($f);
        if (stripos($m, 'To: ' . $email) !== false || stripos($m, '<' . $email . '>') !== false) {
            $subject = preg_match('~^Subject: ([^\r\n]+)~m', $m, $s) ? mb_decode_mimeheader($s[1]) : '';
            return $subject . "\n" . quoted_printable_decode($m);
        }
    }
    return '';
}

/**
 * Регистрация по шагам (v4.96): почта → код из письма, если его спросили →
 * имя и пароль. Один путь на все батареи: когда форма была одна, каждая
 * батарея слала её по-своему, и смена формы ломала их поодиночке.
 *
 * $fields — поля последнего шага поверх обычных; `invite` едет с первого шага.
 * Возвращает [статус последнего ответа, Location, id аккаунта или 0, тело].
 *
 * @return array{0:int,1:string,2:int,3:string}
 */
function signup(string $sso, string $email, array $fields = [], ?string $jar = null, array $headers = []): array
{
    $invite = (string)($fields['invite'] ?? '');
    $q = $invite !== '' ? '?invite=' . urlencode($invite) : '';
    [, $page] = http('GET', $sso . '/register' . $q, [], $headers, $jar);
    $r = http('POST', $sso . '/register', ['_csrf' => csrfOf($page), 'email' => $email]
        + ($invite !== '' ? ['invite' => $invite] : []), $headers, $jar);
    $id = static fn(): int => (int)\App\Core\DB::val('SELECT id FROM {users} WHERE email = ?', [$email]);
    if ($r[0] !== 302 || !str_contains($r[2], '/register')) return [$r[0], $r[2], $id(), $r[1]];
    [, $page] = http('GET', $sso . '/register' . $q, [], $headers, $jar);
    if (str_contains($page, 'action="/register/code"')) {
        $code = preg_match('~Код подтверждения: (\d{6})~u', lastMailTo($email), $m) ? $m[1] : '';
        http('POST', $sso . '/register/code', ['_csrf' => csrfOf($page), 'code' => $code], $headers, $jar);
        [, $page] = http('GET', $sso . '/register' . $q, [], $headers, $jar);
    }
    if (!str_contains($page, 'action="/register/finish"')) return [200, '', $id(), $page];
    preg_match_all('~name="accept\[\]" value="([^"]+)"~', $page, $am);
    unset($fields['invite']);
    $r = http('POST', $sso . '/register/finish', $fields + ['_csrf' => csrfOf($page), 'first_name' => 'Проба',
        'password' => 'very-long-password-1', 'accept' => $am[1] ?? []], $headers, $jar);
    return [$r[0], $r[2], $id(), $r[1]];
}
