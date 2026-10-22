<?php
/** Локальный выход + передача на SSO (там пользователь решит: здесь или везде). */
require __DIR__ . '/bootstrap.php';
sso_session_start();
$cfg = sso_config();

/* id_token_hint снимаем ДО того, как погасим сессию: по нему {{APP_NAME}}
   понимает, кого провожает, и может предложить «выйти везде». Без подсказки
   выход выходит безымянным — экран спросит заново, кто вы. */
$hint = (string)($_SESSION['sso_user']['id_token'] ?? '');

unset($_SESSION['sso_user']);
session_regenerate_id(true);

header('Location: ' . $cfg['issuer'] . '/logout?' . http_build_query(array_filter([
    'id_token_hint' => $hint ?: null,
    'post_logout_redirect_uri' => $cfg['post_logout'],
])));
