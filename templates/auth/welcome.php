<?php /**
 * «Вы вошли» (v4.72): короткая остановка перед возвратом в сервис.
 * $client, $user, $continue — адрес возврата с кодом; $preview — из редактора.
 *
 * Включается порталом в разделе «Экран входа» и показывается только после
 * настоящего входа: тихий заход уже вошедшего человека проходит насквозь, как
 * раньше. Без этой остановки вход на портале выглядел как мигание: форма —
 * белый экран — снова портал, и человек не был уверен, вошёл ли.
 *
 * Переход — сам через 1,4 секунды; кнопка — тем, кто не хочет ждать, и тем,
 * у кого не работают скрипты. $origin — вход во фрейме на странице портала.
 */
$preview = $preview ?? null; ?>
<div class="connector-head mb-16">
  <?= portal_connector($client, true) ?>
</div>
<?= auth_head(t('Вы вошли'), t('Возвращаемся в :portal…', ['portal' => $client['name']]), true) ?>
<div class="welcome-who">
  <?= avatar_html($user, 48) ?>
  <div class="welcome-who-text">
    <b><?= esc(user_name($user)) ?></b>
    <?php if (!empty($user['email'])): ?><span><?= esc((string)$user['email']) ?></span><?php endif; ?>
  </div>
</div>
<div class="welcome-bar" aria-hidden="true"><span></span></div>
<?php /* Переход — тем же механизмом, что и возврат из фрейма (app.js, [data-handoff]):
         страницей — location.replace, во фрейме на странице портала — адрес
         уходит странице портала (v4.73). В предпросмотре редактора — никуда. */
$origin = $origin ?? null; ?>
<a class="btn btn-primary btn-block" href="<?= esc($continue) ?>" data-welcome-go<?=
  $preview === null ? ' data-handoff data-delay="1400"' . ($origin !== null ? ' target="_top" data-origin="' . esc($origin) . '"' : '') : '' ?>><?= esc(t('Продолжить')) ?></a>
