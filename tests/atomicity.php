<?php
/**
 * Многошаговая запись переживает обрыв: либо целиком, либо никак.
 *
 * До v4.45 помощник `DB::tx()` был объявлен и не использовался НИГДЕ — все
 * удаления и слияния шли россыпью отдельных запросов. Пока база отвечает,
 * разницы не видно; она проявляется ровно в тот момент, когда соединение
 * обрывается на середине, — то есть на боевой, под нагрузкой, у одного
 * человека, и разобрать потом уже нечего.
 *
 * Хуже всего это выглядело в удалении пользователя: семнадцать удалений
 * подряд, и обрыв на десятом оставлял аккаунт, у которого больше нет ни
 * сессий, ни ролей, ни ключей, ни согласий. Это не удалённый пользователь и
 * не живой — это сломанный, и вернуть его нечем.
 *
 * Проверка не рассуждает об атомарности, а ломает операцию НАСТОЯЩИМ отказом
 * базы: временный триггер обрывает последний шаг. Если работа атомарна, всё
 * сделанное до этого шага исчезает; если нет — остаётся половина.
 *
 * Запуск: php tests/atomicity.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Models\User;

$passed = 0;
$failed = 0;

if (DB::driver() !== 'sqlite') {
    echo "Проверка рассчитана на dev-базу SQLite.\n";
    exit(0);
}

/** Имя таблицы с префиксом — триггер пишется в сыром SQL, подстановки в нём нет. */
function raw(string $table): string
{
    return trim(DB::sql('{' . $table . '}'), '` ');
}

/**
 * Обрыв на последнем шаге.
 *
 * Триггер BEFORE DELETE, поднимающий ABORT, — это ровно то, чем отличается
 * упавшее соединение от штатной работы: несколько запросов уже прошли, а
 * следующий не проходит. Подделывать такое на уровне PHP бессмысленно —
 * проверять надо поведение БАЗЫ, а не наши намерения насчёт неё.
 */
