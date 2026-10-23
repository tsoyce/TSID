<?php /**
 * Аналитика сервиса (v5.2): как порталом пользуются за период.
 *
 * Обзор отвечает «всё ли в порядке сейчас», здесь — «как идёт»: входы и
 * разница к прошлому периоду, сколько людей заходило за день, неделю и месяц,
 * обновления токенов, доставка событий порталу и отказы. $c, $days, $a.
 */
use App\Controllers\Admin\DashboardController;

$base = '/admin/clients/' . (int)$c['id'];
$periodLabel = $days === 1 ? t('сегодня') : t('за :n дней', ['n' => $days]);
$prevLabel = $days === 1 ? t('ко вчера') : t('к прошлым :n дням', ['n' => $days]);
$fmt = static fn(int $n): string => number_format($n, 0, '', ' ');
$sent = (int)($a['delivery']['sent'] ?? 0);
$events = array_sum($a['delivery']);
$refusalNames = [
    'access.requested' => t('Запросили доступ'),
    'access.denied' => t('В доступе отказано'),
    'consent.denied' => t('Отказались от согласия'),
];
?>
<div class="mb-16"><?= period_switch($base . '/analytics', $days) ?></div>

<div class="bento">
  <div class="bento-card col-3 kpi-tile"><div class="bc-title"><?= esc(t('Входы в сервис')) ?></div>
    <div class="kpi-value" data-count><?= $fmt($a['logins'][0]) ?></div>
    <div class="kpi-sub"><?= esc($periodLabel) ?><?= kpi_delta($a['logins'], true, $prevLabel) ?></div></div>
  <?php /* Три окна «активных» рядом: по отдельности «12 за неделю» ничего не
           говорит, рядом с «40 за месяц» видно, что заходят не каждую неделю. */ ?>
  <div class="bento-card col-3 kpi-tile"><div class="bc-title"><?= esc(t('Активные люди')) ?></div>
    <div class="kpi-value" data-count><?= $fmt($a['active']['month']) ?></div>
    <div class="kpi-sub"><?= esc(t('за 30 дней · за неделю :w · сегодня :d', ['w' => $fmt($a['active']['week']), 'd' => $fmt($a['active']['day'])])) ?></div></div>
  <div class="bento-card col-3 kpi-tile"><div class="bc-title"><?= esc(t('Обновления токенов')) ?></div>
    <div class="kpi-value" data-count><?= $fmt($a['refresh']) ?></div>
    <div class="kpi-sub"><?php if ($a['reuse'] > 0): ?><span class="kpi-delta is-bad"><?= esc(t('повторов refresh-токена: :n', ['n' => $a['reuse']])) ?></span><?php else: ?><?= esc(t('повторов refresh-токена не было')) ?><?php endif; ?></div></div>
  <div class="bento-card col-3 kpi-tile"><div class="bc-title"><?= esc(t('Доставка событий')) ?></div>
    <div class="kpi-value"><?= $events > 0 ? (int)floor($sent / $events * 100) . ' %' : '—' ?></div>
    <div class="kpi-sub"><?= $events > 0
        ? esc(t('доставлено :s из :n', ['s' => $fmt($sent), 'n' => $fmt($events)]))
        : esc(t('событий порталу не было')) ?></div></div>

  <div class="bento-card col-8">
    <div class="bc-title"><?= esc(t('Входы в сервис')) ?> · <?= esc($periodLabel) ?></div>
    <div class="chart chart-bars" data-names="<?= esc(t('Входы')) ?>">
      <?= DashboardController::barsSvg([$a['series']], $days, t('Входы в сервис')) ?>
    </div>
    <details class="chart-table">
      <summary><?= esc(t('Таблицей')) ?></summary>
      <div class="table-wrap borderless mt-8">
        <table class="table">
          <tbody>
          <?php foreach ($a['series'] as $k => $n): ?>
          <tr><td class="nowrap"><?= esc($days === 1 ? $k . ':00' : substr((string)$k, 8, 2) . '.' . substr((string)$k, 5, 2)) ?></td><td class="text-right"><?= (int)$n ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  </div>

  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Отказы и запросы')) ?> · <?= esc($periodLabel) ?></div>
    <?php if (array_sum($a['refusals']) === 0): ?>
      <p class="hint"><?= esc(t('Ни запросов доступа, ни отказов.')) ?></p>
    <?php else: ?>
    <ul class="rank-list">
      <?php foreach ($a['refusals'] as $e => $n): ?>
      <li><span class="rank-label"><?= esc($refusalNames[$e]) ?></span><span class="rank-n"><?= $fmt($n) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($a['errors'] !== []): ?>
    <div class="bc-title mt-16"><?= esc(t('Чем портал не принял события')) ?></div>
    <ul class="rank-list">
      <?php foreach ($a['errors'] as $e): ?>
      <li><span class="rank-label mono" title="<?= esc($e['error']) ?>"><?= esc($e['error']) ?></span><span class="rank-n"><?= $fmt($e['n']) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <div class="bc-footer"><a href="<?= $base ?>/events"><?= esc(t('К доставке →')) ?></a></div>
  </div>
</div>
