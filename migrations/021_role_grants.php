<?php
/**
 * Схлопывание ролей «Админ портала» в одну — см. 021_role_grants.sql.
 *
 * Два шага, порядок обязателен:
 *   1. расширить ключ выдачи до (user_id, role_id, scope_type, scope_id);
 *   2. перенести выдачи на одну системную роль с областью портала.
 *
 * Наоборот нельзя: человек, админствующий на двух порталах, после переноса
 * даёт две строки с одинаковой парой (user_id, role_id), и вторая не ляжет.
 *
 * Шаг идемпотентен: повторный запуск видит, что портальных `client_admin`
 * больше нет, и не делает ничего.
 *
 * Всё живёт замыканиями внутри возвращаемого шага, а не функциями файла:
 * файл может быть подключён повторно, и объявленная в нём функция уронила бы
 * приложение переобъявлением.
 */

declare(strict_types=1);

use App\Core\DB;

return static function (): void {
    if (!DB::columnExists('user_roles', 'scope_type')) return;

    /* ── 1. Ключ выдачи: (user_id, role_id) → + область ───────────────────
     *
     * ВАЖНО про NULL. В первичный ключ MySQL не пускает колонку, допускающую
     * NULL: он молча делает её NOT NULL, а существующие NULL-ы конвертировать
     * нечем — и при строгом режиме получается «Data truncated for column
     * 'scope_id'». SQLite же NULL в ключе терпит, поэтому в разработке всё
     * было зелено, а на боевой миграция не применилась. Ровно та же развилка,
     * что погубила миграцию 014.
     *
     * Поэтому «нет идентификатора» — это НОЛЬ, а не NULL. Заодно чинится вторая
     * беда того же корня: сравнение `scope_id = NULL` не истинно никогда, и
     * проверка «такая выдача уже есть» его не находила — системные роли
     * множились при каждом вызове. Ноль сравнивается.
     */
    $rebuildKey = static function (): void {
        /* Сперва данные, потом схема: конвертировать NULL-ы после того, как
           колонка стала NOT NULL, будет уже нечем. */
        DB::run('UPDATE {user_roles} SET scope_id = 0 WHERE scope_id IS NULL');

        if (DB::driver() !== 'sqlite') {
            /* MySQL меняет и колонку, и ключ на месте. Повтор безвреден: тот же
               PRIMARY KEY отвалится как «уже есть», а это ловится и считается
               пропуском. */
            DB::run('ALTER TABLE {user_roles} MODIFY scope_id BIGINT UNSIGNED NOT NULL DEFAULT 0');
            try {
                DB::run('ALTER TABLE {user_roles} DROP PRIMARY KEY,
                         ADD PRIMARY KEY (user_id, role_id, scope_type, scope_id)');
            } catch (\Throwable $e) {
                if (!str_contains(mb_strtolower($e->getMessage()), 'duplicate')) throw $e;
            }
            return;
        }

        $table = DB::sql('{user_roles}');
        $ddl = (string)(DB::row('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?',
            ['table', $table])['sql'] ?? '');
        if ($ddl === '') return;
        /* Уже пересобрана — повтор ничего не делает. Признаком берём именно
           NOT NULL у scope_id: установки, прошедшие первую редакцию этого шага,
           имеют расширенный ключ, но колонку прежней, и их надо доделать. */
        $flat = str_replace(' ', '', $ddl);
        if (str_contains($flat, 'scope_type,scope_id)') && str_contains($flat, 'scope_idBIGINTUNSIGNEDNOTNULL')) {
            return;
        }

        /* DDL берём из самой базы, а не пишем руками: к этому шагу таблица
           прошла через двадцать миграций, и повторять её описание значит
           однажды разойтись с действительностью. */
        $newDdl = (string)preg_replace(
            '~PRIMARY\s+KEY\s*\(\s*user_id\s*,\s*role_id\s*\)~i',
            'PRIMARY KEY (user_id, role_id, scope_type, scope_id)',
            $ddl, 1);
        /* Колонка тоже обязана стать NOT NULL: держим схему одинаковой на обоих
           движках, иначе разойдётся не сегодня, так через две миграции. */
        $newDdl = (string)preg_replace(
            '~(,\s*)scope_id\s+[A-Za-z]+(\s+UNSIGNED)?(\s+NOT)?(\s+NULL)?(\s+DEFAULT\s+\S+)?~i',
            '$1scope_id BIGINT UNSIGNED NOT NULL DEFAULT 0',
            $newDdl, 1);
        if ($newDdl === $ddl) return;   // не в ожидаемом виде — не трогаем

        $tmp = $table . '_mig021';
        $newDdl = (string)preg_replace('~CREATE\s+TABLE\s+["`\[]?' . preg_quote($table, '~') . '["`\]]?~i',
            'CREATE TABLE "' . $tmp . '"', $newDdl, 1);
        $indexes = array_column(DB::all(
            'SELECT sql FROM sqlite_master WHERE type = ? AND tbl_name = ? AND sql IS NOT NULL',
            ['index', $table]), 'sql');

        /* Внешние ключи гасим на время пересборки и возвращаем ровно как было:
           соединение здесь не всегда наше — перенос базы зовёт миграции для
           приёмника и рассчитывает на выключенные ключи (см. 017). */
        $fkWas = (int)DB::pdo()->query('PRAGMA foreign_keys')->fetchColumn();
        DB::pdo()->exec('PRAGMA foreign_keys = OFF');
        try {
            DB::pdo()->exec('BEGIN');
            DB::pdo()->exec($newDdl);
            DB::pdo()->exec('INSERT INTO "' . $tmp . '" SELECT * FROM "' . $table . '"');
            DB::pdo()->exec('DROP TABLE "' . $table . '"');
            DB::pdo()->exec('ALTER TABLE "' . $tmp . '" RENAME TO "' . $table . '"');
            foreach ($indexes as $sql) DB::pdo()->exec((string)$sql);
            DB::pdo()->exec('COMMIT');
        } catch (\Throwable $e) {
            DB::pdo()->exec('ROLLBACK');
            DB::pdo()->exec('PRAGMA foreign_keys = ' . ($fkWas ? 'ON' : 'OFF'));
            throw $e;
        }
        DB::pdo()->exec('PRAGMA foreign_keys = ' . ($fkWas ? 'ON' : 'OFF'));
    };

    /* ── 2. Портальные «Админ портала» → одна системная роль ────────────── */
    $collapse = static function (): void {
        $perClient = DB::all('SELECT id, client_id FROM {roles} WHERE slug = ? AND client_id IS NOT NULL',
            ['client_admin']);
        if ($perClient === []) return;

        /* Роль-приёмник. Имя, уровень и права берём у одной из портальных:
           они заводились одинаково, а если администратор менял их у себя —
           правильнее сохранить настроенное, чем вернуть умолчание. */
        $sample = DB::row('SELECT * FROM {roles} WHERE id = ?', [(int)$perClient[0]['id']]);
        $sysId = DB::val('SELECT id FROM {roles} WHERE slug = ? AND client_id IS NULL', ['client_admin']);
        if ($sysId === null) {
            $sysId = DB::insert('roles', [
                'client_id' => null,
                'slug' => 'client_admin',
                'name' => (string)($sample['name'] ?? 'Админ портала'),
                'description' => $sample['description'] ?? null,
                'is_system' => 1,
                'access_level' => (int)($sample['access_level'] ?? 70),
                'scope_type' => 'system',
                'scope_id' => null,
                'created_at' => now(),
            ]);
            foreach (DB::all('SELECT perm FROM {role_permissions} WHERE role_id = ?',
                         [(int)$perClient[0]['id']]) as $p) {
                DB::run('INSERT INTO {role_permissions} (role_id, perm) VALUES (?, ?)',
                    [(int)$sysId, $p['perm']]);
            }
        }
        $sysId = (int)$sysId;

        foreach ($perClient as $role) {
            $roleId = (int)$role['id'];
            $clientId = (int)$role['client_id'];
            $grants = DB::all('SELECT user_id, granted_by, created_at FROM {user_roles} WHERE role_id = ?',
                [$roleId]);
            foreach ($grants as $g) {
                $exists = DB::row('SELECT 1 AS x FROM {user_roles}
                     WHERE user_id = ? AND role_id = ? AND scope_type = ? AND scope_id = ?',
                    [(int)$g['user_id'], $sysId, 'client', $clientId]);
                if (!$exists) {
                    DB::insert('user_roles', [
                        'user_id' => (int)$g['user_id'],
                        'role_id' => $sysId,
                        'granted_by' => $g['granted_by'] === null ? null : (int)$g['granted_by'],
                        'created_at' => $g['created_at'] ?? now(),
                        'scope_type' => 'client',
                        'scope_id' => $clientId,
                    ]);
                }
                DB::run('DELETE FROM {user_roles} WHERE user_id = ? AND role_id = ?',
                    [(int)$g['user_id'], $roleId]);
            }
            /* Карта соответствия ссылалась на портальную роль — переводим её
               на системную, иначе портал молча перестанет получать название,
               о котором договаривались. */
            DB::run('UPDATE {client_role_map} SET role_id = ? WHERE role_id = ?', [$sysId, $roleId]);
            DB::run('DELETE FROM {role_permissions} WHERE role_id = ?', [$roleId]);
            DB::run('DELETE FROM {roles} WHERE id = ?', [$roleId]);
        }
    };

    $rebuildKey();
    $collapse();
};
