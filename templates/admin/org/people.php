<?php /**
 * Люди организации — одним списком (v4.69).
 *
 * Участники и люди её сервисов стояли двумя таблицами, и роль на организацию
 * нельзя было выдать человеку из сервиса — сперва его надо было добавить в
 * состав другой формой. А в строке участника «Полномочие» и «Роли в
 * организации» показывали одно и то же дважды: полномочие владельца и
 * администратора — это и есть роли org_owner и org_admin (Org::roleOf читает
 * его оттуда же). Замечание владельца 13.09.
 *
 * Выдать роль можно любому из списка: человек из сервиса при этом становится
 * участником. «Убрать» — только у участника: доступ к сервису снимают в самом
 * сервисе.
 */
$statusMap = ['pending' => ['badge-warn', t('ждёт почту')], 'blocked' => ['badge-danger', t('заблокирован')]];
?>
<div class="bento-card">
  <div class="bc-title"><?= esc(t('Люди организации')) ?></div>
  <p class="muted"><?= esc(t('Участники и те, кто ходит в её сервисы. Роль на организацию действует во всех её сервисах, включая будущие; выдать её можно любому из списка — человек из сервиса при этом станет участником.')) ?></p>

  <?php if ($people === []): ?>
    <?= empty_state('users', t('В организации пока никого нет.'), '', 'pad-16') ?>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table" id="org-people">
      <thead><tr>
        <th></th><th><?= esc(t('Человек')) ?></th>
        <th><?= esc(t('В составе')) ?></th>
        <th><?= esc(t('Роли в организации')) ?></th>
        <th><?= esc(t('Сервисов')) ?></th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($people as $p): $uid = (int)$p['user_id']; ?>
      <tr>
        <td><?= avatar_html($p, 32) ?></td>
        <td>
          <a href="/admin/users/<?= $uid ?>"><?= esc(user_name($p)) ?></a>
          <div class="hint"><?= esc(user_sub($p)) ?></div>
          <?php if (isset($statusMap[$p['status'] ?? ''])): [$cls, $label] = $statusMap[$p['status']]; ?>
          <span class="badge <?= $cls ?>"><?= esc($label) ?></span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($p['is_member']): ?>
          <span class="badge badge-ok"><?= esc(t('участник')) ?></span>
          <?php elseif ($mayRoles): ?>
          <form method="post" action="/admin/orgs/<?= (int)$org['id'] ?>/action">
            <?= csrf_field() ?><input type="hidden" name="action" value="member_add">
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <button class="btn btn-secondary btn-s" type="submit"><?= esc(t('В состав')) ?></button>
          </form>
          <?php else: ?>
          <span class="hint"><?= esc(t('ходит в сервис')) ?></span>
          <?php endif; ?>
        </td>
        <td><?= role_cell($uid, user_name($p), $scope,
              '/admin/orgs/' . (int)$org['id'] . '/action',
              $rolesByUser[$uid] ?? [], $grantable, $mayRoles) ?></td>
        <td class="hint"><?= (int)($p['services'] ?? 0) ?></td>
        <td>
          <?php if ($p['is_member'] && $mayRoles): ?>
          <form method="post" action="/admin/orgs/<?= (int)$org['id'] ?>/action"
                data-confirm="<?= esc(t('Убрать из организации? Доступы, выданные через неё, пропадут.')) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="member_remove">
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <button class="btn btn-ghost btn-s" type="submit"><?= esc(t('Убрать')) ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($mayRoles && $people !== []): ?>
  <p class="hint mt-8"><?= esc($offerWhy) ?></p>
  <?php endif; ?>

  <?php if ($mayRoles): ?>
  <form method="post" action="/admin/orgs/<?= (int)$org['id'] ?>/action" class="row mt-16">
    <?= csrf_field() ?><input type="hidden" name="action" value="member_add">
    <?= field_text('email', t('Добавить участника'), '', [
          'id' => 'f-add-email', 'placeholder' => t('почта или логин'), 'required' => true]) ?>
    <?= field_select('role', t('Роль в организации'), $roles, 'member', ['id' => 'f-add-role']) ?>
    <?= field_submit(t('Добавить')) ?>
  </form>
  <?php endif; ?>
</div>
