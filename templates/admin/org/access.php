<?php /**
 * Доступы: чужие порталы, куда пускают участников организации.
 *
 * Отдельно от «Сервисов»: там владение, здесь допуск. Разница видна на
 * последствиях — выключение организации гасит именно эти доступы, а её
 * собственные сервисы остаются на месте.
 */ ?>
<div class="bento-card">
  <div class="bc-title"><?= esc(t('Выданные доступы')) ?></div>
  <p class="muted"><?= esc(t('Их получают все участники — сразу, без заявок.')) ?></p>
  <?php if ($clients === []): ?>
    <?= empty_state('portal', t('Доступов не выдано.'), '', 'pad-16') ?>
  <?php else: ?>
  <div class="stack mb-16">
    <?php foreach ($clients as $c): ?>
    <div class="row-between gap-8">
      <div class="flex-1">
        <div class="cell-main"><?= esc($c['name']) ?></div>
        <?php if ($c['role_slug']): ?>
          <div class="hint"><?= esc(t('роль при входе:')) ?> <span class="mono"><?= esc($c['role_slug']) ?></span></div>
        <?php endif; ?>
      </div>
      <form method="post" action="/admin/orgs/<?= (int)$org['id'] ?>/action">
        <?= csrf_field() ?><input type="hidden" name="action" value="revoke_client">
        <input type="hidden" name="client_id" value="<?= (int)$c['client_id'] ?>">
        <button class="btn btn-s btn-danger-ghost" type="submit"><?= esc(t('Отозвать')) ?></button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="bc-title mt-24"><?= esc(t('Выдать доступ')) ?></div>
  <form method="post" action="/admin/orgs/<?= (int)$org['id'] ?>/action">
    <?= csrf_field() ?><input type="hidden" name="action" value="grant_client">
    <?php
    $clientOptions = [];
    foreach ($allClients as $c) $clientOptions[(int)$c['id']] = $c['name'];
    $roleOptions = ['0' => '—'];
    foreach ($allRoles as $r) $roleOptions[(int)$r['id']] = $r['name'];
    ?>
    <div class="grid-2">
      <?= field_select('client_id', t('Портал'), $clientOptions, '', ['id' => 'f-org-client']) ?>
      <?= field_select('role_id', t('Роль при входе'), $roleOptions, '', [
            'id' => 'f-org-role', 'note' => t('(необязательно)')]) ?>
    </div>
    <?= form_actions(t('Выдать доступ')) ?>
  </form>
</div>
