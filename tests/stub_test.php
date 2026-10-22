<?php
/**
 * Заглушка в корне: та, что отвечает, когда не работает переписывание адресов.
 *
 * Она выполняется ровно в момент, когда сайт мёртв и объяснить это некому: не
 * работают маршруты, значит не работает ничего. Поэтому проверять её приходится
 * не через HTTP, а запуском в отдельном каталоге — с подставленными $_SERVER и
 * $_POST, как если бы пришёл настоящий запрос.
 *
 * Проверяем три вещи:
 *
 * 1. Правила .htaccess лежат в заглушке второй копией — иначе восстанавливать
 *    нечем, оригинала-то и нет. Копия обязана совпадать с боевым файлом
 *    побайтно: разошлись — и кнопка «починить» чинит вчерашним.
 * 2. Починка создаёт то, чего нет, и НЕ трогает то, что есть. Файл, поправленный
 *    руками под свой хостинг, дороже нашего эталона.
 * 3. Разбор причины считается, а не заучен. «FTP-клиенты прячут точечные файлы»
 *    рядом с зелёным public/.htaccess — подсказка, которая уводит в сторону.
 *
 * Повторяемая: каталог сносится и на входе, и на выходе — харнесс мутаций
 * гоняет проверку дважды.
 *
 * Запуск: php tests/stub_test.php
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$passed = 0;
$failed = 0;

$root = dirname(__DIR__);
$sandbox = $root . '/tests/tmp/stub';

$htRoot = (string)file_get_contents($root . '/.htaccess');
$htPub  = (string)file_get_contents($root . '/public/.htaccess');

/** Снести каталог целиком — рекурсивно и молча. */
function wipe(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach ((array)scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? wipe($p) : @unlink($p);
    }
    @rmdir($dir);
}

/**
 * Разложить песочницу: копия заглушки плюс то, что просили положить рядом.
 *
 * @param array<string,string> $files путь относительно каталога => содержимое
 */
function sandbox(string $sandbox, string $root, array $files): void
{
    wipe($sandbox);
    mkdir($sandbox . '/public', 0777, true);
    copy($root . '/index.php', $sandbox . '/index.php');
    file_put_contents($sandbox . '/_run.php', <<<'RUNNER'
<?php
/* Запускалка: подсовывает заглушке запрос и отдаёт её вывод. */
$cfg = json_decode((string)file_get_contents($argv[1]), true);
$_SERVER = array_merge($_SERVER, $cfg['server']);
$_GET = $cfg['get'];
$_POST = $cfg['post'];
require __DIR__ . '/index.php';
RUNNER);
    /* Отдельным файлом, а не через `php -r`: на Windows escapeshellarg заменяет
       двойные кавычки пробелами, и код в командной строке тихо перестаёт быть
       тем кодом, который писали. Один раз уже поймало. */
    file_put_contents($sandbox . '/_dump.php', <<<'DUMPER'
<?php
/* Значения правил — исполнением, а не разбором исходника: сверять надо то, чем
   заглушка действительно чинит, а не то, что мы про неё прочитали. */
ob_start();
require __DIR__ . '/index.php';
ob_end_clean();
echo HTACCESS_ROOT, chr(0), HTACCESS_PUBLIC;
DUMPER);
    foreach ($files as $rel => $body) {
        $full = $sandbox . '/' . $rel;
        if (!is_dir(dirname($full))) mkdir(dirname($full), 0777, true);
        file_put_contents($full, $body);
    }
}

/** Выполнить заглушку в песочнице и вернуть её вывод. */
function render(string $sandbox, array $server = [], array $post = [], array $get = []): string
{
    $cfg = $sandbox . '/_req.json';
    file_put_contents($cfg, json_encode([
        'server' => ['REQUEST_METHOD' => $post === [] ? 'GET' : 'POST', 'REQUEST_URI' => '/'] + $server,
        'get' => $get,
        'post' => $post,
    ], JSON_UNESCAPED_UNICODE));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sandbox . '/_run.php')
        . ' ' . escapeshellarg($cfg) . ' 2>&1';
    return (string)shell_exec($cmd);
}

echo "═══ Заглушка в корне ═══\n";

