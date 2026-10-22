<?php
/**
 * Ревизия проекта: маршруты, шаблоны, права, SQL.
 * Ищет то, что не видно глазами и не ловится линтером.
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$root = SSO_ROOT;
$found = [];
$note = static function (string $group, string $msg) use (&$found): void { $found[$group][] = $msg; };

/* ---- A. Маршруты ссылаются на существующие методы ---- */
$routes = (string)file_get_contents("$root/app/routes.php");
preg_match_all('~\$router->(get|post)\(\s*\'([^\']+)\'\s*,\s*\[([^\]]+)\]~', $routes, $m, PREG_SET_ORDER);
$routeCount = 0;
foreach ($m as $r) {
    $routeCount++;
    if (!preg_match('~([\w\\\\]+)::class\s*,\s*\'(\w+)\'~', $r[3], $c)) continue;
    $class = str_starts_with($c[1], 'App\\') ? $c[1] : 'App\\Controllers\\' . $c[1];
    foreach (['', 'App\\Controllers\\', 'App\\Controllers\\Admin\\'] as $p) {
        if (class_exists($p . $c[1])) { $class = $p . $c[1]; break; }
    }
    if (!class_exists($class)) { $note('маршруты', "класса нет: {$c[1]} ({$r[2]})"); continue; }
    if (!method_exists($class, $c[2])) $note('маршруты', "метода нет: {$c[1]}::{$c[2]} ({$r[2]})");
}

/* ---- B. Шаблоны: переменные, которых контроллер не передаёт ---- */
$views = [];
foreach (array_merge((array)glob("$root/app/Controllers/*.php"), (array)glob("$root/app/Controllers/Admin/*.php")) as $f) {
    $src = (string)file_get_contents((string)$f);
    preg_match_all("~View::render\(\s*'([^']+)'\s*,\s*\[(.*?)\n\s*\]\)~s", $src, $vm, PREG_SET_ORDER);
    foreach ($vm as $v) {
        preg_match_all("~'(\w+)'\s*=>~", $v[2], $keys);
        $views[$v[1]] = array_unique(array_merge($views[$v[1]] ?? [], $keys[1]));
    }
}
$always = ['user', 'pageTitle', 'flash', 'csrf'];
foreach ($views as $tpl => $keys) {
    $file = "$root/templates/$tpl.php";
    if (!is_file($file)) { $note('шаблоны', "нет файла: $tpl"); continue; }
    $src = (string)file_get_contents($file);
    /* Внутри <code> и <pre> живут примеры для чужих разработчиков: в
       webhooks это `hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret)`.
       Это текст, а не код шаблона, и искать там переменные бессмысленно. */
    $scan = (string)preg_replace('~<(code|pre)\b[^>]*>.*?</\1>~s', '', $src);
    preg_match_all('~\$([a-zA-Z_]\w*)~', $scan, $used);
    $localAssign = [];
    preg_match_all('~\$([a-zA-Z_]\w*)\s*=~', $src, $la);
    $localAssign = $la[1];
    /* Ищем `as $x` где угодно, а не только сразу после `foreach (`: раньше
       шаблон требовал `[^)]*` между ними и спотыкался о любую скобку в
       выражении — `foreach (Legal::requiringConsent() as $k => $doc)` считался
       непонятым, и $doc уходил в отчёт как чужой. */
    preg_match_all('~\bas\s*(?:\$\w+\s*=>\s*)?\$(\w+)~', $src, $fe);
    $localAssign = array_merge($localAssign, $fe[1]);

    /* Разбор списком: `foreach ($x as $k => [$a, $b, $c])` и `[$a, $b] = …`.
       Без этого инструмент считал чужими все переменные из деструктуризации —
       тридцать находок, все ложные. Отчёт, состоящий из шума, учит в себя не
       смотреть, и это хуже отсутствующей проверки. */
    preg_match_all('~as\s*(?:\$\w+\s*=>\s*)?\[([^\]]*)\]~', $src, $destr);
    foreach ($destr[1] as $list) {
        if (preg_match_all('~\$(\w+)~', $list, $dm)) $localAssign = array_merge($localAssign, $dm[1]);
    }
    preg_match_all('~\[([^\]]*)\]\s*=\s*~', $src, $listAssign);
    foreach ($listAssign[1] as $list) {
        if (str_contains($list, '$') && preg_match_all('~\$(\w+)~', $list, $lm)) {
            $localAssign = array_merge($localAssign, $lm[1]);
        }
    }
    /* Параметры стрелочных и анонимных функций тоже локальны. */
    preg_match_all('~(?:function|fn)\s*\(([^)]*)\)~', $src, $fnArgs);
    foreach ($fnArgs[1] as $args) {
        if (preg_match_all('~\$(\w+)~', $args, $am)) $localAssign = array_merge($localAssign, $am[1]);
    }
    $localAssign = array_merge($localAssign, ['this', 'GLOBALS', '_SESSION', '_POST', '_GET', '_SERVER', '_FILES']);
    foreach (array_unique($used[1]) as $u) {
        if (in_array($u, $keys, true) || in_array($u, $always, true) || in_array($u, $localAssign, true)) continue;
        $note('шаблоны', "$tpl: используется \$$u, контроллер его не передаёт");
    }
}

