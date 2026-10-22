<?php /** Аудит (§5.2): фильтры + CSV. */ ?>
<div class="page-head">
  <h1><?= esc(t('Аудит')) ?></h1>
  <a data-download class="btn btn-secondary" href="/admin/logs/export.csv?<?= esc(http_build_query($_GET)) ?>"><?= esc(t('Экспорт CSV')) ?></a>
</div>

<?= logs_tabs('audit') ?>

<form method="get" action="/admin/logs" class="filter-bar">
  <?php
  $clientOptions = ['' => t('— любой')];
  foreach ($clients as $c) $clientOptions[(int)$c['id']] = $c['name'];
  ?>
  <?php $eventOptions = ['' => t('— любые')];
        foreach (App\Core\Audit::GROUPS as $prefix => $label) $eventOptions[$prefix] = t($label);
        /* Точное имя события из ссылки не теряем — оно остаётся выбранным. */
        $eventNow = (string)($_GET['event'] ?? '');
        if ($eventNow !== '' && !isset($eventOptions[$eventNow])) $eventOptions[$eventNow] = App\Core\Audit::title($eventNow); ?>
  <?= field_select('event', t('События'), $eventOptions, $eventNow) ?>
  <?= field_text('user', t('Пользователь'), $_GET['user'] ?? '', ['placeholder' => 'email']) ?>
  <?= field_select('client', t('Портал'), $clientOptions, (string)($_GET['client'] ?? '')) ?>
  <?= field_text('ip', 'IP', $_GET['ip'] ?? '', ['mono' => true, 'class' => 'field-narrow']) ?>
  <?= field_text('from', t('С даты'), $_GET['from'] ?? '', ['type' => 'date', 'class' => 'field-narrow']) ?>
  <?= field_text('to', t('По дату'), $_GET['to'] ?? '', ['type' => 'date', 'class' => 'field-narrow']) ?>
  <noscript><?= field_submit(t('Фильтровать')) ?></noscript>
</form>

<div class="table-wrap">
<table class="table">
  <thead><tr><th><?= esc(t('Когда')) ?></th><th><?= esc(t('Событие')) ?></th><th><?= esc(t('Кто')) ?></th><th><?= esc(t('Над кем')) ?></th><th><?= esc(t('Портал')) ?></th><th>IP</th><th><?= esc(t('Детали')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
    $details = [
      t('Когда') => fmt_dt($r['created_at'], 'd.m.Y H:i:s') . ' (' . tz_gmt_label(App\Core\Auth::user()['timezone'] ?? 'UTC') . ')',
      t('Событие') => App\Core\Audit::title($r['event']) . ' (' . $r['event'] . ')',
      t('Кто') => $r['actor_email'] ?? t('система'),
      t('Над кем') => $r['on_email'] ?? '',
      t('Портал') => $r['client_name'] ?? '',
      'IP' => ip_str($r['ip']),
      t('Устройство') => device_text(device_label((string)$r['user_agent'])),
      'User-Agent' => (string)$r['user_agent'],
      t('Детали') => App\Core\Audit::metaText($r['meta'] ?? null),
    ]; ?>
  <tr class="row-clickable" data-drawer="<?= esc(t('Событие аудита #:id', ['id' => (int)$r['id']])) ?>"
      data-fields="<?= esc(json_encode($details, JSON_UNESCAPED_UNICODE)) ?>">
    <td class="hint nowrap"><?= esc(fmt_dt($r['created_at'], 'd.m.Y H:i:s')) ?></td>
    <td><?= event_label($r['event'], true) ?></td>
    <td class="hint"><?= esc($r['actor_email'] ?? t('система')) ?></td>
    <td class="hint"><?= esc($r['on_email'] ?? '') ?></td>
    <td class="hint"><?= esc($r['client_name'] ?? '') ?></td>
    <td class="hint mono"><?= ip_link($r['ip']) ?></td>
    <?php $metaText = App\Core\Audit::metaText($r['meta'] ?? null); ?>
    <td class="hint cell-clip" title="<?= esc($metaText) ?>"><?= esc($metaText) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="7"><?= empty_state('search', t('По этим фильтрам событий нет.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<div class="pagination">
  <?php $qs = $_GET; for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): $qs['page'] = $p; ?>
    <?php if ($p === $page): ?><span class="current"><?= $p ?></span>
    <?php else: ?><a href="/admin/logs?<?= esc(http_build_query($qs)) ?>"><?= $p ?></a><?php endif; ?>
  <?php endfor; ?>
</div>
<?php endif; ?>
