<?php
/**
 * Ревизия проводки: что объявлено и не подключено, что подключено и не объявлено.
 *
 * Отдельный жанр дефекта, который не ловит ни одна из прежних проверок.
 * Каталог (событий, прав, уведомлений) — это обещание: он рисует переключатель
 * в интерфейсе. Если событие в каталоге есть, а отправлять его забыли,
 * переключатель работает: он включается, сохраняется, показывается включённым —
 * и не делает ничего. Хуже отсутствия: человек считает себя предупреждённым.
 *
 * Так `login.failed_burst` прожил в настройках с v2.2 по v4.17, а три события
 * вебхуков — с v2.3. Ни одна проверка их не видела, потому что по отдельности
 * и каталог, и код были исправны.
 *
 * Обратный случай тише, но так же вреден: событие отправляется, а в каталоге
 * его нет. Подписаться на него нельзя — значит, отправка уходит в пустоту.
 *
 * Запуск: php tools/wiring_audit.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$fails = 0;

/** Одна сверка: объявленное против встреченного в коде. */
function pair(string $title, array $declared, array $used, string $whyDeclared, string $whyUsed): void
{
    global $fails;
    $orphanDecl = array_values(array_diff($declared, $used));
    $orphanUse = array_values(array_diff($used, $declared));
    printf("\n── %s ──\n  объявлено %d, встречается в коде %d\n",
        $title, count($declared), count($used));
    foreach ($orphanDecl as $x) { echo "  ✗ $x — $whyDeclared\n"; $fails++; }
    foreach ($orphanUse as $x) { echo "  ✗ $x — $whyUsed\n"; $fails++; }
    if ($orphanDecl === [] && $orphanUse === []) echo "  ✓ сходится\n";
}

/**
 * Всё содержимое проекта одной строкой, кроме файла с самим каталогом.
 *
 * Исключение обязательно: ключи каталога лежат в нём же, и «встречается в
 * коде» без этого было бы истинно всегда.
 *
 * Комментарии выброшены: упоминание события в пояснении — не проводка.
 */
function projectSource(string $exceptFile = ''): string
{
    $src = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SSO_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', (string)$f);
        foreach (['/dist/', '/vendor/', '/storage/', '/node_modules/', '/tests/', '/tools/', '/.git/'] as $skip) {
            if (str_contains($p, $skip)) continue 2;
        }
        if (!str_ends_with($p, '.php')) continue;
        if ($exceptFile !== '' && str_ends_with($p, $exceptFile)) continue;
        $src .= file_get_contents($p) . "\n";
    }
    return stripComments($src);
}

/** Код без комментариев: токенайзером, а не регуляркой. */
function stripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

/**
 * Названия из каталога, встреченные в коде.
 *
 * Ищем строковый литерал, а не конкретный вызов. Первая версия смотрела только
 * внутрь `emit('…')` и объявила `access.granted` неподключённым — при том, что
 * событие отправляется через общий метод, которому имя приходит переменной.
 * Проверка, требующая писать код определённым образом, не находит дефекты, а
 * навязывает стиль.
 */
function mentioned(array $names, string $src): array
{
    $out = [];
    foreach ($names as $n) {
        if (str_contains($src, "'" . $n . "'")) $out[] = $n;
    }
    return $out;
}

/**
 * Файл каталога без самого каталога.
 *
 * Выбросить файл целиком нельзя: класс может и объявлять событие, и сам его
 * отправлять — так устроен `login.new_device`, его шлёт `Notify::afterLogin()`
 * двумя методами ниже объявления. Выбросив файл, проверка объявила это событие
 * неподключённым, хотя оно работает с v2.2. Поэтому режем ровно объявление, а
 * остальное оставляем.
 */
function withoutCatalog(string $file, string $const): string
{
    $src = (string)file_get_contents(SSO_ROOT . '/' . $file);
    $at = strpos($src, 'const ' . $const);
    if ($at === false) return stripComments($src);
    $end = strpos($src, '];', $at);
    if ($end === false) return stripComments($src);
    return stripComments(substr($src, 0, $at) . substr($src, $end + 2));
}

echo "══ Ревизия проводки ══\n";

/* 1. События вебхуков: каталог рисует галочки в разделе Webhooks.
      Ищем и прямой `emit('…')` — он ловит событие, которого в каталоге нет, —
      и просто упоминание: отправлять могут через общий метод. */
$declared = array_keys(\App\Models\Webhook::events());
$src = projectSource('app/Models/Webhook.php')
    . withoutCatalog('app/Models/Webhook.php', 'EVENTS');
preg_match_all("~emit\(\s*'([a-z0-9_.]+)'~", $src, $m);
$used = array_values(array_unique(array_merge($m[1], mentioned($declared, $src))));
pair('События вебхуков', $declared, $used,
    'есть галочка, но событие никогда не отправляется',
    'отправляется, но подписаться нельзя: нет в каталоге');

/* 2. Уведомления человеку: каталог рисует тумблеры в профиле. */
$declared = array_keys(\App\Core\Notify::EVENTS);
$src = projectSource('app/Core/Notify.php')
    . withoutCatalog('app/Core/Notify.php', 'EVENTS');
