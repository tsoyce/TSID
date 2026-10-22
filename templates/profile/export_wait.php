<?php /** Ссылка из письма открыта раньше, чем крон собрал архив. */ ?>
<div class="text-center">
  <div class="mb-8">
    <?= status_icon($status === 'failed' ? 'fail' : 'warn', 42) ?>
  </div>
  <?php if ($status === 'failed'): ?>
    <h2 class="fs-18"><?= esc(t('Не удалось собрать архив')) ?></h2>
    <p class="muted"><?= esc(t('Закажите выгрузку заново в настройках аккаунта. Если повторится — покажите администратору эту причину:')) ?></p>
    <p class="hint mono"><?= esc((string)$error) ?></p>
  <?php else: ?>
    <h2 class="fs-18"><?= esc(t('Архив ещё готовится')) ?></h2>
    <p class="muted"><?= esc(t('Сборка идёт в фоне и занимает до минуты. Обновите страницу чуть позже — ссылка останется рабочей сутки.')) ?></p>
    <a class="btn btn-primary mt-16" href=""><?= esc(t('Обновить страницу')) ?></a>
  <?php endif; ?>
  <div class="mt-16"><a href="/profile/settings#data"><?= esc(t('← В настройки аккаунта')) ?></a></div>
</div>
