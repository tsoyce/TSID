<?php
/**
 * Приём authorization code: проверки → обмен → локальная сессия.
 *
 * !!! МАТЧИНГ ЛОКАЛЬНОГО АККАУНТА — ТОЛЬКО ПО sub. НИКОГДА ПО EMAIL !!!
 * (email меняется, sub вечен — это ошибка №1 самодельных интеграций)
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/SsoClient.php';
sso_session_start();
$sso = new SsoClient(sso_config());

/**
 * Экран «не получилось» — с выходом, а не с тупиком.
 *
 * Самый частый случай здесь не поломка, а F5 или «назад»: `state` одноразовый,
 * при первом же обмене он стирается из сессии, и повторный заход по той же
 * ссылке честно не сходится. Человек при этом ничего не сломал, а прежний
 * ответ — голая строка «state mismatch» — выглядел как авария и никуда не вёл.
 *
 * Технический текст оставляем, но мелким: он нужен тому, кто разбирается.
 */
$ssoFail = static function (string $why, string $detail = '', bool $retry = true): never {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">',
         '<meta name="viewport" content="width=device-width, initial-scale=1">',
         '<title>Вход не завершён</title>',
         '<link rel="stylesheet" href="/sso/sso-button.css">',
         '<style>body{font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;',
         'max-width:520px;margin:15vh auto;padding:0 20px;color:#1C1917}',
         'h1{font-size:20px;margin:0 0 8px}p{margin:0 0 16px;color:#57534E}',
         'code{font-size:12px;color:#A8A29E}</style></head><body>',
         '<h1>', $e($why), '</h1>';
    if ($retry) {
        echo '<p>Ничего не сломалось — так бывает, если обновить страницу или ',
             'вернуться назад: ссылка возврата одноразовая.</p>',
             '<p><a class="sso-btn" href="/sso/login.php" data-turbo="false" hx-boost="false">',
             '<span class="sso-btn-mark">{{MARK}}</span> Войти заново</a></p>';
    } else {
        echo '<p><a class="sso-btn sso-btn-outline" href="/">На главную</a></p>';
    }
    if ($detail !== '') echo '<p><code>', $e($detail), '</code></p>';
    echo '</body></html>';
    exit;
};

if (isset($_GET['error'])) {
    /* Отказ пришёл от {{APP_NAME}}: человек закрыл экран согласия, доступ не
       выдан, портал выключен. Повторять вход имеет смысл не всегда, но и
       тупика тут быть не должно. */
    $ssoFail('Вход не состоялся',
        (string)$_GET['error'] . ' — ' . (string)($_GET['error_description'] ?? ''),
        ((string)$_GET['error']) === 'access_denied' ? false : true);
}

/* Ответ пришёл от того, к кому мы уходили (RFC 9207): {{APP_NAME}} кладёт
   свой адрес в iss. Чужой iss — это ответ другого провайдера, подсунутый в
   наш возврат; код от него нельзя нести в наш /token. */
$gotIss = (string)($_GET['iss'] ?? '');
if ($gotIss !== '' && $gotIss !== (string)(sso_config()['issuer'] ?? '')) {
    $ssoFail('Вход не завершён', 'iss в ответе не совпал с издателем');
}

try {
    $claims = $sso->handleCallback((string)($_GET['code'] ?? ''), (string)($_GET['state'] ?? ''));
} catch (Throwable $e) {
    $stale = str_contains($e->getMessage(), 'state') || str_contains($e->getMessage(), 'nonce');
    $ssoFail($stale ? 'Эта ссылка уже использована' : 'Вход не завершён', $e->getMessage());
}

