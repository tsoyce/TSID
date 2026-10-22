<?php /**
 * Активность человека (v4.72): откуда приходил и что открывал. $u, $addresses, $rows.
 *
 * Адреса — сверху: по ним чаще всего и начинают разбираться («это не он
 * входил») и отсюда же блокируют. Ниже — каждый запрос.
 */ ?>
<div class="bento-card">
  <div class="bc-title"><?= esc(t('Адреса')) ?></div>
  <?php if ($addresses === []): ?>
    <p class="hint mb-0"><?= esc(t('Пока не видно ни одного: журнал пишется с версии 4.72.')) ?></p>
  <?php else: ?>
  <div class="table-wrap borderless">
    <table class="table">
      <tbody>
      <?php foreach ($addresses as $a): $blocked = App\Core\IpBlock::match((string)$a['ip']) !== null; ?>
        <tr>
          <td><?= ip_link((string)$a['ip']) ?>
            <?php if ($blocked): ?> <span class="badge badge-danger"><?= esc(t('заблокирован')) ?></span><?php endif; ?></td>
          <td class="hint"><?= (int)$a['n'] ?> <?= esc(plural((int)$a['n'], 'запрос', 'запроса', 'запросов')) ?></td>
          <td class="hint nowrap"><?= esc(t('с :first по :last', ['first' => fmt_dt((string)$a['first'], 'd.m H:i'), 'last' => fmt_dt((string)$a['last'], 'd.m H:i')])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="bento-card">
  <div class="row-between mb-12">
    <div class="bc-title mb-0"><?= esc(t('Запросы')) ?></div>
    <?php $onlyFail = ($_GET['status'] ?? '') === 'fail'; ?>
    <a class="btn btn-s btn-secondary" href="/admin/users/<?= (int)$u['id'] ?>/activity<?= $onlyFail ? '' : '?status=fail' ?>"><?=
      esc($onlyFail ? t('Все запросы') : t('Только отказы и сбои')) ?></a>
  </div>
  <?= App\Core\View::fetch('admin/_activity_table', ['rows' => $rows, 'showUser' => false, 'showIp' => true]) ?>
  <?php if (count($rows) >= 100): $last = end($rows); ?>
  <p class="mt-12 mb-0"><a class="btn btn-s btn-secondary" href="/admin/users/<?= (int)$u['id'] ?>/activity?<?= esc(http_build_query(['before' => (int)$last['id']] + $_GET)) ?>"><?= esc(t('Раньше →')) ?></a></p>
  <?php endif; ?>
</div>
