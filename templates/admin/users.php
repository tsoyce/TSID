<?php /** Пользователи (§5.2): таблица + фильтры. Массовых действий нет — намеренно. */ ?>
<div class="page-head">
  <h1><?= esc(t('Пользователи')) ?> <span class="muted fw-400"><?= number_format($total, 0, '', ' ') ?></span></h1>
</div>

<form method="get" action="/admin/users" class="filter-bar">
  <?php
  $clientOptions = ['' => t('— любой')];
  foreach ($clients as $c) $clientOptions[(int)$c['id']] = $c['name'];
  $roleOptions = ['' => t('— любая')];
  foreach ($roles as $r) {
      /* Название — впереди, слаг — для сверки (v4.71): в списке стояли одни
         «user», «client_admin», и искать приходилось по машинному слову. */
      $roleOptions[(int)$r['id']] = (($r['name'] ?? '') !== '' ? $r['name'] . ' · ' : '')
          . ($r['client_slug'] ? $r['client_slug'] . ':' : '') . $r['slug'];
  }
  ?>
  <?= field_text('q', t('Поиск'), $filters['q'], [
        'class' => 'field-grow', 'placeholder' => t('email, логин, ФИО или sub')]) ?>
  <?= field_select('status', t('Статус'), [
        '' => t('— любой'), 'active' => t('Активен'), 'pending' => t('Ожидает почту'),
        'blocked' => t('Заблокирован'), 'deleted' => t('Удалён'),
      ], $filters['status']) ?>
  <?= field_select('client', t('Портал'), $clientOptions, (string)($filters['clientF'] ?: '')) ?>
  <?= field_select('role', t('Роль'), $roleOptions, (string)($filters['roleF'] ?: '')) ?>
  <?= field_select('flag', t('Признак'), [
        '' => '—', '2fa' => t('с 2FA'), 'no_email' => t('email не подтверждён'),
      ], $filters['flag']) ?>
  <?php /* Выбор в списке применяется сразу, поиск — по Enter (v4.71): кнопка
           стояла отдельной строкой под фильтрами и была лишним нажатием.
           Без скрипта она на месте. */ ?>
  <noscript><?= field_submit(t('Фильтровать')) ?></noscript>
</form>

<div class="table-wrap">
<table class="table table-cards">
  <thead><tr><th></th><th><?= esc(t('Пользователь')) ?></th><th><?= esc(t('Статус')) ?></th><th><?= esc(t('Роли')) ?></th><th><?= esc(t('Порталы')) ?></th><th><?= esc(t('Последний вход')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td class="cell-avatar"><?= avatar_html($u, 32) ?></td>
      <td data-label="<?= esc(t('Пользователь')) ?>" class="cell-primary">
        <?php /* Имя — одной строкой (v4.92): «имена» ботов в сотню знаков
                 переносились на две-три строки и растягивали ряд. Полное — в
                 подсказке; галочка подтверждённого не уезжает за обрез. */ ?>
        <div class="name-line"><a class="cell-main truncate" href="/admin/users/<?= (int)$u['id'] ?>" title="<?= esc(user_name($u)) ?>"><?= esc(user_name($u)) ?></a><?php if ($u['is_verified_badge']): ?><span class="verified-badge"><?= icon('badge-check', 15) ?></span><?php endif; ?></div>
        <?php $sub = user_sub($u); if ($sub !== '' || ($u['email'] && $u['login'])): ?>
        <?php $subLine = $sub . (($u['email'] && $u['login']) ? ' · @' . $u['login'] : ''); ?>
        <div class="hint name-line"><span class="truncate" title="<?= esc($subLine) ?>"><?= esc($subLine) ?></span></div>
        <?php endif; ?>
      </td>
      <td data-label="<?= esc(t('Статус')) ?>">
        <?php $map = ['active' => ['badge-ok', t('активен')], 'pending' => ['badge-warn', t('ждёт почту')],
                      'blocked' => ['badge-danger', t('заблокирован')], 'deleted' => ['badge-muted', t('удалён')]];
        [$cls, $label] = $map[$u['status']] ?? ['badge-muted', $u['status']]; ?>
        <span class="badge <?= $cls ?>"><?= $label ?></span>
        <?php if ($u['totp_enabled_at']): ?><span class="badge badge-ink">2FA</span><?php endif; ?>
      </td>
<?php /* Без переводов строк внутри <td>: пробелы сделали бы ячейку непустой,
             и на мобиле печаталась бы подпись «Роли» без единого значения. */ ?>
<?php /* Показываем две роли и число остальных. Полный список — в карточке, он
             там и нужен: в общем списке шесть плашек в строке не отвечают ни на
             один вопрос, а строку разносит. Все названия — в подсказке. */ ?>
<?php $uRoles = $rolesBy[(int)$u['id']] ?? []; $uSlugs = array_column($uRoles, 'slug'); ?>
      <td data-label="<?= esc(t('Роли')) ?>" class="hint" title="<?= esc(implode(', ', $uSlugs)) ?>"><?php foreach (array_slice($uRoles, 0, 2) as $r): ?><span class="badge badge-muted" title="<?= esc($r['slug']) ?>"><?= esc(($r['name'] ?? '') !== '' ? $r['name'] : $r['slug']) ?></span> <?php endforeach; ?><?php if (count($uRoles) > 2): ?><span class="badge badge-muted">+<?= count($uRoles) - 2 ?></span><?php endif; ?></td>
      <td data-label="<?= esc(t('Порталы')) ?>" class="hint"><?= esc(implode(', ', array_slice($accessBy[(int)$u['id']] ?? [], 0, 3))) ?><?= count($accessBy[(int)$u['id']] ?? []) > 3 ? '…' : '' ?></td>
      <td data-label="<?= esc(t('Последний вход')) ?>" class="hint nowrap"><?= esc(fmt_ago($u['last_login_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($users === []): ?>
    <tr><td colspan="6"><?= empty_state('search', t('Никого не нашлось — ослабьте фильтры.')) ?></td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<div class="pagination">
  <?php $qs = $_GET; for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): $qs['page'] = $p; ?>
    <?php if ($p === $page): ?><span class="current"><?= $p ?></span>
    <?php else: ?><a href="/admin/users?<?= esc(http_build_query($qs)) ?>"><?= $p ?></a><?php endif; ?>
  <?php endfor; ?>
</div>
<?php endif; ?>
