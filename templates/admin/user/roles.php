<?php /**
 * Роли человека — с областью, в которой каждая действует.
 *
 * «Роль есть» само по себе ничего не говорит: одна и та же роль «Админ портала»
 * означает разное в зависимости от того, выдана она на систему, организацию или
 * один сервис (SCOPES §2). Раньше область не показывалась вовсе.
 *
 * v4.92: название впереди, слаг и уровень — строкой под ним (впереди стояло
 * машинное «client_admin», а уровень дублировал название отдельной колонкой);
 * область — ссылкой туда, где роль действует; выдача на удалённый сервис
 * названа прямо, а не «сервис #904». «Отозвать» снимает роль только в этой
 * строке: форма шлёт область.
 */
$scopeOf = static function (array $g): array {
    $type = (string)($g['scope_type'] ?? 'system');
    $id = (int)($g['scope_id'] ?? 0);
    if ($type === App\Core\Scope::SYSTEM || $id === 0) return ['label' => t('вся система'), 'href' => null, 'gone' => false];
    if ($type === App\Core\Scope::ORG) {
        $o = App\Models\Org::find($id);
        return $o === null
            ? ['label' => t('удалённая организация #:id', ['id' => $id]), 'href' => null, 'gone' => true]
            : ['label' => t('организация :name', ['name' => $o['name']]), 'href' => '/admin/orgs/' . $id, 'gone' => false];
    }
    $c = App\Models\Client::find($id);
    return $c === null
        ? ['label' => t('удалённый сервис #:id', ['id' => $id]), 'href' => null, 'gone' => true]
        : ['label' => t('сервис :name', ['name' => $c['name']]), 'href' => '/admin/clients/' . $id, 'gone' => false];
};
$gone = 0;
?>
<div class="bento-card">
  <div class="bc-title"><?= esc(t('Что выдано')) ?></div>
  <?php if ($grants === []): ?>
    <?= empty_state('role', t('Ролей нет — только базовая «Пользователь».'), '', 'pad-16') ?>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table table-cards">
      <thead><tr>
        <th><?= esc(t('Роль')) ?></th><th><?= esc(t('Где действует')) ?></th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($grants as $g): $s = $scopeOf($g); if ($s['gone']) $gone++; ?>
      <tr>
        <td class="cell-primary">
          <span class="cell-main"><?= esc(($g['name'] ?? '') !== '' ? $g['name'] : $g['slug']) ?></span>
          <div class="hint"><span class="mono"><?= esc($g['slug']) ?></span> · <?= esc(t('уровень :n', ['n' => (int)$g['access_level']])) ?></div>
        </td>
        <td data-label="<?= esc(t('Где действует')) ?>"><?php if ($s['href'] !== null): ?><a href="<?= esc($s['href']) ?>"><?= esc($s['label']) ?></a><?php elseif ($s['gone']): ?><span class="badge badge-warn"><?= esc($s['label']) ?></span><?php else: ?><span class="hint"><?= esc($s['label']) ?></span><?php endif; ?></td>
        <td class="text-right">
          <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/roles">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="role_id" value="<?= (int)$g['id'] ?>">
            <input type="hidden" name="scope_type" value="<?= esc((string)($g['scope_type'] ?? App\Core\Scope::SYSTEM)) ?>">
            <input type="hidden" name="scope_id" value="<?= (int)($g['scope_id'] ?? 0) ?>">
            <button class="btn btn-ghost btn-s" type="submit"><?= esc($s['gone'] ? t('Убрать') : t('Отозвать')) ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($gone > 0): ?>
  <p class="hint mt-8"><?= esc(t('Выдача на удалённую область ничего не открывает — она осталась от сервиса или организации, которых больше нет. Её можно убрать.')) ?></p>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($isGlobalAdmin): ?>
<div class="bento-card mt-16">
  <div class="bc-title"><?= esc(t('Выдать роль')) ?></div>
  <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/roles" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="assign">
    <?php $opts = ['' => t('— выберите роль')];
          foreach ($allRoles as $r) $opts[(int)$r['id']] = $r['name'] . ' (' . $r['slug'] . ')'; ?>
    <?= field_select('role_id', t('Роль'), $opts, '', ['id' => 'f-grant-role']) ?>
    <?= field_submit(t('Выдать')) ?>
  </form>
  <p class="hint mt-8"><?= esc(t('Роль выдаётся в своей области: глобальная — на всю систему, роль сервиса — в этом сервисе. Роль в организации выдают в её разделе «Пользователи».')) ?></p>
</div>
<?php endif; ?>
