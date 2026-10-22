<?php /** Письмо хозяину установки: что случилось и подробности таблицей.
 *  $title, $message, $rows. Устроено как security_alert — тот же жанр, только
 *  адресат другой: не человек про свой аккаунт, а владелец про установку. */ ?>
<p><strong><?= esc($title) ?></strong></p>
<p><?= esc($message) ?></p>
<?php if (!empty($rows)): ?>
<table role="presentation" cellpadding="0" cellspacing="0"
       style="margin:18px 0;font-size:13px;color:#57534E;border-collapse:collapse">
  <?php foreach ($rows as $k => $v): ?>
  <tr>
    <td style="padding:3px 14px 3px 0;color:#A8A29E"><?= esc((string)$k) ?></td>
    <td style="padding:3px 0"><?= esc((string)$v) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
<p style="font-size:12px;color:#78716C">
  <?= esc(t('Это уведомление владельцу установки. Выключить или сменить адрес — в настройках, раздел «Уведомления».')) ?>
</p>
