<?php
/**
 * Ревизия настроек: что в коде читается, что в интерфейсе пишется.
 *
 * Настройка, которую код спрашивает, а интерфейс не даёт задать, — это
 * захардкоженное значение с лишним шагом. Настройка, которую интерфейс пишет,
 * а код не читает, — мёртвая галочка. Оба случая видно только сверкой, потому
 * что по отдельности и то и другое выглядит рабочим.
 *
 * Запуск: php tools/settings_audit.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/** Все .php проекта, кроме служебных каталогов. */
function walkPhp(string $dir, array $skip = []): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = str_replace('\\', '/', (string)$f);
        foreach ($skip as $s) if (str_contains($path, '/' . $s . '/')) continue 2;
        if (str_ends_with($path, '.php')) $out[] = $path;
    }
    return $out;
}

/* Ревизия смотрит на ПРОДУКТ, а не на всё подряд.
   Батарея дёргает `Setting::get('app.name')`, проверяя, что чтение настроек
   пережило восстановление конфига, — и ключ попадал в отчёт как «читается, а
   задать негде». Читает его не продукт, а проверка продукта.
   Ещё смешнее было с `tools/`: ревизия обходила саму себя и засчитывала как
   чтение примеры в собственных регулярках. То есть отчёт полтора релиза
   показывал ключ, которого в приложении нет вовсе. */
$files = walkPhp($root, ['dist', 'vendor', 'storage', 'node_modules', 'tests', 'tools']);

$read = [];     // ключ => файлы, где читают
$write = [];    // ключ => файлы, где пишут
foreach ($files as $f) {
    $src = (string)file_get_contents($f);
    $short = ltrim(str_replace(str_replace('\\', '/', $root), '', $f), '/');

    /* Читают: Setting::get('x'), setting('x'), Setting::getInt('x') — и то же
       через self:: внутри самого Setting. Без self:: половина ключей выглядела
       бы «нигде не читается»: именованные обёртки вроде lockoutAttempts()
       живут именно там. */
    if (preg_match_all("~(?:Setting::|self::)get(?:Int|Bool|Json)?\(\s*'([a-z0-9_.]+)'~i", $src, $m)) {
        foreach ($m[1] as $k) $read[$k][$short] = true;
    }
    if (preg_match_all("~\bsetting\(\s*'([a-z0-9_.]+)'~i", $src, $m)) {
        foreach ($m[1] as $k) $read[$k][$short] = true;
    }
    /* Пишут: Setting::set('x', …) и Setting::delete('x'). */
    if (preg_match_all("~(?:Setting::|self::)(?:set|delete)\(\s*'([a-z0-9_.]+)'~i", $src, $m)) {
        foreach ($m[1] as $k) $write[$k][$short] = true;
    }
    /* То же в ДВОЙНЫХ кавычках.
       Все регулярки выше знают только одинарные — и вся семья юридических
       документов из Legal.php (`docs.$key.enabled`, `.body`, `.version`,
       `.updated_at`) ревизии не существовала. Отчёт при этом уверенно писал
       «только читаются: 0», не посмотрев на них ни разу.
       Дефект того же жанра, что промах со словарём: гейт зелёный не потому,
       что чисто, а потому, что сканер туда не смотрит. */
    if (preg_match_all('~(?:Setting::|self::)(get(?:Int|Bool|Json)?|set|delete)\(\s*"([a-z0-9_.]+)"~i', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            if (stripos($hit[1], 'get') === 0) $read[$hit[2]][$short] = true;
            else $write[$hit[2]][$short] = true;
        }
    }
    /* Двойные кавычки с подстановкой: "docs.$key.enabled" — тоже семейство. */
    if (preg_match_all('~(?:Setting::|self::)(?:get(?:Int|Bool|Json)?|set|delete)\(\s*"([a-z0-9_]+\.)\$~i', $src, $m)) {
        foreach ($m[1] as $k) $dynamic[$k][$short] = true;
    }
    /* Ключ, собранный из куска и переменной: Setting::set('policy.' . $flag).
       Такой не ключ, а СЕМЕЙСТВО, и в списке он выглядел бы обрубком
       «policy.» — то есть мёртвой настройкой, которой на деле нет. */
    if (preg_match_all("~(?:Setting::|self::)(?:get(?:Int|Bool)?|set|delete)\(\s*'([a-z0-9_.]*\.)'\s*\.~i", $src, $m)) {
        foreach ($m[1] as $k) $dynamic[$k][$short] = true;
    }
    /* Семейство с переменной В СЕРЕДИНЕ: 'social.' . $id . '.enabled'. */
    if (preg_match_all("~'([a-z0-9_]+\.)'\s*\.\s*[^,;]+?\.\s*'(\.[a-z0-9_]+)'~i", $src, $m)) {
        foreach ($m[1] as $k) $dynamic[$k][$short] = true;
    }

    /* Ключ через константу класса: `const NONCE_KEY = 'system.move.nonce'`, а
       дальше `Setting::get(self::NONCE_KEY)`. Для регулярки выше такого чтения
       не существует, и ключ выглядел «мёртвой галочкой» — притом что читается
       он в том же файле двумя строками ниже. Именно так `system.move.nonce`
       полтора релиза числился в отчёте мёртвым.

       Разбираем в два шага: сначала карта «константа → значение» по файлу,
       потом обращения к ней. Файл — вся область видимости, которая тут нужна:
       ключи настроек не объявляют в одном классе, чтобы читать в другом. */
    $consts = [];
    if (preg_match_all("~\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*'([a-z0-9_.]+)'~", $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $c) $consts[$c[1]] = $c[2];
    }
    if ($consts !== []) {
        $names = implode('|', array_map('preg_quote', array_keys($consts)));
        if (preg_match_all(
            "~(?:Setting::|self::)(get(?:Int|Bool|Json)?|set|delete)\(\s*(?:self|static)::($names)\b~",
            $src, $m, PREG_SET_ORDER
        )) {
            foreach ($m as $hit) {
                $key = $consts[$hit[2]];
                if (str_starts_with($hit[1], 'get')) $read[$key][$short] = true;
                else $write[$key][$short] = true;
            }
        }
    }
}
$dynamic = $dynamic ?? [];