/* ---- C. Права: каталог объявлен — а спрашивают ли его ---- */
$appSrc = '';
foreach (array_merge((array)glob("$root/app/Controllers/*.php"), (array)glob("$root/app/Controllers/Admin/*.php")) as $f) {
    $appSrc .= (string)file_get_contents((string)$f);
}
/* Право спрашивают двумя способами, и оба законны: прямым вызовом и таблицей
   перед `switch` — `['impersonate' => 'impersonate', …]` плюс
   `requirePerm($needs[$action])`. Инструмент видел только первый и объявлял
   `impersonate` неспрошенным, хотя проверка стоит и работает. Ложная тревога
   про право доступа — худший вид шума: на неё либо зря тратят день, либо
   привыкают не верить. */
preg_match_all("~(?:can|requirePerm)\(\s*'([\w.]+)'~", $appSrc, $cm);
preg_match_all("~=>\s*'([a-z]+\.[a-z]+|impersonate|keys\.rotate|backup|housekeeping)'~", $appSrc, $tm);
$asked = array_unique(array_merge($cm[1], $tm[1]));
$declared = \App\Core\Permission::all();
$never = array_values(array_diff($declared, $asked));
if ($never !== []) $note('права', 'объявлены, но нигде не спрашиваются: ' . implode(', ', $never));
$unknown = array_values(array_diff($asked, $declared));
if ($unknown !== []) $note('права', 'спрашиваются, но не объявлены: ' . implode(', ', $unknown));

/* ---- D. SQL, который живёт только на sqlite ---- */
foreach (array_merge((array)glob("$root/app/**/*.php"), (array)glob("$root/app/*/*.php")) as $f) {
    $src = (string)file_get_contents((string)$f);
    if (preg_match('~SHOW\s+[A-Z ]+LIKE\s*\?~i', $src)) $note('SQL', basename((string)$f) . ': плейсхолдер в SHOW … LIKE');
    if (preg_match('~sqlite_master~', $src) && !preg_match('~driver|sqlite~i', $src)) {
        $note('SQL', basename((string)$f) . ': sqlite_master без проверки драйвера');
    }
}

/* ---- E. Параметры перевода с двоеточием ---- */
foreach (array_merge((array)glob("$root/templates/**/*.php"), (array)glob("$root/templates/*.php")) as $f) {
    if (preg_match("~\[':~", (string)file_get_contents((string)$f))) {
        $note('перевод', basename((string)$f) . ": ключ параметра с двоеточием — подстановка не сработает");
    }
}

/* ---- F. Файлы, на которые никто не ссылается ---- */
$all = '';
foreach (array_merge(
    (array)glob("$root/app/*.php"), (array)glob("$root/app/*/*.php"),
    (array)glob("$root/app/*/*/*.php"), (array)glob("$root/templates/*.php"),
    (array)glob("$root/templates/*/*.php"), (array)glob("$root/tests/*.php"),
    [$root . '/public/index.php', $root . '/cron.php', $root . '/system.php', $root . '/migrate.php']
) as $f) $all .= (string)file_get_contents((string)$f);
foreach ((array)glob("$root/app/Core/*.php") as $f) {
    $cls = basename((string)$f, '.php');
    if (substr_count($all, $cls) <= 1) $note('мёртвый код', "класс $cls нигде не упоминается");
}

echo "Маршрутов разобрано: $routeCount\n\n";
if ($found === []) { echo "Ревизия чистая\n"; exit(0); }
$total = 0;
foreach ($found as $group => $list) {
    $total += count($list);
    echo "── $group (" . count($list) . ")\n";
    foreach (array_slice($list, 0, 25) as $x) echo "   · $x\n";
    if (count($list) > 25) echo '   … и ещё ' . (count($list) - 25) . "\n";
    echo "\n";
}

/* Ревизия обязана уметь провалиться.
   До v4.45 скрипт печатал найденное и заканчивался — с кодом возврата 0.
   Пока его запускали руками, читал человек; в батарее он был бы зелёным
   всегда, что бы ни нашёл. Проверено возвратом дефекта: неиспользуемый класс
   находился, печатался, а код возврата оставался нулевым. */
echo "НАЙДЕНО: $total\n";
exit(1);
