<p><?= esc(t('Здравствуйте')) ?><?= !empty($name) ? ', ' . esc($name) : '' ?>!</p>
<p><?= t_html('Для вашей учётной записи запрошена смена почты на :email.', ['email' => '<strong>' . esc($new_email) . '</strong>']) ?></p>
<p><strong><?= esc(t('Это были не вы?')) ?></strong> <?= esc(t('Нажмите кнопку — смена отменится, все сессии будут закрыты:')) ?></p>
<p style="text-align:center;margin:24px 0">
  <a href="<?= esc($revert_url) ?>" style="background:#B91C1C;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block"><?= esc(t('Это не я — отменить')) ?></a>
</p>
<p style="font-size:12px;color:#78716C"><?= esc(t('Кнопка действует 7 дней. Если смену запросили вы — ничего делать не нужно.')) ?></p>
