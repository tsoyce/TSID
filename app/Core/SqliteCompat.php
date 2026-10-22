<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Трансляция нашего подмножества MySQL-DDL в SQLite — только для dev-режима.
 * Прод всегда MySQL; транслятор покрывает ровно те конструкции, что есть в /migrations.
 */
final class SqliteCompat
{
    public static function translate(string $sql): string
    {
        $out = [];
        foreach (self::splitStatements($sql) as $stmt) {
            $t = trim($stmt);
            if ($t === '') continue;
            /* MySQL-ное «ADD COLUMN … AFTER other» SQLite не понимает, а порядок
               колонок ни на что не влияет — просто срезаем хвост. */
            if (preg_match('~^ALTER\s+TABLE~i', $t)) {
                $t = (string)preg_replace('~\s+AFTER\s+`?[a-z_0-9]+`?~i', '', $t);
                /* ENUM переводим и здесь, а не только внутри CREATE TABLE.
                   SQLite к именам типов терпим — `VARCHAR(255)` и
                   `BIGINT UNSIGNED` он принимает как есть, поэтому остальные
                   переводы в ALTER не нужны. Но `ENUM('a','b')` — не имя типа
                   с размером, а строковые литералы в скобках, и разбор падает
                   с «near 'a': syntax error». До сих пор это не всплывало
                   просто потому, что ни одна миграция не добавляла ENUM
                   колонкой к существующей таблице. */
                $t = (string)preg_replace('~\bENUM\s*\([^)]*\)~i', 'TEXT', $t);
            }
            if (preg_match('~^CREATE\s+TABLE~i', $t)) {
                $out = array_merge($out, self::translateCreate($t));
            } else {
                $out[] = $t;
            }
        }
        return implode(";\n", $out) . ';';
    }

    /** Разбивка на statement'ы по ';' вне строк/комментариев. */
    public static function splitStatements(string $sql): array
    {
        // Убираем комментарии '-- …' целиком, включая хвостовые после определения
        // колонки: иначе при разборе CREATE TABLE следующая колонка «съедается»
        // комментарием предыдущей строки.
        $sql = preg_replace('~--[^\n]*~', '', $sql);
        $stmts = [];
        $buf = '';
        $inStr = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === "'" ) $inStr = !$inStr;
            if ($ch === ';' && !$inStr) {
                $stmts[] = $buf;
                $buf = '';
            } else {
                $buf .= $ch;
            }
        }
        if (trim($buf) !== '') $stmts[] = $buf;
        return $stmts;
    }

    private static function translateCreate(string $stmt): array
    {
        /* Имя таблицы. `IF NOT EXISTS` допускаем: без него разбор молча не
           находил имя и падал на `$m[1]` с «Undefined array key 1» — сообщение,
           по которому причину не угадать. */
        if (!preg_match('~CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\S+)\s*\(~i', $stmt, $m)) {
            throw new \RuntimeException('Не разобрал CREATE TABLE: ' . substr(trim($stmt), 0, 60));
        }
        $table = trim($m[1], '`');
        // Тело — между первой '(' и последней ')'
        $open = strpos($stmt, '(');
        $close = strrpos($stmt, ')');
        $body = substr($stmt, $open + 1, $close - $open - 1);

        $lines = self::splitTopLevel($body);
        $cols = [];
        $indexes = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('~^UNIQUE\s+KEY\s+(\w+)\s*\((.+)\)$~i', $line, $mm)) {
                $indexes[] = "CREATE UNIQUE INDEX {$table}_{$mm[1]} ON $table (" . str_replace('`', '', $mm[2]) . ')';
                continue;
            }
            if (preg_match('~^KEY\s+(\w+)\s*\((.+)\)$~i', $line, $mm)) {
                $indexes[] = "CREATE INDEX {$table}_{$mm[1]} ON $table (" . str_replace('`', '', $mm[2]) . ')';
                continue;
            }
            if (preg_match('~^PRIMARY\s+KEY\s*\((.+)\)$~i', $line, $mm)) {
                // Если единственная колонка PK уже стала INTEGER PRIMARY KEY — пропускаем
                $pkCols = str_replace([' ', '`'], '', $mm[1]);
                if (!str_contains($pkCols, ',') && self::hasAutoPk($cols, $pkCols)) continue;
                $cols[] = 'PRIMARY KEY (' . str_replace('`', '', $mm[1]) . ')';
                continue;
            }
            // Колонки и FK-констрейнты
            $line = preg_replace('~BIGINT\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT~i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $line);
            $line = preg_replace('~\bENUM\s*\([^)]*\)~i', 'TEXT', $line);
            $line = preg_replace('~\bVARBINARY\(\d+\)~i', 'BLOB', $line);
            $line = preg_replace('~\b(MEDIUMTEXT|LONGTEXT|JSON)\b~i', 'TEXT', $line);
            $line = preg_replace('~\b(VARCHAR|CHAR)\(\d+\)~i', 'TEXT', $line);
            $line = preg_replace('~\b(BIGINT|INT|SMALLINT|TINYINT)\b(\(\d+\))?(\s+UNSIGNED)?~i', 'INTEGER', $line);
            $line = preg_replace('~\bON\s+UPDATE\s+CURRENT_TIMESTAMP~i', '', $line);
            $cols[] = trim($line);
        }
        $create = "CREATE TABLE $table (\n  " . implode(",\n  ", $cols) . "\n)";
        return array_merge([$create], $indexes);
    }

    private static function hasAutoPk(array $cols, string $pkCol): bool
    {
        foreach ($cols as $c) {
            if (str_contains($c, 'INTEGER PRIMARY KEY AUTOINCREMENT')
                && preg_match('~^`?' . preg_quote($pkCol, '~') . '`?\s~', trim($c))) return true;
        }
        return false;
    }

    /** Разбивка тела CREATE на строки-определения по запятым верхнего уровня. */
    private static function splitTopLevel(string $body): array
    {
        $parts = [];
        $depth = 0;
        $buf = '';
        $inStr = false;
        for ($i = 0, $len = strlen($body); $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === "'") $inStr = !$inStr;
            if (!$inStr) {
                if ($ch === '(') $depth++;
                if ($ch === ')') $depth--;
                if ($ch === ',' && $depth === 0) {
                    $parts[] = $buf;
                    $buf = '';
                    continue;
                }
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') $parts[] = $buf;
        return $parts;
    }
}