try {
    echo "\nПравила лежат в ней самой и совпадают с боевыми:\n";
    sandbox($sandbox, $root, ['public/index.php' => '<?php']);
    $dump = render($sandbox, [], [], []);   // прогрев: убедимся, что вообще запускается
    ok(str_contains($dump, 'Сайт не настроен'), 'заглушка отвечает страницей разбора');

    $consts = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg($sandbox . '/_dump.php'));
    [$copyRoot, $copyPub] = array_pad(explode(chr(0), $consts, 2), 2, '');

    ok($copyRoot . "\n" === $htRoot, 'копия корневых правил совпадает с .htaccess побайтно',
       'разница в ' . abs(strlen($copyRoot) + 1 - strlen($htRoot)) . ' б');
    ok($copyPub . "\n" === $htPub, 'копия правил public/ совпадает с public/.htaccess побайтно',
       'разница в ' . abs(strlen($copyPub) + 1 - strlen($htPub)) . ' б');

    echo "\nПочинка создаёт недостающее:\n";
    sandbox($sandbox, $root, ['public/index.php' => '<?php']);
    $page = render($sandbox, [], [], []);
    ok(str_contains($page, 'Это чинится отсюда'), 'при нехватке файлов предлагается кнопка');
    ok(str_contains($page, 'name="fix"'), 'кнопка — форма POST, а не совет сходить в FTP');

    render($sandbox, [], ['fix' => '1']);
    ok(file_get_contents($sandbox . '/.htaccess') === $htRoot,
       'корневой .htaccess создан и совпадает с поставкой');
    ok(file_get_contents($sandbox . '/public/.htaccess') === $htPub,
       'public/.htaccess создан и совпадает с поставкой');

    echo "\nИ не трогает то, что есть:\n";
    $mine = "# правил руками под свой хостинг\nRewriteEngine On\n";
    sandbox($sandbox, $root, ['public/index.php' => '<?php', '.htaccess' => $mine]);
    render($sandbox, [], ['fix' => '1']);
    ok(file_get_contents($sandbox . '/.htaccess') === $mine,
       'существующий файл не перезаписан — чужая правка дороже эталона');
    ok(file_get_contents($sandbox . '/public/.htaccess') === $htPub,
       'а недостающий рядом при этом всё равно создан');

    echo "\nИ не чинит там, где чинить нечего:\n";
    sandbox($sandbox, $root, []);   // поставка не доехала: public/index.php нет
    render($sandbox, [], ['fix' => '1']);
    ok(!is_file($sandbox . '/.htaccess'),
       'без public/index.php ничего не пишется — это не установка, а пустой каталог');
    $page = render($sandbox, [], [], []);
    ok(str_contains($page, 'доехала не вся поставка'), 'и об этом сказано прямо');
    ok(str_contains($page, 'RewriteEngine On'),
       'а правила показаны текстом — создать файл в панели хостинга можно и руками');

    echo "\nnginx — отдельный разговор:\n";
    sandbox($sandbox, $root, ['public/index.php' => '<?php']);
    $page = render($sandbox, ['SERVER_SOFTWARE' => 'nginx/1.24.0'], [], []);
    ok(!str_contains($page, 'name="fix"'),
       'кнопки нет: класть .htaccess под nginx — врать человеку');
    ok(str_contains($page, 'try_files'), 'вместо неё — кусок конфига nginx');
    render($sandbox, ['SERVER_SOFTWARE' => 'nginx/1.24.0'], ['fix' => '1']);
    ok(!is_file($sandbox . '/.htaccess'), 'и починка под nginx ничего не создаёт');

    echo "\nПричина выводится, а не заучена:\n";
    sandbox($sandbox, $root, ['public/index.php' => '<?php', 'public/.htaccess' => $htPub]);
    $page = render($sandbox, [], [], []);
    ok(!str_contains($page, 'FTP-клиенты его часто пропускают'),
       'соседний точечный файл доехал — про «клиент их прячет» молчим');
    ok(str_contains($page, 'потерялся отдельно'), 'и говорим то, что следует из соседней строки');

    sandbox($sandbox, $root, ['public/index.php' => '<?php']);
    $page = render($sandbox, [], [], []);
    ok(str_contains($page, 'FTP-клиенты его часто пропускают'),
       'нет обоих — вот тогда версия про скрытые файлы и звучит');

    echo "\nВторой заход после починки:\n";
    sandbox($sandbox, $root, ['public/index.php' => '<?php', '.htaccess' => $htRoot,
                              'public/.htaccess' => $htPub]);
    $page = render($sandbox, [], [], ['fixed' => '1']);
    ok(str_contains($page, 'Файл создан'), 'вернулись сюда же — значит сервер файл не читает');
    ok(str_contains($page, 'AllowOverride'), 'и разбор идёт дальше, к правам на .htaccess');

    echo "\nОна обязана работать в одиночку:\n";
    $src = (string)file_get_contents($root . '/index.php');
    ok(!preg_match('~\b(require|include)(_once)?\s*[\(\'"]~', $src),
       'ничего не подключает — работает, даже если не доехало ничего');
    ok(str_contains($src, 'http_response_code(503)'),
       'отвечает 503, а не 200: сайт не работает, и мониторинг обязан это видеть');
    ok(str_contains((string)file_get_contents($root . '/build_dist.php'), "'index.php',"),
       'и кладётся в архив — без неё владелец снова увидит ошибку Apache');
} finally {
    wipe($sandbox);
}

echo "\n══ Итог ══\n";
printf("Пройдено: %d, провалено: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
