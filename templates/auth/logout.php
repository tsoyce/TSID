<?php /** Выбор «Выйти только здесь / везде» (§2.4). $client, $post_logout, $state, $back. */ ?>
<?= auth_head(t('logout.title'), '', true) ?>
<?php if ($client): ?>
<?php /* Обе фразы — в одной строке и через пробел. На двух строках закрывающий
         тег съедал перевод строки за собой, и выходило «Вы вышли из Arc.Выйти…». */ ?>
<p class="muted text-center"><?= esc(t('Вы вышли из :portal.', ['portal' => $client['name']])) ?> <?= esc(t('Выйти и из остальных сервисов :app?', ['app' => App\Core\Setting::appName()])) ?></p>
<?php else: ?>
<p class="muted text-center"><?= esc(t('Завершить только эту сессию или выйти на всех устройствах и сервисах?')) ?></p>
<?php endif; ?>

<form method="post" action="/logout" class="stack">
  <?= csrf_field() ?>
  <input type="hidden" name="post_logout" value="<?= esc($post_logout) ?>">
  <input type="hidden" name="state" value="<?= esc($state) ?>">
  <input type="hidden" name="client_id" value="<?= esc($client['client_id'] ?? '') ?>">
  <?php /* «Выйти только здесь» с портала непонятно, где это «здесь»: человек уже
           на странице SSO. Называем портал по имени. */ ?>
  <button class="btn btn-secondary btn-block" name="mode" value="here" type="submit"><?= esc($client
      ? t('Выйти только из «:portal»', ['portal' => $client['name']]) : t('logout.here')) ?></button>
  <button class="btn btn-primary btn-block" name="mode" value="everywhere" type="submit"><?= esc($client
      ? t('Выйти из всех сервисов') : t('logout.everywhere')) ?></button>
</form>
<?php /* «Отмена» ведёт туда, откуда пришли. Раньше — всегда в профиль SSO, и
         пришедший с портала оказывался в личном кабинете, куда не собирался. */ ?>
<div class="auth-footer"><a href="<?= esc((string)($back ?? '/profile')) ?>"><?= esc($client
    ? t('Отмена — вернуться в :portal', ['portal' => $client['name']]) : t('Отмена — вернуться в профиль')) ?></a></div>
