<?php
/**
 * Ревизия DDL на пригодность для MySQL — без MySQL.
 *
 * Схема пишется на диалекте MySQL, а батарея гоняет SQLite через транслятор
 * SqliteCompat. Значит боевой движок не выполняет миграции ни разу за всю
 * проверку: зелёный прогон означает «SQLite это принял», а не «MySQL примет».
 * Дважды это уже стоило боевой аварии — на SQLite миграция проходила, на
 * MySQL падала, и падала она на сервере, где чинить неудобно.
 *
 * Хуже того, транслятор часть ошибок ПРЯЧЕТ. `id BIGINT UNSIGNED AUTO_INCREMENT`
 * без строки `PRIMARY KEY (id)` MySQL отвергает (1075), а транслятор превращает
 * такую колонку в `INTEGER PRIMARY KEY AUTOINCREMENT` — и на SQLite всё
 * проходит. Дефект существует, проверка зелёная, узнаём на боевой.
 *
 * Здесь проверяются ровно те правила MySQL, которых у SQLite нет вовсе:
 * они не могут проявиться на dev-машине ни при каком стечении обстоятельств.
 * Обратное направление (MySQL можно, SQLite нельзя) не проверяется — его и так
 * ловит каждый прогон батареи, на то он и прогон.
 *
 * Это не замена запуску на MySQL: настоящий сервер проверит и то, о чём здесь
 * не написано. Это нижняя граница — то, что можно поймать не выходя из dev.
 *
 * Запуск: php tools/mysql_lint.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$fails = 0;
$checks = 0;

/** Одна претензия к схеме. */
function bad(string $where, string $what, string $why): void
{
    global $fails;
    echo "  ✗ $where\n    $what\n    → $why\n";
    $fails++;
}

function ok(string $line): void
{
    echo "  ✓ $line\n";
}

/* Зарезервированные слова MySQL 8.0, которые легко взять именем колонки.
   Полный список длинный; здесь те, что реально просятся в схему такого
   проекта. Незакавыченное такое имя — синтаксическая ошибка в MySQL и
   совершенно законное имя в SQLite. */
const RESERVED = [
    'key', 'keys', 'order', 'group', 'groups', 'read', 'system', 'rank', 'lead',
    'range', 'row', 'rows', 'lag', 'first_value', 'last_value', 'over',
    'window', 'cume_dist', 'dense_rank', 'percent_rank', 'recursive',
    'grouping', 'except', 'interval', 'match', 'usage', 'primary', 'index',
    'default', 'condition', 'signal', 'resignal', 'partition', 'lateral',
    'optimizer_costs', 'stored', 'virtual', 'generated', 'function', 'call',
    'left', 'right', 'inner', 'outer', 'join', 'natural', 'using', 'force',
    'ignore', 'straight_join', 'to', 'from', 'where', 'having', 'limit',
];

/* Байт на символ в utf8mb4 — по нему считаются лимиты строки и ключа. */
const MB4 = 4;

