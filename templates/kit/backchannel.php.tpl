<?php
/**
 * Приёмник событий SSO ({{BACKCHANNEL_URI}}, §5 integration.md).
 * POST event_token=JWT. Проверяем как id_token + claim events. Отвечаем 200 быстро.
 */
require __DIR__ . '/SsoClient.php';
$sso = new SsoClient(require __DIR__ . '/config.php');

$token = (string)($_POST['event_token'] ?? ($_POST['logout_token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('event_token required');
}

try {
    $claims = $sso->verifyJwt($token);
} catch (Throwable $e) {
    http_response_code(400);
    exit('bad token');
}

$events = (array)($claims['events'] ?? []);
$sub = (string)($claims['sub'] ?? '');
$sid = (string)($claims['sid'] ?? '');

/* Обработчики портала. Файл может отсутствовать — тогда всё уйдёт в журнал
   событий, и это будет видно в самопроверке. */
if (is_file(__DIR__ . '/hooks.php')) require_once __DIR__ . '/hooks.php';

/**
 * Событие, которое портал не обработал, кладём на диск.
 *
 * Раньше здесь стоял `// TODO` — то есть событие приходило, подпись
 * проверялась, и дальше не происходило НИЧЕГО. Со стороны SSO всё выглядело
 * доставленным: 200 получен, галочка стоит. А в портале продолжал жить аккаунт
 * человека, которого в SSO уже нет. Потерянное событие хуже недоставленного:
 * недоставленное видно, потерянное — нет.
 */
$sso_record = static function (string $event, string $sub, array $claims): void {
    $dir = __DIR__ . '/var';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/sso-events.ndjson',
        json_encode(['at' => gmdate('c'), 'event' => $event, 'sub' => $sub,
                     'claims' => $claims], JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX);
    error_log('SSO: событие ' . $event . ' для ' . $sub . ' не обработано — см. sso/var/sso-events.ndjson');
};

foreach (array_keys($events) as $event) {
    $handled = false;
    /* Подробности события лежат под его именем: у user.deleted это mode и
       restore_until (integration.md §5). Отдаём их обработчику поверх claims,
       чтобы $payload['mode'] читался без раскопок. */
    $payload = (array)($events[$event] ?? []) + $claims;
    switch ($event) {
        case 'logout':
            /* Выход — единственное, что мост умеет сам: сессия наша, и её
               идентификатор приехал в токене. */
            if ($sid !== '') $sso->killSessionBySid($sid);
            $handled = true;
            break;

        case 'http://schemas.openid.net/event/backchannel-logout':
            /* То же событие выхода под стандартным именем (OIDC Back-Channel
               Logout). {{APP_NAME}} кладёт его рядом с `logout`, пока портал не
               опубликовал манифест: работу сделала ветка `logout`, здесь только
               не записываем ключ в необработанные. */
            $handled = true;
            break;

        case 'user.blocked':
        case 'user.deleted':
            /* Событие про человека целиком приходит без sid — гасим все его
               сессии по sub. Раньше мост гасил только по sid, которого здесь
               нет, и без дописанного хука не закрывалась ни одна (v4.62). */
            $sso->killSessionsBySub($sub);
            if ($sid !== '') $sso->killSessionBySid($sid);
            if (function_exists('sso_on_user_gone')) {
                $handled = (bool)sso_on_user_gone($sub, $event, $payload);
            }
            break;

        case 'user.restored':
            if (function_exists('sso_on_user_restored')) {
                $handled = (bool)sso_on_user_restored($sub, $payload);
            }
            break;

        case 'user.updated':
            if (function_exists('sso_on_user_updated')) {
                $handled = (bool)sso_on_user_updated($sub, $payload);
            }
            break;

        case 'access.revoked':
            $sso->killSessionsBySub($sub);
            if ($sid !== '') $sso->killSessionBySid($sid);
            if (function_exists('sso_on_access_revoked')) {
                $handled = (bool)sso_on_access_revoked($sub, $payload);
            }
            break;

        case 'access.granted':
            if (function_exists('sso_on_access_granted')) {
                $handled = (bool)sso_on_access_granted($sub, $payload);
            }
            break;

        default:
            break;
    }
    if (!$handled) $sso_record($event, $sub, $claims);
}

/* 200 отдаём в любом случае: повторная доставка того же события ничего не
   исправит, если портал его не умеет, а очередь SSO забьётся попытками. Факт
   необработанности лежит в файле и виден самопроверке. */
http_response_code(200);
echo 'ok';
