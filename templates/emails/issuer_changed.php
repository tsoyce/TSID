<p><?= esc(t('Здравствуйте')) ?>!</p>
<p><?= t_html('Адрес системы входа изменился. Портал :portal перестанет принимать вход, пока в его настройках указан прежний адрес.', ['portal' => '<strong>' . esc($portal) . '</strong>']) ?></p>
<p><?= esc(t('Было:')) ?> <span style="font-family:monospace"><?= esc($old_issuer) ?></span><br>
<?= esc(t('Стало:')) ?> <span style="font-family:monospace"><?= esc($new_issuer) ?></span></p>
<p><?= t_html('Что поправить: в конфигурации SSO-модуля портала (обычно :file) замените значение :issuer на новое. Остальные реквизиты — :client_id, :client_secret и адреса возврата — не меняются.', ['file' => '<code>/sso/config.php</code>', 'issuer' => '<code>issuer</code>', 'client_id' => '<code>client_id</code>', 'client_secret' => '<code>client_secret</code>']) ?></p>
<p style="font-size:12px;color:#78716C"><?= esc(t('Письмо отправлено администраторам портала автоматически. Ключи подписи прежние, перевыпускать ничего не нужно.')) ?></p>
