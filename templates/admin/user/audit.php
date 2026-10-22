  <div class="bento">
    <div class="bento-card col-7">
      <div class="bc-title"><?= esc(t('История')) ?></div>
      <?php /* Двусторонняя: и что делали с человеком, и что делал он сам.
               Портал и организация действующим лицом не бывают, поэтому
               различие живёт в ObjectHistory, а не в этом шаблоне. */ ?>
      <?= obj_history($history) ?>
    </div>
    <div class="bento-card col-5">
      <div class="bc-title"><?= esc(t('Связи')) ?></div>
      <?= rel_group(t('Роли'), array_map(static fn(array $r): array => [
          'href' => '/admin/roles/' . (int)$r['id'],
          'label' => $r['name'],
      ], $roles), t('Ролей нет')) ?>
      <?= rel_group(t('Порталы'), array_map(static fn(array $a): array => [
          'href' => '/admin/clients/' . (int)$a['client_id'],
          'label' => $a['client_name'] ?? $a['name'] ?? '#' . (int)$a['client_id'],
      ], $access), t('Доступов нет')) ?>
    </div>
  </div>

<?php /* Вкладка «Действия» — только глобальному админу. */ ?>
