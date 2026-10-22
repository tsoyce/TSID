<?php
/**
 * Заглушка в корне: объясняет, почему сайт не работает, — и чинит, если может.
 *
 * Этот файл выполняется ТОЛЬКО в одном случае — когда DocumentRoot смотрит в
 * корень проекта, а корневой .htaccess не действует. Работай он, запрос к «/»
 * был бы переписан в public/ и сюда бы не дошёл.
 *
 * Случай не выдуманный и не единичный: на боевой .htaccess не доехал при
 * заливке — дважды. Сайт отдавал голое «Not Found» от Apache, файлы при этом
 * лежали на месте: /public/assets/... открывался, а /assets/... нет.
 *
 * Первая версия только называла причину и честно писала о себе «ничего не чинит
 * и чинить не может». Это оказалось неправдой: заглушка выполняется PHP в том
 * самом каталоге, где не хватает файла, и на обычном хостинге может его создать.
 * Урок про FTP-клиент вместо кнопки — это тридцать секунд, растянутые на полчаса.
 *
 * Поэтому правила лежат тут же, второй копией (батарея сверяет их с боевыми
 * файлами побайтно), и при нехватке предлагается кнопка. Пишем только то, чего
 * нет: файл, поправленный руками под свой хостинг, заглушка трогать не вправе.
 *
 * Зависимостей нет намеренно — она обязана отработать и тогда, когда не доехало
 * вообще ничего, кроме неё самой: ничего не подключает, не читает конфиг, не
 * знает про базу.
 */

declare(strict_types=1);

/**
 * Правила корневого .htaccess — второй копией.
 *
 * Копия существует ровно потому, что оригинала может не быть: читать его, чтобы
 * восстановить, значит не восстановить ничего. Расхождение ловит батарея
 * (tests/stub_test.php), поэтому копия не может тихо устареть.
 */
const HTACCESS_ROOT = <<<'RULES'
# SSO — прокси-вариант из Приложения D: если DocumentRoot нельзя навести
# на public/, этот файл закрывает служебные каталоги и переписывает всё в public/.
# Если DocumentRoot = public/ — файл просто не читается сервером.
#
# ВАЖНО: /install здесь НЕ блокируется — это маршрут инсталлятора; запросы к нему
# уходят через воронку в public/index.php, а прямой запуск исходников install/*
# закрыт guard'ом в самих файлах.
RewriteEngine On

# Служебные каталоги — наглухо: ключи, конфиг, исходники
RewriteRule ^(storage|config|app|migrations|lib|templates|tests)/ - [F]
RewriteRule ^\.(git|claude) - [F]

# Discovery — явно и первым: путь начинается с точки и на панельном хостинге
# попадает под правило «резать всё скрытое». См. пояснение в public/.htaccess.
RewriteRule ^\.well-known/ public/index.php [QSA,L]

# Всё остальное — в public/. Первое правило гасит повторные круги переписывания.
RewriteRule ^public/ - [L]
RewriteRule ^(.*)$ public/$1 [L]
RULES;

/** Правила public/.htaccess — тем же порядком и по той же причине. */
const HTACCESS_PUBLIC = <<<'RULES'
Options -Indexes
RewriteEngine On
# Basic-auth для /token и /introspect: FPM/CGI-режимы съедают заголовок без этого
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

# Discovery живёт по пути, начинающемуся с точки, и на панельном хостинге
# регулярно попадает под правило «резать всё скрытое». Пускаем его в приложение
# первым и явно. Помогает не всегда: если запрет стоит в конфиге виртуального
# хоста (RedirectMatch 404 /\..*$), он срабатывает раньше любого .htaccess — там
# снимает только поддержка. Проверить, кто ответил, можно в разделе «Прозвон»:
# ответ приложения помечен заголовком X-SSO-App (до 4.89 — X-TSOY-ID).
RewriteRule ^\.well-known/ index.php [QSA,L]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]

<FilesMatch "\.(woff2|css|js|svg|png|webp)$">
  Header set Cache-Control "public, max-age=604800"
</FilesMatch>

# Сжатие ответов (v4.67). Замер 12.09 на боевой: HTML, app.css (137 КБ) и app.js
# (59 КБ) уходили несжатыми при загруженных brotli и deflate — просто никто не
# попросил. Каждая строка — под проверкой своего модуля: неизвестная директива
# в .htaccess роняет сайт в 500, а отсутствующий модуль должен значить «без
# сжатия», а не «без сайта». Шрифты woff2 уже сжаты — их не трогаем.
<IfModule mod_filter.c>
  <IfModule mod_brotli.c>
    <IfModule mod_deflate.c>
      AddOutputFilterByType BROTLI_COMPRESS;DEFLATE text/html text/plain text/css application/javascript text/javascript application/json application/manifest+json image/svg+xml
    </IfModule>
    <IfModule !mod_deflate.c>
      AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/css application/javascript text/javascript application/json application/manifest+json image/svg+xml
    </IfModule>
  </IfModule>
  <IfModule !mod_brotli.c>
    <IfModule mod_deflate.c>
      AddOutputFilterByType DEFLATE text/html text/plain text/css application/javascript text/javascript application/json application/manifest+json image/svg+xml
    </IfModule>
  </IfModule>