/* ------------------------------------------------------------------
 * TODO для интеграции с вашей БД (см. integration.md, §3 шаг 4).
 *
 * Лестница из четырёх ступеней, сверху вниз, первое совпадение выигрывает:
 *
 *   // 1. По sub. Он вечен — с него и начинаем.
 *   $user = findUserBySub($claims['sub']);
 *
 *   // 2. По ПОДТВЕРЖДЁННОЙ почте: только email_verified === true, только если
 *   //    совпадение ровно одно и sub к той записи ещё не привязан.
 *   if (!$user && ($claims['email_verified'] ?? false)) {
 *       $legacy = findUnlinkedUserByEmail($claims['email']);
 *       if ($legacy) { linkSub($legacy, $claims['sub']); $user = $legacy; }
 *   }
 *
 *   // 3. По логину — ТОЛЬКО когда почты нет ни с одной стороны.
 *   //    Есть почта хоть у кого-то из двоих — не сопоставлять: «ivanov» у вас
 *   //    и «ivanov» в SSO это, скорее всего, разные люди, а склейка необратима.
 *   if (!$user && !isset($claims['email'])) {
 *       $legacy = findUnlinkedUserByLoginWithoutEmail($claims['preferred_username'] ?? '');
 *       if ($legacy) { linkSub($legacy, $claims['sub']); $user = $legacy; }
 *   }
 *
 *   // 3.5. ВЛАДЕЛЕЦ УСТАНОВКИ — особый случай, и он важнее остальных правил.
 *   //      Установщик записал, КТО ставил мост: sub, логин и почту. Это не
 *   //      догадка по совпадению, а имя, названное самим SSO. Если пришёл он,
 *   //      а локальная запись с таким логином у вас уже есть — это она и есть.
 *   //      Без этого шага владелец с логином «ivan» на обеих сторонах получает
 *   //      второй аккаунт «ivan_2» и смотрит на свой портал глазами гостя.
 *   $sec = sso_secrets();
 *   if (!$user && ($sec['owner_sub'] ?? '') !== ''
 *       && hash_equals($sec['owner_sub'], (string)$claims['sub'])) {
 *       $legacy = findUserByLogin($sec['owner_login'] ?? '')
 *           ?: findUserByEmail($sec['owner_email'] ?? '');
 *       if ($legacy) { linkSub($legacy, $claims['sub']); $user = $legacy; }
 *   }
 *
 *   // 4. Не нашли — заводим.
 *   if (!$user) $user = createUser($claims['sub'], $claims['email'] ?? null, $claims['name'] ?? '');
 *
 * ВНИМАНИЕ: $claims['email'] может отсутствовать — у человека почты нет вовсе
 * (перенесён из сервиса, где регистрация шла логином и паролём). Колонка email
 * у вас обязана быть NULL-able, а UNIQUE по ней — допускать NULL: иначе второй
 * такой человек не заведётся. Имя берите из name → preferred_username.
 *   если запрашивали offline_access — сохраните $claims['_tokens']['refresh_token'] в БД.
 *
 * ПРАВА (integration.md §4.1). roles и access_level приезжают только со scope
 * `roles`. Заведена карта ролей — в roles ваши собственные названия; не
 * заведена — слаги SSO, глобальные без префикса и ваши с префиксом
 * «{{CLIENT_SLUG}}:». Пустой roles — это НЕ «нет прав»: заполненная карта
 * отдаёт только то, что в ней есть.
 *
 *   $level = (int)($claims['access_level'] ?? 0);   // 70+ админ, 55+ модератор
 *
 * Человека, которому у вас нечего делать (level 20), всё равно заводим и
 * пускаем на публичную часть, а в админку отдаём 403. Решение «пускать ли
 * вообще» принимает SSO политикой доступа портала (open / approval / invite) —
 * раз он доехал сюда, его уже пустили. Подробно — integration.md §4.2.
 * ------------------------------------------------------------------ */

session_regenerate_id(true);
$_SESSION['sso_user'] = [
    'sub' => $claims['sub'],
    'name' => $claims['name'] ?? ($claims['preferred_username'] ?? ($claims['email'] ?? 'Пользователь')),
    'email' => $claims['email'] ?? null,
    'picture' => $claims['picture'] ?? null,
    'roles' => $claims['roles'] ?? [],
    'sid' => $claims['sid'] ?? '',
    /* Сырой id_token нужен ровно для одного: отдать его на выходе как
       id_token_hint. Живёт в серверной сессии и в браузер не попадает. */
    'id_token' => $claims['_tokens']['id_token'] ?? null,
    /* Уровень доступа в ЭТОМ портале — по нему экран настроек SSO (admin.php)
       узнаёт администратора. Приезжает со scope roles. */
    'access_level' => (int)($claims['access_level'] ?? 0),
];
/* Для приёмника событий: по sid гасится одна сессия, по sub — все сессии
   человека, когда его удалили, заблокировали или закрыли ему доступ. */
$sso->rememberSid((string)($claims['sid'] ?? ''), (string)($claims['sub'] ?? ''));

/* Куда вернуть: экран настроек просит вернуть на себя (login.php?back=…).
   Только путь этого же сайта — иначе это открытый редирект. */
$back = (string)($_SESSION['sso_back'] ?? '/');
unset($_SESSION['sso_back']);
if (preg_match('~^/(?!/)[^\s\\\\]*$~', $back) !== 1) $back = '/';

/* Popup-режим (§2.3): страница-заглушка сообщает родителю и закрывается */
if (isset($_SESSION['sso_display_popup']) || (($_GET['display'] ?? '') === 'popup')) {
    unset($_SESSION['sso_display_popup']);
}
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title>Вход выполнен</title></head>
<body>
<script>
if (window.opener) {
  /* Адресуем своему же origin, а не '*': открывшая страница живёт на этом
     же портале, и звёздочка означала бы «кому угодно, кто слушает». */
  try { window.opener.postMessage({ type: 'sso:done' }, location.origin); } catch (e) {}
  window.close();
} else {
  /* Косые — как есть, а <, >, кавычки и & — кодами: из строки не выйти в разметку. */
  location.href = <?= json_encode($back, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
}
</script>
<p>Вход выполнен. <a href="<?= htmlspecialchars($back, ENT_QUOTES, 'UTF-8') ?>">Перейти на сайт</a></p>
</body></html>
