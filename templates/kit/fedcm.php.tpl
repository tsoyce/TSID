<?php
/**
 * Вход без ухода со страницы (FedCM).
 *
 * Браузер сам показывает нативный диалог «Продолжить как …» и отдаёт готовый
 * id_token — редиректа на {{APP_NAME}} не происходит. Работает в Chrome и
 * производных; в остальных браузерах вызов просто не поддерживается, и
 * пользователь идёт обычной кнопкой входа.
 *
 * Подключение: положите файл рядом с login.php и вставьте на страницу входа
 *   <?php include __DIR__ . '/fedcm.php'; ?>
 * Кнопку обычного входа оставьте — она нужна как запасной путь.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/SsoClient.php';

/* config.php ОТДАЁТ массив через return и переменной не создаёт: раньше здесь
   стоял require_once с последующим $config — и файл падал на первом же
   обращении. Конфиг спрашиваем у sso_config(), как и все остальные. */
$sso = new SsoClient(sso_config());

/* nonce кладём в сессию, а не только в разметку: он затем и нужен, чтобы
   принимающая сторона могла сверить «этот токен выдан по МОЕЙ попытке входа».
   Сгенерированный и никуда не сохранённый nonce не проверяет ничего. */
sso_session_start();
$fedcmNonce = bin2hex(random_bytes(16));
$_SESSION['sso_fedcm_nonce'] = $fedcmNonce;
?>
<script>
(function () {
  /* Поддержки нет — молча уходим на обычный сценарий */
  if (!('IdentityCredential' in window)) return;

  var box = document.getElementById('fedcm-status');
  var say = function (msg) { if (box) box.textContent = msg; };

  navigator.credentials.get({
    identity: {
      providers: [{
        configURL: <?= json_encode(sso_config()['issuer'] . '/fedcm/config.json', JSON_UNESCAPED_SLASHES) ?>,
        clientId: <?= json_encode(sso_config()['client_id']) ?>,
        /* nonce связывает токен с этой попыткой входа; тот же лежит в сессии */
        nonce: <?= json_encode($fedcmNonce) ?>
      }]
    },
    /* mediation: 'optional' — не дёргать пользователя, если он не залогинен */
    mediation: 'optional'
  }).then(function (cred) {
    if (!cred || !cred.token) return;
    /* Токен проверяет сервер: подпись, iss, aud и nonce — как в обычном потоке */
    var form = document.createElement('form');
    form.method = 'post';
    form.action = 'fedcm-finish.php';
    var f = document.createElement('input');
    f.type = 'hidden'; f.name = 'token'; f.value = cred.token;
    form.appendChild(f);
    document.body.appendChild(form);
    form.submit();
  }).catch(function (e) {
    /* Пользователь закрыл диалог или FedCM отключён — это не ошибка портала */
    if (e && e.name !== 'NotAllowedError' && e.name !== 'AbortError') {
      say('Быстрый вход недоступен, войдите обычной кнопкой.');
    }
  });
})();
</script>
<div id="fedcm-status" class="hint"></div>
