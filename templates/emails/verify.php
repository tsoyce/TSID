<?php /** Письмо «verify». Текст настраивается (App\Core\MailTemplates), устройство — нет. */
$L = App\Core\MailTemplates::render('verify', ['name' => $name ?? '']);
$link = $cancel_url ?? $url ?? '';
?>
<p><?= esc($L['greeting']) ?></p>
<p><?= esc($L['body']) ?></p>
<?= mail_button($link, $L['button']) ?>
<p style="font-size:12px;color:#78716C">
  <?= esc(t('Или откройте ссылку:')) ?> <a href="<?= esc($link) ?>"><?= esc($link) ?></a><?php if ($L['expires'] !== ''): ?><br>
  <?= esc(t('Ссылка действует :for.', ['for' => $L['expires']])) ?><?php endif; ?>
</p>
