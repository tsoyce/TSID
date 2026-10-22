<?php /** Вход через Telegram: виджет отдаёт данные прямо на /social/telegram/callback. */ ?>
<?= auth_head(t('Вход через Telegram'), t('Нажмите кнопку ниже — Telegram подтвердит вашу личность и вернёт вас сюда. Пароль не потребуется.'), true) ?>
<div class="row row-center mt-16 mb-16">
  <?= $widget ?>
</div>
<div class="auth-footer"><a href="/login"><?= esc(t('← Другие способы входа')) ?></a></div>