</IfModule>
RULES;

$dir = __DIR__;
$hasPublic  = is_file($dir . '/public/index.php');
$hasRootHt  = is_file($dir . '/.htaccess');
$hasPubHt   = is_file($dir . '/public/.htaccess');
$software   = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
$isNginx    = stripos($software, 'nginx') !== false;
$modRewrite = function_exists('apache_get_modules')
    ? in_array('mod_rewrite', (array)apache_get_modules(), true)
    : null;

/* Что мы вправе создать. Условие «файла нет» — часть права, а не забота об
   удобстве: перезаписать чужую правку хуже, чем не починить вовсе. */
$mayWriteRoot = $hasPublic && !$hasRootHt && is_writable($dir);
$mayWritePub  = $hasPublic && !$hasPubHt && is_dir($dir . '/public') && is_writable($dir . '/public');
/* На nginx .htaccess не читается никогда — создать его значит соврать. */
$mayFix = !$isNginx && ($mayWriteRoot || $mayWritePub);

$self = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
if ($self === false || $self === '') $self = '/';

/* Починка. Отдельного маршрута нет: заглушка обязана работать в одиночку. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['fix'] ?? '') === '1' && $mayFix) {
    if ($mayWriteRoot) @file_put_contents($dir . '/.htaccess', HTACCESS_ROOT . "\n");
    if ($mayWritePub)  @file_put_contents($dir . '/public/.htaccess', HTACCESS_PUBLIC . "\n");
    /* Обратно на тот же адрес. Заработало переписывание — приедем в приложение и
       этой страницы больше не увидим; не заработало — вернёмся сюда, но уже с
       файлом на месте, и разбор пойдёт по второй ветке: AllowOverride. */
    header('Location: ' . $self . '?fixed=1', true, 303);
    exit;
}

$justFixed = isset($_GET['fixed']) && $hasRootHt;

http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
header('Retry-After: 300');
header('X-SSO-App: 1');   // подпись «ответило приложение» — как у основного входа
header('X-TSOY-ID: 1');   // прежнее имя метки, до 5.0

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);

/** Строка проверки: да/нет и что это значит. */
$row = static function (string $what, ?bool $ok, string $note) use ($e): string {
    $mark = $ok === null ? '·' : ($ok ? '✓' : '✗');
    $color = $ok === null ? '#8a8a8a' : ($ok ? '#177245' : '#b3261e');
    return '<li><b style="color:' . $color . '">' . $mark . '</b> ' . $e($what)
        . ' <span style="color:#6b6b6b">— ' . $e($note) . '</span></li>';
};

/* Пояснение к потерянному файлу выводится из соседней строки, а не заучено.
   Заученное «FTP-клиенты прячут точечные файлы» рядом с зелёным public/.htaccess
   — подсказка, которая уводит: раз соседний точечный доехал, дело не в них. */
$rootNote = $hasRootHt
    ? 'файл есть, но не действует: сервер его не читает — обычно это AllowOverride'
    : ($hasPubHt
        ? 'файла нет, хотя соседний точечный доехал — значит клиент их не прячет, корневой потерялся отдельно'
        : 'файла нет — он начинается с точки, и FTP-клиенты его часто пропускают');
