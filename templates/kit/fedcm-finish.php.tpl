<?php
/**
 * Приём id_token от FedCM.
 *
 * Сюда приходит токен, который браузер получил из нативного диалога. Доверять
 * ему «потому что от браузера» нельзя: проверяем подпись, издателя, получателя
 * и срок — ровно так же, как в callback.php. Отличие одно: кода на обмен нет,
 * токен приходит сразу.
 *
 * !!! МАТЧИНГ ЛОКАЛЬНОГО АККАУНТА — ТОЛЬКО ПО sub. НИКОГДА ПО EMAIL !!!
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/SsoClient.php';
sso_session_start();
$sso = new SsoClient(sso_config());

$token = (string)($_POST['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    exit('Токен не передан.');
}

try {
    /* verifyJwt проверяет RS256-подпись по jwks, iss, aud и exp */
    $claims = $sso->verifyJwt($token);
} catch (Throwable $e) {
    http_response_code(400);
    exit('Токен не прошёл проверку: ' . htmlspecialchars($e->getMessage()));
}

/* nonce сверяем так же, как в обычном потоке. Без этого токен, добытый в
   другой вкладке или другой попытке входа, годится здесь как свой: подпись у
   него настоящая, издатель тот же, получатель тот же — не сходится только
   привязка к КОНКРЕТНОМУ входу, а её-то и не проверяли. */
$expectNonce = (string)($_SESSION['sso_fedcm_nonce'] ?? '');
unset($_SESSION['sso_fedcm_nonce']);
if ($expectNonce === '' || !hash_equals($expectNonce, (string)($claims['nonce'] ?? ''))) {
    http_response_code(400);
    exit('Токен не от этой попытки входа. Начните вход заново.');
}

/* ------------------------------------------------------------------
 * TODO: тот же матчинг, что и в callback.php — ищем пользователя по sub.
 * ------------------------------------------------------------------ */

session_regenerate_id(true);
$_SESSION['sso_user'] = [
    'sub' => $claims['sub'],
    'name' => $claims['name'] ?? ($claims['preferred_username'] ?? ($claims['email'] ?? 'Пользователь')),
    'email' => $claims['email'] ?? null,
    'picture' => $claims['picture'] ?? null,
    'roles' => $claims['roles'] ?? [],
    'sid' => $claims['sid'] ?? '',
];
/* sid тот же, что и при обычном входе, — backchannel-logout работает одинаково */
$sso->rememberSid((string)($claims['sid'] ?? ''));

header('Location: /');
