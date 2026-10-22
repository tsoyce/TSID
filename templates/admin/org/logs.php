<?php /**
 * Журнал организации: её события ПЛЮС события её сервисов.
 *
 * Именно «плюс»: у события организации портал не указан, и фильтр по порталам
 * срезал бы самое главное — переименования, состав, выдачи ролей.
 */ ?>
<?php if ($rows === []): ?>
  <?= empty_state('log', $projectCount === 0
      ? t('Событий нет. У организации ещё нет сервисов, а её собственные действия сюда попадут сразу.')
      : t('Событий пока нет.')) ?>
<?php else: ?>
<div class="bento-card">
  <div class="bc-title"><?= esc(t('События')) ?></div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr>
      <th><?= esc(t('Когда')) ?></th><th><?= esc(t('Событие')) ?></th>
      <th><?= esc(t('Кто')) ?></th><th><?= esc(t('Сервис')) ?></th><th><?= esc(t('Детали')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td class="hint nowrap"><?= esc(fmt_dt($r['created_at'], 'd.m.Y H:i:s')) ?></td>
      <td><span class="badge <?= str_contains((string)$r['event'], 'fail') ? 'badge-danger' : 'badge-muted' ?> mono"><?= esc($r['event']) ?></span></td>
      <td class="hint"><?= esc($r['actor_email'] ?? t('система')) ?></td>
      <td class="hint"><?= esc($r['client_name'] ?? '—') ?></td>
      <td class="hint cell-clip" title="<?= esc((string)($r['meta'] ?? '')) ?>"><?= esc((string)($r['meta'] ?? '')) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="hint mt-8"><?= esc(t('Показаны последние 200 событий. Полный журнал со всеми фильтрами — в разделе «Аудит».')) ?></p>
</div>
<?php endif; ?>
