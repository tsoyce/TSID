<?php /** Обёртка писем: $inner. Инлайн-стили — почтовые клиенты другого не понимают. */
$appName = \App\Core\Setting::appName();
?>
<!DOCTYPE html>
<html lang="<?= esc(lang()) ?>">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#F5F5F4;font-family:'Segoe UI',Arial,sans-serif;color:#1C1917">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F5F4;padding:24px 12px">
<tr><td align="center">
  <table role="presentation" width="520" cellpadding="0" cellspacing="0"
         style="max-width:520px;width:100%;background:#FFFFFF;border-radius:16px;border:1px solid #E7E5E4">
    <tr><td style="padding:24px 28px 8px;font-weight:600;font-size:17px">
      <span style="color:#1D4ED8">&#9679;</span> <?= esc($appName) ?>
    </td></tr>
    <tr><td style="padding:8px 28px 24px;font-size:14px;line-height:1.55">
      <?= $inner ?>
    </td></tr>
  </table>
  <?php /* Подпись настраивается: у одной установки тут адрес поддержки, у
           другой — юридическое лицо, у третьей ничего. */ ?>
  <div style="max-width:520px;padding:14px 6px;font-size:12px;color:#A8A29E;text-align:left">
    <?= esc(\App\Core\MailTemplates::footer()) ?><?= isset($securityNote) && $securityNote ? ' ' . $securityNote : '' ?>
  </div>
</td></tr>
</table>
</body>
</html>
