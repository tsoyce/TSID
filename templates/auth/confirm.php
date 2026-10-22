<?php /**
 * Подтверждение по ссылке из письма: кнопка вместо мгновенного действия.
 * Почтовые клиенты, антивирусы и сканеры ссылок ходят по ссылкам из писем
 * сами — GET гасил бы одноразовый токен без участия человека. Кнопку они
 * не нажимают. $action, $title, $lead, $button.
 *
 * $auto — отправить форму самой (v4.70, вход по ссылке). Человеку лишний клик
 * не нужен, а большинство сканеров скриптов не выполняет: токен гаснет в
 * браузере, а не от предпросмотра ссылки.
 * Без скрипта остаётся кнопка. Необязательна: остальные экраны её не передают.
 */
$auto = !empty($auto); ?>
<?= auth_head($title, $lead, true) ?>
<form method="post" action="<?= esc($action) ?>" class="mt-16" id="confirm-form">
  <?= csrf_field() ?>
  <button class="btn btn-primary btn-block" type="submit"><?= esc($button) ?></button>
</form>
<?php if ($auto): ?>
<script nonce="<?= csp_nonce() ?>">document.getElementById('confirm-form').submit();</script>
<?php endif; ?>