function boomOnDelete(string $table, callable $body): bool
{
    $t = raw($table);
    DB::pdo()->exec("CREATE TRIGGER zzz_boom BEFORE DELETE ON $t
                     BEGIN SELECT RAISE(ABORT, 'обрыв'); END");
    $threw = false;
    try {
        $body();
    } catch (\Throwable) {
        $threw = true;
    } finally {
        DB::pdo()->exec('DROP TRIGGER IF EXISTS zzz_boom');
    }
    return $threw;
}

echo "═══ Атомарность многошаговых записей ═══\n";

/* ---------- 1. Сам помощник ---------- */
echo "\n── DB::tx ──\n";

$probe = raw('settings');
DB::run('DELETE FROM {settings} WHERE `key` LIKE ?', ['zzz.atom%']);

try {
    DB::tx(static function (): void {
        DB::run('INSERT INTO {settings} (`key`, `value`) VALUES (?, ?)', ['zzz.atom.a', '1']);
        DB::run('INSERT INTO {settings} (`key`, `value`) VALUES (?, ?)', ['zzz.atom.b', '2']);
        throw new \RuntimeException('обрыв');
    });
} catch (\Throwable) {
}
ok((int)DB::val('SELECT COUNT(*) FROM {settings} WHERE `key` LIKE ?', ['zzz.atom%']) === 0,
   'исключение внутри tx() откатывает всё, что успело записаться');

DB::tx(static function (): void {
    DB::run('INSERT INTO {settings} (`key`, `value`) VALUES (?, ?)', ['zzz.atom.a', '1']);
});
ok((int)DB::val('SELECT COUNT(*) FROM {settings} WHERE `key` LIKE ?', ['zzz.atom%']) === 1,
   'без исключения tx() фиксирует записанное');

/* Вложение: PDO вложенных транзакций не умеет, и до v4.45 второй
   beginTransaction() просто бросил бы исключение. Раз обёрток стало несколько,
   рано или поздно одна позовёт другую — и узнать об этом из падения на боевой
   было бы обидно. */
$nestedThrew = false;
try {
    DB::tx(static function (): void {
        DB::run('INSERT INTO {settings} (`key`, `value`) VALUES (?, ?)', ['zzz.atom.b', '2']);
        DB::tx(static function (): void {
            DB::run('INSERT INTO {settings} (`key`, `value`) VALUES (?, ?)', ['zzz.atom.c', '3']);
        });
    });
} catch (\Throwable $e) {
    $nestedThrew = true;
    echo "    (вложение упало: {$e->getMessage()})\n";
}
ok(!$nestedThrew, 'вложенный tx() не падает — внутренний присоединяется к внешнему');
ok((int)DB::val('SELECT COUNT(*) FROM {settings} WHERE `key` LIKE ?', ['zzz.atom%']) === 3,
   'вложенный tx() фиксируется вместе с внешним');

try {
    DB::tx(static function (): void {
        DB::run('DELETE FROM {settings} WHERE `key` = ?', ['zzz.atom.a']);
        DB::tx(static function (): void {
            throw new \RuntimeException('обрыв внутри вложенного');
        });
    });
} catch (\Throwable) {
}
ok((int)DB::val('SELECT COUNT(*) FROM {settings} WHERE `key` LIKE ?', ['zzz.atom%']) === 3,
   'падение внутри вложенного откатывает и работу внешнего');

DB::run('DELETE FROM {settings} WHERE `key` LIKE ?', ['zzz.atom%']);

/* ---------- 2. Удаление пользователя ---------- */
echo "\n── User::purge ──\n";

$uid = User::create([
    'email' => 'atom' . time() . '@test.local',
    'first_name' => 'Атом',
    'status' => 'active',
]);
$user = User::find($uid);
ok($user !== null, 'подопытный пользователь заведён');

/* Разложим по нескольким таблицам, которые purge выметает ДО строки users:
   именно они и остались бы сиротами при обрыве. */
DB::run('INSERT INTO {user_roles} (user_id, role_id) VALUES (?, ?)',
    [$uid, (int)DB::val('SELECT id FROM {roles} ORDER BY id LIMIT 1')]);
DB::run('INSERT INTO {notification_prefs} (user_id, event, enabled) VALUES (?, ?, ?)',
    [$uid, 'zzz.atom', 1]);

$before = [
    'roles' => (int)DB::val('SELECT COUNT(*) FROM {user_roles} WHERE user_id = ?', [$uid]),
    'prefs' => (int)DB::val('SELECT COUNT(*) FROM {notification_prefs} WHERE user_id = ?', [$uid]),
];
ok($before['roles'] > 0 && $before['prefs'] > 0, 'привязанные строки на месте до удаления');

$threw = boomOnDelete('users', static fn() => User::purge($uid));
ok($threw, 'удаление упало на последнем шаге (как при обрыве соединения)');

$after = [
    'user'  => (int)DB::val('SELECT COUNT(*) FROM {users} WHERE id = ?', [$uid]),
    'roles' => (int)DB::val('SELECT COUNT(*) FROM {user_roles} WHERE user_id = ?', [$uid]),
    'prefs' => (int)DB::val('SELECT COUNT(*) FROM {notification_prefs} WHERE user_id = ?', [$uid]),
];
ok($after['user'] === 1, 'пользователь на месте — раз удалиться целиком не вышло');
ok($after['roles'] === $before['roles'],
   'роли не пропали', "было {$before['roles']}, стало {$after['roles']}");
ok($after['prefs'] === $before['prefs'],
   'настройки уведомлений не пропали', "было {$before['prefs']}, стало {$after['prefs']}");

/* Теперь по-честному, без триггера: удаление обязано работать. Проверка,
   доказывающая только откат, зелена и у кода, который не делает ничего. */
User::purge($uid);
ok((int)DB::val('SELECT COUNT(*) FROM {users} WHERE id = ?', [$uid]) === 0,
   'без обрыва пользователь удаляется');
ok((int)DB::val('SELECT COUNT(*) FROM {user_roles} WHERE user_id = ?', [$uid]) === 0,
   'вместе с привязанными строками');

/* ---------- 3. Удаление организации ---------- */
echo "\n── Org::purge ──\n";

if (DB::tableExists('orgs')) {
    $oid = (int)DB::insert('orgs', ['name' => 'Атом', 'slug' => 'atom-' . time(),
                                    'created_at' => now()]);
    DB::run('INSERT INTO {org_members} (org_id, user_id, role, created_at) VALUES (?, ?, ?, ?)',
        [$oid, 1, 'member', now()]);
    $membersBefore = (int)DB::val('SELECT COUNT(*) FROM {org_members} WHERE org_id = ?', [$oid]);

    $threw = boomOnDelete('orgs', static fn() => \App\Models\Org::purge($oid));
    ok($threw, 'удаление организации упало на последнем шаге');
    ok((int)DB::val('SELECT COUNT(*) FROM {org_members} WHERE org_id = ?', [$oid]) === $membersBefore,
       'состав организации уцелел');

    \App\Models\Org::purge($oid);
    ok((int)DB::val('SELECT COUNT(*) FROM {orgs} WHERE id = ?', [$oid]) === 0,
       'без обрыва организация удаляется');
} else {
    echo "  · таблицы orgs нет — пропуск\n";
}

/* ---------- 4. Правило на будущее ---------- */
echo "\n── Кто ещё пишет в несколько шагов ──\n";

/**
 * Всякая функция с тремя и более записями обязана либо идти одним куском,
 * либо быть названной здесь с причиной.
 *
 * Без такого правила сегодняшняя починка живёт до следующей функции: тот, кто
 * напишет четвёртое удаление россыпью, не сделает ничего дурного — он просто
 * не будет знать, что так нельзя. Список исключений короткий и объясняется
 * вслух: транзакция вокруг сетевого вызова хуже её отсутствия, потому что
 * блокировки в базе будут держаться ровно столько, сколько молчит чужой
 * сервер. Такие места атомарными не делают — их делают повторяемыми.
 */
const ALLOWED_WITHOUT_TX = [
    'WebhookDispatcher::attempt' =>
        'записи — это переходы статуса ВОКРУГ HTTP-запроса к чужому серверу; транзакция держала бы блокировки всё время его молчания',
    'DataExport::build' =>
        'то же самое вокруг сборки ZIP на диске: файл транзакция всё равно не откатит, а задание перезапускается',
    'CronTasks::tasks' =>
        'не операция, а перечень заданий: записи лежат в разных замыканиях и выполняются в разное время',
    'Mailer::attempt' =>
        'v5.4: захват письма условным UPDATE, потом отправка по SMTP и итог; захват обязан быть виден до отправки, иначе второй cron пошлёт письмо ещё раз',
    'RateLimiter::hit' =>
        'v5.4: записи — взаимоисключающие ветви (продлить окно, начать новое, вставить); каждая — один условный запрос, атомарный сам по себе',
];

$multi = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(SSO_ROOT . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = str_replace('\\', '/', (string)$f);
    if (!str_ends_with($path, '.php') || str_ends_with($path, 'Core/DB.php')) continue;
    $src = (string)file_get_contents($path);
    $cls = basename($path, '.php');
    $len = strlen($src);
    $i = 0;
    while (preg_match('~function\s+(\w+)\s*\(~', $src, $m, PREG_OFFSET_CAPTURE, $i)) {
        $name = $m[1][0];
        $brace = strpos($src, '{', (int)$m[0][1]);
        if ($brace === false) break;
        $depth = 0; $end = $brace;
        for ($p = $brace; $p < $len; $p++) {
            if ($src[$p] === '{') $depth++;
            elseif ($src[$p] === '}') { $depth--; if ($depth === 0) { $end = $p; break; } }
        }
        $body = substr($src, $brace, $end - $brace + 1);
        $i = $end + 1;

        /* DB::sweep([...]) — одна строка кода, но столько записей, сколько
           таблиц в списке. Считать её за одну значило бы не заметить самое
           длинное удаление в проекте: у User::purge их шестнадцать. */
        $writes = preg_match_all('~DB::(insert|update|delete)\(~', $body)
                + preg_match_all('~DB::run\(\s*[\'"]\s*(INSERT|UPDATE|DELETE|REPLACE)~i', $body)
                + preg_match_all('~DB::upsert\(~', $body);
        if (preg_match_all('~DB::sweep\(\s*\[(.*?)\]~s', $body, $sm)) {
            foreach ($sm[1] as $list) $writes += max(1, substr_count($list, ','), 1) + 1;
        }
        if ($writes < 3) continue;
        $multi["$cls::$name"] = ['w' => $writes, 'tx' => str_contains($body, 'DB::tx(')];
    }
}

ksort($multi);
$bare = [];
foreach ($multi as $fn => $info) {
    if ($info['tx'] || isset(ALLOWED_WITHOUT_TX[$fn])) continue;
    $bare[] = "$fn ({$info['w']} записей)";
}
printf("  найдено функций с 3+ записями: %d, из них одним куском: %d, оговорено: %d\n",
    count($multi), count(array_filter($multi, fn($i) => $i['tx'])), count(ALLOWED_WITHOUT_TX));
ok($bare === [], 'все многошаговые записи либо атомарны, либо оговорены',
   implode('; ', $bare));

/* Оговорка, потерявшая адресата, — мусор, который со временем начинает
   покрывать не то, что покрывал. */
foreach (array_keys(ALLOWED_WITHOUT_TX) as $fn) {
    if (!isset($multi[$fn])) {
        ok(false, "оговорка $fn относится к существующей функции",
           'функции с таким числом записей больше нет — оговорку пора убрать');
    }
}

echo "\n══ Итог ══\n";
printf("Пройдено: %d, провалено: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
