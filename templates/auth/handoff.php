<?php /**
 * Возврат в портал из входа во фрейме (v4.73). $client, $continue, $origin, $title.
 *
 * Фрейм на странице портала не уводим на его колбэк: адрес возврата с кодом
 * отдаётся странице портала (postMessage только на origin её адреса
 * возврата), и она переходит по нему сама — колбэк работает как при входе
 * страницей. Не дошло сообщение — кнопка ведёт по тому же адресу всю вкладку.
 */ ?>
<div class="connector-head mb-16">
  <?= portal_connector($client, true) ?>
</div>
<?= auth_head((string)($title ?? t('Вы вошли')), t('Возвращаемся в :portal…', ['portal' => $client['name']]), true) ?>
<a class="btn btn-primary btn-block" href="<?= esc($continue) ?>" target="_top"
   data-handoff data-origin="<?= esc($origin) ?>" data-delay="0"><?= esc(t('Продолжить')) ?></a>
