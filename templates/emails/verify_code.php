<?php /** Письмо «verify_code» (v4.72, v4.74): код и кнопка — кому как удобнее.
         Текст настраивается (App\Core\MailTemplates), устройство — нет. */
$L = App\Core\MailTemplates::render('verify_code', ['name' => $name ?? '', 'code' => $code ?? '']);
$link = (string)($url ?? '');
?>
<p><?= esc($L['greeting']) ?></p>
<p><?= esc($L['body']) ?></p>
<p style="font-size:28px;font-weight:600;letter-spacing:6px;margin:16px 0"><?= esc($code ?? '') ?></p>
<?php if ($link !== ''): ?>
<?= mail_button($link, $L['button']) ?>
<?php endif; ?>
<p style="font-size:12px;color:#78716C">
  <?php if ($L['expires'] !== ''): ?><?= esc(t('Код действует :for.', ['for' => $L['expires']])) ?><br><?php endif; ?>
  <?php if ($link !== ''): ?><?= esc(t('Или откройте ссылку:')) ?> <a href="<?= esc($link) ?>"><?= esc($link) ?></a><br><?php endif; ?>
  <?= esc(t('Если вы нигде не регистрировались — просто не вводите код, аккаунт без него не заработает.')) ?>
</p>