$pubNote = $hasPubHt
    ? 'на месте'
    : ($hasRootHt ? 'файла нет, хотя корневой доехал — потерялся отдельно' : 'нет — залейте вместе с корневым');
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Сайт не настроен</title>
<style>
  body { margin: 0; padding: 40px 20px; background: #f5f5f4; color: #1c1917;
         font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
  main { max-width: 660px; margin: 0 auto; background: #fff; border: 1px solid #e7e5e4;
         border-radius: 16px; padding: 28px 32px; }
  h1 { font-size: 22px; margin: 0 0 8px; }
  h2 { font-size: 16px; margin: 24px 0 8px; }
  code { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 13px;
         background: #f5f5f4; padding: 2px 6px; border-radius: 4px; }
  ul { list-style: none; padding: 0; margin: 16px 0; }
  li { padding: 4px 0; }
  .muted { color: #6b6b6b; }
  ol { padding-left: 20px; }
  .plate { margin: 20px 0; padding: 16px 18px; background: #f4f8f5;
           border: 1px solid #cfe0d3; border-radius: 12px; }
  .plate p { margin: 0 0 12px; }
  .plate p:last-child { margin-bottom: 0; }
  button { font: inherit; font-weight: 600; color: #fff; background: #177245; border: 0;
           border-radius: 10px; padding: 10px 18px; cursor: pointer; }
  button:hover { background: #135c38; }
  button:focus-visible { outline: 3px solid #177245; outline-offset: 2px; }
  pre { overflow-x: auto; background: #f5f5f4; border: 1px solid #e7e5e4; border-radius: 10px;
        padding: 12px 14px; font-size: 12.5px; line-height: 1.5; }
  summary { cursor: pointer; padding: 6px 0; }
  dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px;
       margin: 20px 0 0; font-size: 13px; color: #6b6b6b; }
  dt { font-weight: 600; }
  dd { margin: 0; word-break: break-all; }
</style>
</head><body><main>
<h1>Сайт не настроен: не работает переписывание адресов</h1>
<p>Файлы на месте, но веб-сервер не направляет запросы в приложение. Пока это так,
   любой адрес будет отвечать «не найдено».</p>

<ul>
  <?= $row('Приложение распаковано', $hasPublic, $hasPublic
        ? 'public/index.php на месте'
        : 'public/index.php нет — доехала не вся поставка') ?>
  <?= $row('Корневой .htaccess', $hasRootHt, $rootNote) ?>
  <?= $row('public/.htaccess', $hasPubHt, $pubNote) ?>
  <?php if ($isNginx): ?>
  <?= $row('Веб-сервер', null, 'nginx — файлы .htaccess он не читает, сколько их ни клади') ?>
  <?php else: ?>
  <?= $row('mod_rewrite', $modRewrite, $modRewrite === null
        ? 'проверить нельзя: PHP работает не модулем Apache'
        : ($modRewrite ? 'включён' : 'выключен — без него переписывание невозможно')) ?>
  <?php endif ?>
</ul>

<?php if ($justFixed): ?>
<div class="plate">
  <p><b>Файл создан.</b> Вы снова видите эту страницу — значит, сервер его не читает.
     Осталось попросить хостинг разрешить <code>AllowOverride All</code> для сайта и
     включить <code>mod_rewrite</code>. Или навести <code>DocumentRoot</code> на
     <code>public/</code> — тогда корневой файл не нужен вовсе.</p>
</div>
<?php elseif ($mayFix): ?>
<div class="plate">
  <p><b>Это чинится отсюда.</b> Каталог доступен на запись — <?= $mayWriteRoot && $mayWritePub
      ? 'создадим оба файла' : 'создадим недостающий файл' ?> с правилами из поставки
     и вернёмся на этот же адрес. Заработает — попадёте в приложение.</p>
  <form method="post" action="<?= $e($self) ?>">
    <input type="hidden" name="fix" value="1">
    <button type="submit">Создать <?= $mayWriteRoot && $mayWritePub ? 'файлы' : 'файл' ?> и проверить</button>
  </form>
</div>
<?php endif ?>

<h2>Что сделать</h2>
<?php if ($isNginx): ?>
<p>nginx не читает <code>.htaccess</code> — правила задаются в конфиге сайта:</p>
<pre>root <?= $e($dir) ?>/public;
location / { try_files $uri /index.php$is_args$args; }
location ~ ^/(storage|config)/ { deny all; }</pre>
<p>После правки — <code>nginx -t &amp;&amp; systemctl reload nginx</code>.</p>
<?php else: ?>
<ol>
  <li>Залейте <code>.htaccess</code> из архива в корень и в <code>public/</code>.
      В FTP-клиенте включите показ скрытых файлов — иначе он их не покажет и не отправит.</li>
  <li>Если файлы на месте, а не работает — попросите хостинг разрешить
      <code>AllowOverride All</code> для сайта и включить <code>mod_rewrite</code>.</li>
  <li>Надёжнее всего — навести <code>DocumentRoot</code> сайта прямо на
      <code>public/</code>. Тогда корневой <code>.htaccess</code> не нужен вовсе.</li>
</ol>
<?php if (!$hasRootHt && !$mayFix): ?>
<details>
  <summary>Содержимое корневого <code>.htaccess</code> — если проще создать файл в панели хостинга</summary>
  <pre><?= $e(HTACCESS_ROOT) ?></pre>
</details>
<?php endif ?>
<?php if (!$hasPubHt && !$mayFix): ?>
<details>
  <summary>Содержимое <code>public/.htaccess</code></summary>
  <pre><?= $e(HTACCESS_PUBLIC) ?></pre>
</details>
<?php endif ?>
<?php endif ?>

<p class="muted">Проверить в один запрос: если <code>/public/assets/css/app.css</code>
   открывается, а <code>/assets/css/app.css</code> — нет, дело именно в переписывании,
   а не в потерянных файлах.</p>

<dl>
  <dt>Веб-сервер</dt><dd><?= $e($software !== '' ? $software : 'не представился') ?></dd>
  <dt>Корень сайта</dt><dd><?= $e((string)($_SERVER['DOCUMENT_ROOT'] ?? '—')) ?></dd>
  <dt>Каталог проекта</dt><dd><?= $e($dir) ?></dd>
</dl>
</main></body></html>