pair('Уведомления человеку', $declared, mentioned($declared, $src),
    'тумблер есть, письмо не уходит',
    'письмо уходит, тумблера нет — выключить нельзя');

/* 3. Уведомления владельцу: то же самое, но каталог другой. */
$declared = array_keys(\App\Core\AdminNotify::EVENTS);
$src = projectSource('app/Core/AdminNotify.php')
    . withoutCatalog('app/Core/AdminNotify.php', 'EVENTS');
pair('Уведомления владельцу', $declared, mentioned($declared, $src),
    'тумблер есть, письмо не уходит',
    'письмо уходит, тумблера нет');

/* 4. Права: каталог рисует таблицу прав у роли. */
$declared = \App\Core\Permission::all();
$src = projectSource('app/Core/Permission.php');
preg_match_all("~(?:requirePerm|Auth::can|can)\(\s*'([a-z0-9_.]+)'~", $src, $m);
$used = array_values(array_unique($m[1]));
/* Право может проверяться только через набор по умолчанию — это тоже
   употребление: роль его выдаёт, значит оно живое. */
foreach (\App\Core\Permission::DEFAULTS as $perms) {
    foreach ($perms as $p) $used[] = $p;
}
pair('Права ролей', $declared, array_values(array_unique($used)),
    'право можно выдать, но оно нигде не спрашивается',
    'право спрашивается, но выдать его негде');

/* 5. Разделы настроек: у каждого обязан быть шаблон, иначе экран падает. */
$declared = array_column(\App\Core\SettingsMap::sections(), 'key');
$missingTpl = [];
foreach (\App\Core\SettingsMap::sections() as $s) {
    /* Раздел может жить своим контроллером — тогда у него свой маршрут вне
       /admin/settings/section/. Проверяем только «наши». */
    if (!str_contains($s['route'], '/settings/section/')) continue;
    if (!is_file(SSO_ROOT . '/templates/admin/settings/' . $s['key'] . '.php')) {
        $missingTpl[] = $s['key'];
    }
}
printf("\n── Разделы настроек ──\n  разделов %d\n", count($declared));
foreach ($missingTpl as $k) { echo "  ✗ $k — в карте есть, шаблона нет\n"; $fails++; }
if ($missingTpl === []) echo "  ✓ у каждого раздела свой шаблон\n";

/* 6. Классы CSS: шаблон просит класс, которого в таблице стилей нет.
      Так `.text-warning` жил с v4.6: разметка на него ссылалась, правила не
      существовало, и предупреждение красилось как обычный текст. */
$css = '';
foreach ((array)glob(SSO_ROOT . '/public/assets/css/*.css') as $f) $css .= file_get_contents($f) . "\n";
preg_match_all('~\.([a-z][a-z0-9_-]+)~', $css, $m);
$haveClass = array_flip($m[1]);

$usedClass = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SSO_ROOT . '/templates', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', (string)$f);
    if (!str_ends_with($p, '.php') || str_contains($p, '/emails/')) continue;
    $s = (string)file_get_contents($p);
    /* Сначала вырезаем PHP целиком. Иначе в выдачу попадает разметка, которую
       PHP собирает строкой: `'class="badge ' . $cls . '"'` даёт «классы»
       `$cls`, `?`, `:` и кавычку. Первый заход этой проверки именно так и
       сработал — и показал восемь «ненайденных классов», ни одного настоящего.
       Проверка, которая шумит, перестаёт читаться на второй раз. */
    /* Заменяем на метку, а не на пустоту: `class="toast-<?= $type ?>"`
       без метки превращается в класс `toast-`, которого, разумеется, в CSS
       нет. Вторая попытка проверки споткнулась ровно об это. */
    $s = (string)preg_replace('~<\?(?:php|=).*?\?>~su', '@@', $s);
    if (preg_match_all('~class="([^"<]*)"~', $s, $mm)) {
        foreach ($mm[1] as $chunk) {
            foreach (preg_split('~\s+~', trim($chunk)) ?: [] as $c) {
                /* Осталась подстановка — значение класса известно только в
                   рантайме, сверять нечего. */
                if ($c === '' || str_contains($c, '@@')
                    || !preg_match('~^[a-z][a-z0-9_-]*$~', $c)) continue;
                $usedClass[$c] = basename($p);
            }
        }
    }
}
/* Классы, которые ставит только JS, живут в app.js — их таблица стилей знает,
   а шаблоны нет. Здесь сверяем ровно то, что написано в разметке. */
$unknown = [];
foreach ($usedClass as $c => $where) {
    if (!isset($haveClass[$c])) $unknown[] = "$c ($where)";
}
printf("\n── Классы в разметке ──\n  разных классов %d\n", count($usedClass));
foreach ($unknown as $u) { echo "  ✗ $u — класс есть в разметке, правила в CSS нет\n"; $fails++; }
if ($unknown === []) echo "  ✓ каждому классу есть правило\n";

echo "\n══ Итог ══\n";
echo $fails === 0 ? "ПРОВОДКА СХОДИТСЯ\n" : "РАСХОЖДЕНИЙ: $fails\n";
exit($fails === 0 ? 0 : 1);
