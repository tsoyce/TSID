  <div class="bento">
    <div class="bento-card col-7">
      <div class="bc-title"><?= esc(t('История')) ?></div>
      <?= obj_history($history) ?>
    </div>
    <?php /* Связи одним компонентом: из карточки должно быть видно, что
             сломается при удалении. Доступ, розданный организацией целиком,
             раньше отсюда виден не был вовсе. */ ?>
    <div class="bento-card col-5">
      <div class="bc-title"><?= esc(t('Связи')) ?></div>
      <?= rel_group(t('Организации'), array_map(static fn(array $o): array => [
          'href' => '/admin/orgs/' . (int)$o['id'],
          'label' => $o['name'],
      ], $orgs), t('Доступ организациям не выдан')) ?>
      <?= rel_group(t('Роли портала'), array_map(static fn(array $r): array => [
          'href' => '/admin/roles/' . (int)$r['id'],
          'label' => $r['name'],
      ], $roles), t('Своих ролей нет')) ?>
    </div>
  </div>
