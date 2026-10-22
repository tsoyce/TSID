<?php
/**
 * SSO · системные операции из командной строки.
 *
 * Запасной выход на случай «владелец потерял доступ». Работает у того, у кого
 * есть файлы и база, то есть у хозяина хостинг-аккаунта. Это правильная
 * граница: кто владеет сервером, тот владеет установкой, и делать вид, что
 * иначе, — самообман.
 *
 *   php system.php owner            — кто владелец сейчас
 *   php system.php owner:set <email> — назначить владельцем этот аккаунт
 *   php system.php domain           — текущий адрес инсталляции
 *   php system.php db               — текущее подключение к базе
 *   php system.php incident <id>    — найти событие по номеру со страницы 500
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/app/bootstrap.php';

if (!\App\Core\Config::installed()) {
    fwrite(STDERR, "SSO не установлен\n");
    exit(1);
}

$cmd = $argv[1] ?? '';
$arg = $argv[2] ?? '';

switch ($cmd) {
    /* Инцидент по номеру со страницы 500. Здесь, а не только в админке:
       если сломалось само приложение, админка может быть недоступна — а номер
       у человека уже есть, и он ждёт ответа. */
    case 'incident':
        if ($arg === '') {
            fwrite(STDERR, "Укажите номер: php system.php incident a6154c59\n");
            exit(1);
        }
        if (!\App\Core\Incident::valid($arg)) {
            fwrite(STDERR, "Номер состоит из восьми знаков — цифр и букв a–f\n");
            exit(1);
        }
        $hits = \App\Core\Incident::find($arg);
        if ($hits === []) {
            echo "Инцидент $arg в журналах не найден.\n",
                 "Записи чистятся по сроку хранения — возможно, он старше него.\n";
            exit(1);
        }
        echo "Инцидент $arg — записей: ", count($hits), ", файл ", $hits[0]['file'], "\n\n";
        foreach ($hits as $hit) {
            echo \App\Core\Incident::describe($hit), "\n\n";
        }
        break;

    case 'owner':
        $u = \App\Core\Owner::user();
        echo $u ? ('Владелец: ' . user_name($u) . ' <' . $u['email'] . '> #' . $u['id'] . "\n")
                : "Владельца нет: в системе нет ни одного живого суперадмина\n";
        break;

    case 'owner:set':
        if ($arg === '') {
            fwrite(STDERR, "Укажите email: php system.php owner:set admin@example.ru\n");
            exit(1);
        }
        $user = \App\Models\User::findByEmail(mb_strtolower(trim($arg)));
        if (!$user) {
            fwrite(STDERR, "Аккаунт $arg не найден\n");
            exit(1);
        }
        /* Владельцем может быть только суперадмин — иначе владелец не сможет
           открыть админку, в которой лежит его раздел. Выдаём роль сами: тот,
           кто дошёл до CLI, и так может всё. */
        if (!in_array('superadmin', \App\Models\User::globalRoleSlugs((int)$user['id']), true)) {
            $role = \App\Models\Role::findBySlug('superadmin');
            if (!$role) {
                fwrite(STDERR, "Роли superadmin нет в базе — сначала примените миграции\n");
                exit(1);
            }
            \App\Models\Role::assign((int)$user['id'], (int)$role['id']);
            echo "Выдана роль superadmin\n";
        }
        if (!\App\Core\Owner::set((int)$user['id'], null, 'cli')) {
            fwrite(STDERR, "Не удалось назначить владельца\n");
            exit(1);
        }
        echo 'Владелец: ' . user_name($user) . ' <' . $user['email'] . ">\n";
        break;

    case 'domain':
        echo 'Адрес инсталляции: ' . \App\Core\Config::get('app.url') . "\n";
        $moved = \App\Core\DomainMove::recent();
        if ($moved) echo 'Сменён ' . $moved['hours'] . " ч назад с " . $moved['prev'] . "\n";
        break;

    case 'db':
        $db = \App\Core\DbInspect::current();
        echo ($db['version'] ?? $db['driver']) . ' · '
            . ($db['driver'] === 'sqlite' ? $db['name'] : $db['host'] . ':' . $db['port'] . '/' . $db['name'])
            . ' · префикс ' . $db['prefix'] . "\n";
        echo $db['ok'] ? ('Таблиц: ' . $db['tables'] . "\n") : ('ОШИБКА: ' . $db['error'] . "\n");
        break;

    default:
        echo "php system.php owner             — кто владелец\n";
        echo "php system.php owner:set <email> — назначить владельцем\n";
        echo "php system.php domain            — адрес инсталляции\n";
        echo "php system.php db                — подключение к базе\n";
        echo "php system.php incident <id>     — событие по номеру со страницы 500\n";
        exit($cmd === '' ? 0 : 1);
}
