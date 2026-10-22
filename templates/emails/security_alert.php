<p><?= esc(t('Здравствуйте')) ?><?= !empty($name) ? ', ' . esc($name) : '' ?>!</p>
<p><?= esc($message) ?></p>

<table role="presentation" cellpadding="0" cellspacing="0"
       style="margin:18px 0;font-size:13px;color:#57534E;border-collapse:collapse">
  <tr><td style="padding:3px 14px 3px 0;color:#A8A29E"><?= esc(t('Когда')) ?></td><td style="padding:3px 0"><?= esc($when) ?></td></tr>
  <tr><td style="padding:3px 14px 3px 0;color:#A8A29E"><?= esc(t('Устройство')) ?></td><td style="padding:3px 0"><?= esc($device) ?></td></tr>
  <tr><td style="padding:3px 14px 3px 0;color:#A8A29E">IP</td><td style="padding:3px 0"><?= esc($ip) ?></td></tr>
</table>

<p style="text-align:center;margin:24px 0">
  <a href="<?= esc($action_url) ?>" style="background:#1D4ED8;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block"><?= esc($action_label) ?></a>
</p>

<p style="font-size:12px;color:#78716C"><?= esc(t('Не узнаёте это действие? Смените пароль и завершите все сессии в разделе «Безопасность». Отключить такие письма можно в настройках аккаунта.')) ?></p>
