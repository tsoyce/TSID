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