/** Разбор одного CREATE TABLE в структуру: колонки, ключи, опции. */
function parseCreate(string $stmt): ?array
{
    if (!preg_match('~CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\S+)\s*\(~i', $stmt, $m)) return null;
    $open = strpos($stmt, '(');
    $close = strrpos($stmt, ')');
    if ($open === false || $close === false || $close <= $open) return null;
    $body = substr($stmt, $open + 1, $close - $open - 1);
    $tail = substr($stmt, $close + 1);

    /* Разбивка по запятым верхнего уровня — как в трансляторе, чтобы разбирать
       ровно то же самое и не разойтись с ним в трактовке. */
    $parts = [];
    $depth = 0; $buf = ''; $inStr = false;
    for ($i = 0, $len = strlen($body); $i < $len; $i++) {
        $ch = $body[$i];
        if ($ch === "'") $inStr = !$inStr;
        if (!$inStr) {
            if ($ch === '(') $depth++;
            if ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) { $parts[] = $buf; $buf = ''; continue; }
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') $parts[] = $buf;

    $cols = [];      // имя => ['type','len','null','default','auto','raw']
    $keys = [];      // ['kind','name','cols'=>[['col','prefix']]]
    foreach ($parts as $raw) {
        $line = trim(preg_replace('~\s+~', ' ', $raw) ?? '');
        if ($line === '') continue;

        if (preg_match('~^(UNIQUE\s+KEY|KEY|INDEX)\s+`?(\w+)`?\s*\((.+)\)$~i', $line, $mm)) {
            $keys[] = ['kind' => stripos($mm[1], 'UNIQUE') !== false ? 'UNIQUE' : 'KEY',
                       'name' => $mm[2], 'cols' => keyCols($mm[3]), 'raw' => $line];
            continue;
        }
        if (preg_match('~^PRIMARY\s+KEY\s*\((.+)\)$~i', $line, $mm)) {
            $keys[] = ['kind' => 'PRIMARY', 'name' => 'PRIMARY',
                       'cols' => keyCols($mm[1]), 'raw' => $line];
            continue;
        }
        if (preg_match('~^(CONSTRAINT|FOREIGN)~i', $line)) continue;

        if (!preg_match('~^`?([a-z_0-9]+)`?\s+([A-Za-z]+)(\(([^)]*)\))?(.*)$~i', $line, $mm)) continue;
        $rest = $mm[5] ?? '';
        $cols[strtolower($mm[1])] = [
            'name'    => $mm[1],
            'quoted'  => str_starts_with(trim($line), '`'),
            'type'    => strtoupper($mm[2]),
            'len'     => isset($mm[4]) && $mm[4] !== '' ? (int)$mm[4] : null,
            'null'    => stripos($rest, 'NOT NULL') === false,
            'default' => preg_match('~\bDEFAULT\s+(.+?)(\s+(?:COMMENT|ON\s+UPDATE|AUTO_INCREMENT)\b|$)~i', $rest, $dm)
                            ? trim($dm[1]) : null,
            'auto'    => stripos($rest, 'AUTO_INCREMENT') !== false,
            'raw'     => $line,
        ];
    }
    return ['table' => trim($m[1], '`'), 'cols' => $cols, 'keys' => $keys, 'tail' => $tail];
}

/** «`a`, `b`(20)» → [['col'=>'a','prefix'=>null], ['col'=>'b','prefix'=>20]] */
function keyCols(string $s): array
{
    $out = [];
    foreach (explode(',', $s) as $piece) {
        $piece = trim($piece);
        if (preg_match('~^`?([a-z_0-9]+)`?(?:\s*\((\d+)\))?~i', $piece, $m)) {
            $out[] = ['col' => strtolower($m[1]), 'prefix' => isset($m[2]) ? (int)$m[2] : null];
        }
    }
    return $out;
}

/** Сколько байт занимает колонка в ключе (utf8mb4). null — тип без предела. */
function keyBytes(array $col, ?int $prefix): ?int
{
    $t = $col['type'];
    if ($prefix !== null) return $prefix * MB4;
    return match (true) {
        in_array($t, ['VARCHAR', 'CHAR'], true) => (int)($col['len'] ?? 255) * MB4,
        in_array($t, ['TINYINT', 'SMALLINT'], true) => 2,
        in_array($t, ['INT', 'INTEGER', 'MEDIUMINT'], true) => 4,
        $t === 'BIGINT' => 8,
        in_array($t, ['DATETIME', 'TIMESTAMP'], true) => 8,
        $t === 'DATE' => 3,
        $t === 'VARBINARY' || $t === 'BINARY' => (int)($col['len'] ?? 255),
        default => null,
    };
}

/** Тип, который MySQL хранит вне строки: в ключ без длины нельзя, DEFAULT нельзя. */
function isBlobby(string $t): bool
{
    return in_array($t, ['TEXT', 'TINYTEXT', 'MEDIUMTEXT', 'LONGTEXT',
                         'BLOB', 'TINYBLOB', 'MEDIUMBLOB', 'LONGBLOB', 'JSON'], true);
}

echo "═══ Ревизия DDL на пригодность для MySQL ═══\n";
echo "Правила, которых нет у SQLite: на dev-машине такой дефект не проявится никогда.\n";

$files = (array)glob(SSO_ROOT . '/migrations/*.sql');
sort($files);

/* Схема копится по всем миграциям сразу: ALTER из 017 добавляет колонку в
   таблицу из 001, и судить о длине ключа можно только по итоговой картине. */
$schema = [];

foreach ($files as $file) {
    $name = basename((string)$file);
    $sql = (string)file_get_contents((string)$file);
    $sql = \App\Core\DB::sql($sql);

    foreach (\App\Core\SqliteCompat::splitStatements($sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;

        /* ENUM — ограничение, которого нет там, где пишут код.
           SQLite перечисление игнорирует: колонка становится TEXT и принимает
           что угодно, поэтому вся разработка и вся батарея идут мимо. Живёт
           ограничение ровно на боевой — и работает не как проверка, а как
           отказ у пользователя: «1265 Data truncated for column», а при
           нестрогом режиме MySQL и вовсе молча обрезанное значение.

           Так было дважды с `one_time_tokens.type`: типы `portal_install` и
           `owner_transfer` завели в коде и не дописали в перечисление из
           001_init. Ссылка установки портала и передача владения не работали
           на боевой вообще — и ни одна проверка этого не видела.

           Набор значений сторожит код (OneTimeToken::TTL, User::STATUSES,
           Org::ROLES): он один на оба движка. */
        if (preg_match_all('~^\s*(?:ADD\s+COLUMN\s+)?([a-z_]+)\s+ENUM\(~mi', $stmt, $em, PREG_SET_ORDER)) {
            foreach ($em as $hit) {
                bad("$name / {$hit[1]}", trim($hit[0]),
                    'ENUM: на SQLite не действует вовсе, значит проверить набор значений негде. '
                    . 'Ставьте VARCHAR — список значений держит код');
            }
            $checks++;
        }

        /* ---- CREATE TABLE ---- */
        if (preg_match('~^CREATE\s+TABLE~i', $stmt)) {
            $t = parseCreate($stmt);
            if ($t === null) { bad($name, substr($stmt, 0, 60), 'не разобрал CREATE TABLE'); continue; }
            $schema[$t['table']] = $t;
            $checks++;

            /* 1075: колонка AUTO_INCREMENT обязана быть ключом. Транслятор
               делает её PK сам и потому прячет пропуск. */
            foreach ($t['cols'] as $c) {
                if (!$c['auto']) continue;
                $isKey = false;
                foreach ($t['keys'] as $k) {
                    if (($k['cols'][0]['col'] ?? '') === strtolower($c['name'])) $isKey = true;
                }
                if (!$isKey) {
                    bad("$name / {$t['table']}.{$c['name']}", $c['raw'],
                        'AUTO_INCREMENT без ключа — MySQL 1075. SQLite принимает: транслятор сам делает такую колонку PRIMARY KEY');
                }
            }

            /* 1171: колонка первичного ключа не может быть NULL. */
            foreach ($t['keys'] as $k) {
                if ($k['kind'] !== 'PRIMARY') continue;
                foreach ($k['cols'] as $kc) {
                    $col = $t['cols'][$kc['col']] ?? null;
                    if ($col !== null && $col['null'] && !$col['auto']) {
                        bad("$name / {$t['table']}.{$kc['col']}", $col['raw'],
                            'колонка PRIMARY KEY объявлена NULL — MySQL 1171');
                    }
                }
            }

            /* 1101: TEXT/BLOB/JSON не может иметь DEFAULT. */
            foreach ($t['cols'] as $c) {
                if (isBlobby($c['type']) && $c['default'] !== null
                    && !str_starts_with($c['default'], '(')) {
                    bad("$name / {$t['table']}.{$c['name']}", $c['raw'],
                        "тип {$c['type']} с DEFAULT — MySQL 1101, значение по умолчанию таким типам не положено");
                }
            }

            /* 1118: длина строки. Сумма объявленных длин в utf8mb4 не должна
               перевалить за 65535 байт — иначе таблица не создастся вовсе. */
            $rowBytes = 0;
            foreach ($t['cols'] as $c) {
                if (isBlobby($c['type'])) { $rowBytes += 12; continue; } // указатель на внешнее хранилище
                $rowBytes += keyBytes($c, null) ?? 8;
            }
            if ($rowBytes > 65535) {
                bad("$name / {$t['table']}", "объявленная строка ≈ $rowBytes байт",
                    'больше 65535 — MySQL 1118, таблица не создастся. У SQLite предела нет');
            }

            /* 1059: длина имени. */
            foreach (array_merge([['n' => $t['table'], 'k' => 'таблица']],
                     array_map(fn($c) => ['n' => $c['name'], 'k' => 'колонка'], $t['cols']),
                     array_map(fn($k) => ['n' => $k['name'], 'k' => 'ключ'], $t['keys'])) as $id) {
                if (mb_strlen((string)$id['n']) > 64) {
                    bad("$name / {$t['table']}", "{$id['k']} «{$id['n']}»",
                        'имя длиннее 64 символов — MySQL 1059');
                }
            }

            /* Таблица без явного движка и кодировки: на сервере с другими
               умолчаниями получится MyISAM или latin1, и внешние ключи молча
               не создадутся. Транслятор этот хвост просто отбрасывает. */
            if (stripos($t['tail'], 'ENGINE') === false) {
                bad("$name / {$t['table']}", trim($t['tail']) ?: '(хвоста нет)',
                    'нет ENGINE=InnoDB — на сервере с другим умолчанием внешние ключи молча не создадутся');
            }
            if (stripos($t['tail'], 'utf8mb4') === false) {
                bad("$name / {$t['table']}", trim($t['tail']) ?: '(хвоста нет)',
                    'нет CHARSET=utf8mb4 — на сервере с latin1 эмодзи и часть кириллицы обрежутся молча');
            }
        }

        /* ---- ALTER TABLE ADD COLUMN: дополняем схему и судим о колонке ---- */
        if (preg_match('~^ALTER\s+TABLE\s+`?(\w+)`?\s+(.+)$~is', $stmt, $am)) {
            $table = $am[1];
            $checks++;
            if (preg_match('~ADD\s+(?:COLUMN\s+)?`?([a-z_0-9]+)`?\s+([A-Za-z]+)(\((\d+)\))?(.*)$~is', $am[2], $cm)) {
                $rest = $cm[5] ?? '';
                $col = [
                    'name' => $cm[1], 'type' => strtoupper($cm[2]),
                    'len' => isset($cm[4]) && $cm[4] !== '' ? (int)$cm[4] : null,
                    'null' => stripos($rest, 'NOT NULL') === false,
                    'default' => preg_match('~\bDEFAULT\s+(.+?)(\s+(?:AFTER|COMMENT)\b|$)~i', $rest, $dm) ? trim($dm[1]) : null,
                    'auto' => false, 'raw' => trim(preg_replace('~\s+~', ' ', $stmt) ?? ''),
                ];
                if (isset($schema[$table])) $schema[$table]['cols'][strtolower($cm[1])] = $col;

                if (isBlobby($col['type']) && $col['default'] !== null
                    && !str_starts_with($col['default'], '(')) {
                    bad("$name / $table.{$col['name']}", $col['raw'],
                        "тип {$col['type']} с DEFAULT — MySQL 1101");
                }
                /* NOT NULL без DEFAULT в таблицу с данными: MySQL подставит
                   неявное значение и промолчит, SQLite откажется. Значит
                   поймает нас dev, а не прод, — но сказать всё равно стоит:
                   неявное значение MySQL это тихая единица или пустая строка. */
                if (!$col['null'] && $col['default'] === null && $col['type'] !== 'TIMESTAMP') {
                    bad("$name / $table.{$col['name']}", $col['raw'],
                        'NOT NULL без DEFAULT при ALTER — MySQL молча подставит пустое значение уже существующим строкам');
                }
            }
        }

        /* ---- CREATE INDEX отдельным запросом ---- */
        if (preg_match('~^CREATE\s+(UNIQUE\s+)?INDEX\s+`?(\w+)`?\s+ON\s+`?(\w+)`?\s*\((.+?)\)~is', $stmt, $im)) {
            $checks++;
            $schema[$im[3]]['keys'][] = ['kind' => $im[1] ? 'UNIQUE' : 'KEY',
                'name' => $im[2], 'cols' => keyCols($im[4]),
                'raw' => trim(preg_replace('~\s+~', ' ', $stmt) ?? '')];
        }
    }
}

/* ---- Разбор ключей по итоговой схеме ---- */
foreach ($schema as $tname => $t) {
    foreach ($t['keys'] as $k) {
        $bytes = 0;
        $unknown = false;
        foreach ($k['cols'] as $kc) {
            $col = $t['cols'][$kc['col']] ?? null;
            if ($col === null) {
                bad("$tname / ключ {$k['name']}", $k['raw'],
                    "в ключе колонка «{$kc['col']}», которой в таблице нет");
                $unknown = true;
                continue;
            }
            /* 1170: TEXT/BLOB/JSON в ключе обязан иметь длину префикса.
               JSON нельзя индексировать вовсе. SQLite индексирует что угодно. */
            if (isBlobby($col['type']) && $kc['prefix'] === null) {
                bad("$tname / ключ {$k['name']}", $k['raw'],
                    $col['type'] === 'JSON'
                        ? 'JSON-колонка в ключе — MySQL 3152, индексировать её напрямую нельзя'
                        : "колонка {$col['type']} в ключе без длины префикса — MySQL 1170");
            }
            $b = keyBytes($col, $kc['prefix']);
            if ($b === null) { $unknown = true; continue; }
            $bytes += $b;
        }
        /* 1071: предел ключа InnoDB — 3072 байта при DYNAMIC. */
        if (!$unknown && $bytes > 3072) {
            bad("$tname / ключ {$k['name']}", $k['raw'],
                "ключ ≈ $bytes байт в utf8mb4 — больше 3072, MySQL 1071. У SQLite предела нет");
        }
    }

    /* Зарезервированные слова без обратных кавычек. */
    foreach ($t['cols'] as $c) {
        if (in_array(strtolower($c['name']), RESERVED, true) && !($c['quoted'] ?? false)) {
            bad("$tname.{$c['name']}", $c['raw'],
                'имя — зарезервированное слово MySQL, нужны обратные кавычки. SQLite такое имя принимает');
        }
    }
    if (in_array(strtolower($tname), RESERVED, true)) {
        bad($tname, 'имя таблицы', 'зарезервированное слово MySQL');
    }
}

echo "\n── Проверено ──\n";
printf("  таблиц в итоговой схеме: %d, запросов разобрано: %d\n", count($schema), $checks);

echo "\n══ Итог ══\n";
if ($fails === 0) {
    echo "DDL ПРИГОДЕН ДЛЯ MYSQL (по правилам, которых нет у SQLite)\n";
    echo "Это нижняя граница, а не гарантия: настоящий MySQL проверит и то, чего здесь нет.\n";
} else {
    echo "РАСХОЖДЕНИЙ С MYSQL: $fails\n";
}
exit($fails === 0 ? 0 : 1);
