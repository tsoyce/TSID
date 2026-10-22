<?php /** Возврат из Telegram (v4.87): данные входа во фрагменте адреса, в запрос обработчика их переносит app.js. */ ?>
<div data-tg-return>
  <?= auth_head(t('Вход через Telegram'), t('Проверяем ответ Telegram…'), true) ?>
  <?php /* Не вышло разобрать ответ: человек отменил вход у Telegram, или
           ссылку открыли без данных. Без скрипта — та же подсказка. */ ?>
  <p class="hint text-center" data-tg-fail hidden><?= esc(t('Telegram не вернул данные входа. Попробуйте ещё раз или выберите другой способ.')) ?></p>
  <noscript><p class="hint text-center"><?= esc(t('Telegram не вернул данные входа. Попробуйте ещё раз или выберите другой способ.')) ?></p></noscript>
</div>
<div class="auth-footer"><a href="/login"><?= esc(t('← Другие способы входа')) ?></a></div>
