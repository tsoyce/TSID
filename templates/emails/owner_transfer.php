<p><?= esc(t('Здравствуйте')) ?><?= !empty($name) ? ', ' . esc($name) : '' ?>!</p>
<p><?= esc(t('Вам предлагают стать владельцем этой инсталляции. Владелец — единственный, кому доступны смена домена, управление базой данных и сброс проекта.')) ?></p>
<p style="text-align:center;margin:24px 0">
  <a href="<?= esc($url) ?>" style="background:#1D4ED8;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block"><?= esc(t('Принять владение')) ?></a>
</p>
<p style="font-size:12px;color:#78716C"><?= esc(t('Или откройте ссылку:')) ?> <a href="<?= esc($url) ?>"><?= esc($url) ?></a><br>
<?= esc(t('Ссылка действует сутки. Ничего не делать — тоже ответ: без подтверждения владелец не меняется.')) ?></p>
