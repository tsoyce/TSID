<p><?= esc(t('Здравствуйте')) ?><?= !empty($name) ? ', ' . esc($name) : '' ?>!</p>
<p><?= esc(t('Проект сброшен. Данные, порталы и настройки удалены, ключи подписи выпущены заново. Ваш аккаунт заведён заново и остаётся владельцем инсталляции — задайте пароль:')) ?></p>
<p style="text-align:center;margin:24px 0">
  <a href="<?= esc($url) ?>" style="background:#1D4ED8;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block"><?= esc(t('Задать пароль')) ?></a>
</p>
<p style="font-size:12px;color:#78716C"><?= esc(t('Ссылка действует 7 дней. Порталы придётся подключить заново: их прежние client_id и client_secret больше не существуют.')) ?></p>
