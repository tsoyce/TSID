<?php /**
 * Опасная зона организации. $org, $unlocked, $membersCount, $clientsCount.
 * Механизм общий с порталами — App\Core\DangerZone.
 *
 * В оболочке карточки (v4.83): колонка разделов остаётся на месте, как у
 * остальных разделов, — раньше страница открывалась без неё.
 */ ?>
<div class="bento">
<div class="bento-card col-12 bordered-danger" id="danger">
  <div class="bc-title"><?= esc(t('Удаление организации')) ?></div>
  <p class="hint"><?= esc(t('Уйдут состав и выданные организации доступы к порталам. Люди и сами порталы останутся — исчезнет только групповая связь между ними. Персональные доступы, выданные отдельно, не затрагиваются.')) ?></p>
  <div class="stack mt-16 mb-16">
    <div class="row-between"><span><?= esc(t('Участников')) ?></span><span><?= (int)$membersCount ?></span></div>
    <div class="row-between"><span><?= esc(t('Порталов с доступом через организацию')) ?></span><span><?= (int)$clientsCount ?></span></div>
  </div>

  <?php if (!$unlocked): ?>
  <?= danger_gate('/admin/orgs/' . (int)$org['id'] . '/danger') ?>
  <?php else: ?>
  <form method="post" action="/admin/orgs/<?= (int)$org['id'] ?>/action"
        data-confirm="<?= esc(t('Удалить организацию? Это необратимо.')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <?= field_confirm_name(t('Наберите слаг организации — :slug — чтобы подтвердить.', ['slug' => $org['slug']]), $org['slug']) ?>
    <button class="btn btn-danger" type="submit"><?= esc(t('Удалить организацию')) ?></button>
  </form>
  <?php endif; ?>
</div>
</div>
