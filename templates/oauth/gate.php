<?php /** Политика доступа (§9.1): pending / blocked / invite-denied. */ ?>
<div class="text-center">
  <div class="mb-8"><?= icon('shield', 42, 'icon-muted') ?></div>
  <?= auth_head(t($title), t($message), true) ?>
  <div class="stack">
    <a class="btn btn-secondary btn-block" href="<?= esc($client['base_url']) ?>">
      <?= esc(t('Вернуться на :name', ['name' => $client['name']])) ?></a>
    <a class="btn btn-ghost btn-block" href="/profile/services"><?= esc(t('Мои сервисы')) ?></a>
  </div>
</div>
