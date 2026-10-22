<?php
/**
 * Приложение переживает неприменённую миграцию.
 *
 * Свойство заявлено с v3.4 и нарушалось дважды, оба раза одинаково: код
 * обновили, миграцию не применили, а запрос ссылается на новую колонку.
 *
 *  · На боевой (лог от 08.08): `Unknown column 'org_id'` на КАЖДОМ событии
 *    аудита. Падение проглатывалось, и журнал молча не вёлся вовсе — ни
 *    входов, ни правок настроек, ни выдачи ролей.
 *  · В работах по областям (v4.23–4.27): `no such column: ur.scope_type` в
 *    `rolesClaim()`. Это уже не журнал — это ВЫДАЧА ТОКЕНА, то есть вход в
 *    порталы переставал работать целиком.
 *
 * Проверка ставит схему на несколько миграций назад и гоняет по ней то, без
 * чего установка считается мёртвой: вход, claim, права, аудит. Обычная батарея
 * такого не ловит — она всегда работает на свежей схеме.
 *
 * Запуск: php tests/lagging_schema.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\DB;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;

$passed = 0;
$failed = 0;
function ok(bool $cond, string $what, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { $passed++; echo "  ✓ $what\n"; return; }
    $failed++;
    echo "  ✗ $what" . ($detail !== '' ? " — $detail" : '') . "\n";
}

/** Схема, остановленная на указанной версии (все, что строго раньше). */
function schemaUpTo(string $stopBefore, string $file): PDO
{
    @unlink($file);
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    DB::adopt($pdo, 'sqlite', 'sso_');
    foreach ((array)glob(SSO_ROOT . '/migrations/*.sql') as $f) {
        $v = basename((string)$f, '.sql');
        if ($v >= $stopBefore) continue;
        DB::runMigrationSql((string)file_get_contents((string)$f));
        App\Core\Migrations::runPhpStep($v);
        DB::insert('migrations', ['version' => $v, 'applied_at' => now()]);
    }
    return $pdo;
}

/**
 * Живая установка на отставшей схеме: человек, портал, роль.
 * Роли засевает установщик, а не миграции, — здесь заводим руками.
 */
function fixture(): array
{
    $uid = User::create(['email' => 'lag@test.local', 'first_name' => 'Отставшая',
        'status' => 'active', 'email_verified_at' => now()]);
    [$cid] = Client::create([
        'name' => 'Отставший портал', 'slug' => 'lag-c', 'base_url' => 'https://lag.example',
        'redirect_uris' => ['https://lag.example/cb'], 'post_logout_uris' => [],
        'backchannel_uri' => null, 'allowed_scopes' => ['openid', 'profile', 'email', 'roles'],
        'is_trusted' => 1, 'access_policy' => 'open',
    ]);
    $rid = Role::create(null, 'user', 'Пользователь', null, true);
    Role::assign($uid, $rid);
    return [$uid, $cid, $rid];
}

/** Всё, без чего установка мертва. Возвращает список упавших имён. */
function vitals(int $uid, int $cid): array
{
    $bad = [];
    $probes = [
        'globalRoleSlugs' => static fn() => User::globalRoleSlugs($uid),
        'rolesClaim' => static fn() => User::rolesClaim($uid, $cid),
        'clientAdminClientIds' => static fn() => User::clientAdminClientIds($uid),
        'levelOfUser' => static fn() => Role::levelOfUser($uid, $cid),
        'permissionsOfUser' => static fn() => Role::permissionsOfUser($uid),
        'AccessLevel::forClient' => static fn() => App\Core\AccessLevel::forClient(User::find($uid), $cid),
        'IdToken::claims' => static fn() => App\Oidc\IdToken::claims(
            User::find($uid), Client::find($cid), 'openid profile email roles'),
        'Audit::log' => static fn() => App\Core\Audit::log('probe.event', ['client' => $cid]),
        'Org::forUser' => static fn() => App\Models\Org::forUser($uid),
    ];
    foreach ($probes as $name => $fn) {
        try { $fn(); } catch (\Throwable $e) { $bad[] = $name . ': ' . mb_substr($e->getMessage(), 0, 50); }
    }
    return $bad;
}

$tmp = SSO_ROOT . '/tests/tmp';
@mkdir($tmp, 0777, true);

/* Три остановки: до областей, до организации в журнале и до прав у ролей.
   Дальше вглубь не идём — там ещё не было и половины таблиц. */
foreach (['020' => 'до областей доступа', '015' => 'до организации в журнале',
          '014' => 'до таблицы прав'] as $stop => $what) {
    echo "── Схема $what (остановлена перед $stop) ──\n";
    $file = $tmp . '/lag-' . $stop . '.sqlite';
    schemaUpTo($stop, $file);
    [$uid, $cid] = fixture();

    $bad = vitals($uid, $cid);
    ok($bad === [], "жизненно важное работает (остановка перед $stop)", implode('; ', $bad));

    /* Аудит обязан не просто не падать, а ЗАПИСАТЬСЯ: молча переставший
       вестись журнал — ровно то, что случилось на боевой. */
    $n = (int)DB::val('SELECT COUNT(*) FROM {audit_log}');
    ok($n > 0, "событие в журнал записалось, а не проглотилось (найдено $n)");

    /* Токен обязан содержать то, ради чего портал за ним и приходит. */
    $claims = App\Oidc\IdToken::claims(User::find($uid), Client::find($cid), 'openid profile email roles');
    ok(($claims['sub'] ?? '') !== '', 'в токене есть sub');
    ok(isset($claims['roles']), 'и роли — портал ветвится по ним');

    DB::release();
    @unlink($file);
}

echo "\n" . ($failed === 0 ? "ОТСТАВШАЯ СХЕМА ПЕРЕЖИТА: $passed проверок\n" : "ПРОВАЛЕНО: $failed\n");
exit($failed === 0 ? 0 : 1);
