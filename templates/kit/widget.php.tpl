<?php
/**
 * Виджет входа: include в шаблон сайта.
 * Не вошёл → кнопка «Войти через {{APP_NAME}}» (popup на десктопе, редирект на мобильном).
 * Вошёл → аватар + имя + меню.
 *
 * Ни одного inline-обработчика и ни одного атрибута style: на сайте со строгой
 * политикой безопасности (а §7 спецификации сама к ней толкает) `onclick=` не
 * выполняется, и меню просто не открывается — молча, без ошибки в консоли.
 * Оформление живёт в sso-button.css, поведение — в одном обработчике внизу.
 *
 * Директивы, которые портал обязан разрешить (подставьте свой адрес):
 *   script-src  {{ISSUER}}     — sso.js для входа без ухода со страницы
 *   img-src     {{ISSUER}}     — аватары
 *   connect-src {{ISSUER}}     — проверка сессии и настройка окна входа
 *   frame-src   {{ISSUER}}     — вход окном поверх сайта (экран входа во фрейме)
 * Свой единственный <script> ниже помечайте nonce-ом сайта, если он у вас есть.
 */
require_once __DIR__ . '/bootstrap.php';
sso_session_start();
$ssoUser = $_SESSION['sso_user'] ?? null;

/* Кнопку показываем по тому же условию, по которому вход работает. Иначе
   выходит худший из вариантов: кнопка на странице есть, а нажатие отвечает
   «единый вход ещё не настроен» — потому что «показывать ли» и «настроен ли»
   спрашивали разные куски кода. */
if (!$ssoUser && !sso_ready()) return;
?>
<link rel="stylesheet" href="/sso/sso-button.css">
<?php if (!$ssoUser): ?>
<script src="{{ISSUER}}/assets/js/sso.js" data-client-id="{{CLIENT_ID}}" data-start-url="/sso/login.php" defer></script>
<button class="sso-btn" type="button" data-sso-login>
  <span class="sso-btn-mark">{{MARK}}</span> Войти через {{APP_NAME}}
</button>
<?php else: ?>
<div class="sso-widget" data-sso-widget>
  <button class="sso-widget-btn" type="button" data-sso-menu aria-expanded="false" aria-haspopup="true">
    <?php if (!empty($ssoUser['picture'])): ?>
      <img class="sso-widget-pic" src="<?= htmlspecialchars($ssoUser['picture']) ?>" alt="">
    <?php else: ?>
      <span class="sso-widget-pic sso-widget-initial"><?=
        htmlspecialchars(mb_strtoupper(mb_substr((string)$ssoUser['name'], 0, 1))) ?></span>
    <?php endif; ?>
    <span><?= htmlspecialchars((string)$ssoUser['name']) ?></span>
  </button>
  <div class="sso-widget-menu" hidden>
    <a href="{{ISSUER}}/profile">Профиль {{APP_NAME}}</a>
    <a class="sso-widget-danger" href="/sso/logout.php">Выйти</a>
  </div>
</div>
<script src="/sso/sso-widget.js" defer></script>
<?php endif; ?>