/* Обрубки семейств выбрасываем: «policy.» — не настройка, а способ записи. */
$all = array_unique(array_merge(array_keys($read), array_keys($write)));
$all = array_values(array_filter($all, static fn(string $k): bool => !str_ends_with($k, '.')));
sort($all);

/* Группируем по префиксу: он же и есть раздел настроек. */
$groups = [];
foreach ($all as $k) {
    $prefix = str_contains($k, '.') ? substr($k, 0, strpos($k, '.')) : '(без раздела)';
    $groups[$prefix][] = $k;
}
ksort($groups);

$onlyRead = [];
$onlyWrite = [];
$writtenInUi = [];

/** Пишется ли ключ семейством: 'policy.' . $flag покрывает policy.require_phone. */
$byFamily = static function (string $key) use ($dynamic): bool {
    foreach (array_keys($dynamic) as $prefix) {
        if ($prefix !== '' && str_starts_with($key, $prefix)) return true;
    }
    return false;
};

foreach ($all as $k) {
    $w = array_keys($write[$k] ?? []);
    $r = array_keys($read[$k] ?? []);
    $ui = array_values(array_filter($w, static fn(string $f): bool
        => str_contains($f, 'Controllers/Admin/') || str_contains($f, 'install/')));
    $family = $byFamily($k);
    if ($r !== [] && $w === [] && !$family) $onlyRead[] = $k;
    if ($r === [] && $w !== [] && !$family) $onlyWrite[] = $k;
    if ($ui !== [] || $family) $writtenInUi[] = $k;
}

echo "═══ Настройки по разделам ═══\n";
foreach ($groups as $prefix => $keys) {
    printf("\n%-12s %d\n", $prefix, count($keys));
    foreach ($keys as $k) {
        $inUi = in_array($k, $writtenInUi, true);
        $r = count($read[$k] ?? []);
        $w = count($write[$k] ?? []);
        printf("  %-34s читают:%-2d пишут:%-2d %s\n", $k, $r, $w, $inUi ? '' : '← не из интерфейса');
    }
}

echo "\n═══ Итог ═══\n";
printf("всего ключей:            %d\n", count($all));
printf("настраиваются из админки:%d\n", count($writtenInUi));
printf("только читаются:         %d\n", count($onlyRead));
printf("только пишутся:          %d\n", count($onlyWrite));

if ($onlyRead !== []) {
    echo "\nЧитаются, но задать негде (значение по умолчанию — навсегда):\n";
    foreach ($onlyRead as $k) echo "  · $k\n";
}
if ($onlyWrite !== []) {
    echo "\nПишутся, но никто не читает (мёртвая галочка):\n";
    foreach ($onlyWrite as $k) echo "  · $k\n";
}

/* Ревизия обязана уметь провалиться.
   До v4.45 этот файл заканчивался печатью и падал за конец скрипта с кодом 0 —
   то есть не мог сказать «плохо» никаким способом. Пока его запускали руками,
   это было незаметно: смотрел человек. В батарее такая проверка вечно зелёная,
   а вечно зелёная проверка хуже отсутствующей — на неё рассчитывают.
   Провал ровно по двум условиям, ради которых ревизия и написана: настройку
   читают, а задать негде; настройку пишут, а никто не читает. */
exit($onlyRead === [] && $onlyWrite === [] ? 0 : 1);
