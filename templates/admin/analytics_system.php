<?php /** Система (v5.2): нагрузка, скорость и ошибки приложения, почта — за период. */
use App\Controllers\Admin\DashboardController;
use App\Core\Stats;

$periodLabel = $days === 1 ? t('сегодня') : t('за :n дней', ['n' => $days]);
$fmt = static fn(int $n): string => number_format($n, 0, '', ' ');
$total = array_sum($requests);
$fails = array_sum($errors);

/* Подпись корзины: «до 50 мс», «50–100 мс», …, «дольше 2 с». */
$ms = static fn(int $v): string => $v >= 1000 ? t(':n с', ['n' => $v / 1000]) : t(':n мс', ['n' => $v]);
$last = count(Stats::SPEED);
$bucketName = static function (int $i) use ($ms, $last): string {
    if ($i === 0) return t('до :v', ['v' => $ms(Stats::SPEED[0])]);
    if ($i >= $last) return t('дольше :v', ['v' => $ms(Stats::SPEED[$last - 1])]);
    [$lo, $hi] = [Stats::SPEED[$i - 1], Stats::SPEED[$i]];
    /* Единица одна на диапазон: «1–2 с», а не «1000–2 с». */
    if (($lo >= 1000) === ($hi >= 1000)) return ($lo >= 1000 ? $lo / 1000 : $lo) . '–' . $ms($hi);
    return $ms($lo) . ' – ' . $ms($hi);
};
/* Процентиль — верхняя граница его корзины: «95 % — до 200 мс». */
$upTo = static fn(?int $i): string => $i === null ? '—'
    : ($i >= $last ? $bucketName($i) : t('до :v', ['v' => $ms(Stats::SPEED[$i])]));
$mailAll = array_sum($mail);
?>
<?= dash_head('system', $days, t('Аналитика')) ?>

<div class="bento">
  <div class="bento-card col-3 kpi-tile"><div class="bc-title"><?= esc(t('Запросы')) ?></div>
    <div class="kpi-value" data-count><?= $fmt($total) ?></div>
    <div class="kpi-sub"><?= esc($periodLabel) ?> · <?= esc(t('без стука сканеров')) ?></div></div>
  <div class="bento-card col-3 kpi-tile"><div class="bc-title"><?= esc(t('Скорость ответа')) ?></div>
    <div class="kpi-value"><?= esc($upTo($speed['p95'])) ?></div>
    <div class="kpi-sub"><?= esc(t('95 % запросов · половина — :m', ['m' => $upTo($speed['p50'])])) ?></div></div>
  <div class="bento-card col-3 kpi-tile"><div class="bc-title"><?= esc(t('Ошибки сервера')) ?></div>
    <div class="kpi-value" data-count><?= $fmt($fails) ?></div>
    <div class="kpi-sub"><?php if ($fails > 0): ?><span class="kpi-delta is-bad"><?= esc(t(':p % запросов', ['p' => $total > 0 ? round($fails / $total * 100, 2) : 0])) ?></span><?php else: ?><?= esc(t('ответов 5xx не было')) ?><?php endif; ?></div></div>
  <div class="bento-card col-3 kpi-tile"><div class="bc-title"><?= esc(t('Почта')) ?></div>
    <div class="kpi-value" data-count><?= $fmt((int)($mail['sent'] ?? 0)) ?></div>
    <div class="kpi-sub"><?= $mailAll === 0 ? esc(t('писем не было')) : esc(t('отправлено · в очереди :q · не ушло :f', ['q' => $fmt((int)($mail['pending'] ?? 0)), 'f' => $fmt((int)($mail['failed'] ?? 0))])) ?></div></div>

  <div class="bento-card col-8">
    <div class="bc-title"><?= esc(t('Запросы')) ?> · <?= esc($periodLabel) ?></div>
    <div class="chart chart-bars" data-names="<?= esc(t('Запросы')) ?>">
      <?= DashboardController::barsSvg([$requests], $days, t('Запросы')) ?>
    </div>
    <?php if ($fails > 0): ?>
    <div class="bc-title mt-16"><?= esc(t('Ошибки сервера')) ?></div>
    <div class="chart chart-bars" data-names="<?= esc(t('Ошибки сервера')) ?>">
      <?= DashboardController::barsSvg([$errors], $days, t('Ошибки сервера')) ?>
    </div>
    <?php endif; ?>
    <details class="chart-table">
      <summary><?= esc(t('Таблицей')) ?></summary>
      <div class="table-wrap borderless mt-8">
        <table class="table">
          <thead><tr><th></th><th class="text-right"><?= esc(t('Запросы')) ?></th><th class="text-right"><?= esc(t('Ошибки сервера')) ?></th></tr></thead>
          <tbody>
          <?php foreach ($requests as $k => $n): ?>
          <tr><td class="nowrap"><?= esc($days === 1 ? $k . ':00' : substr((string)$k, 8, 2) . '.' . substr((string)$k, 5, 2)) ?></td>
            <td class="text-right"><?= (int)$n ?></td><td class="text-right"><?= (int)$errors[$k] ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
    <div class="bc-footer"><a href="/admin/logs/requests"><?= esc(t('К запросам →')) ?></a></div>
  </div>

  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Время ответа')) ?> · <?= esc($periodLabel) ?></div>
    <?php if ($speed['total'] === 0): ?>
      <p class="hint"><?= esc(t('Запросов не было.')) ?></p>
    <?php else: $top = max(1, ...$speed['buckets']); ?>
    <ul class="rank-list">
      <?php foreach ($speed['buckets'] as $i => $n): ?>
      <li><span class="rank-label"><?= esc($bucketName($i)) ?></span><span class="rank-n"><?= $fmt($n) ?></span>
        <span class="rank-track"><i style="width:<?= round($n / $top * 100, 1) ?>%"></i></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>

  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Самые медленные адреса')) ?> · <?= esc($periodLabel) ?></div>
    <?php if ($slow === []): ?>
      <p class="hint"><?= esc(t('Мало запросов, чтобы судить: нужно хотя бы пять запросов на адрес.')) ?></p>
    <?php else: ?>
    <div class="table-wrap borderless">
      <table class="table">
        <thead><tr><th><?= esc(t('Адрес')) ?></th><th class="text-right hide-narrow"><?= esc(t('Запросов')) ?></th><th class="text-right"><?= esc(t('В среднем')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($slow as $s): ?>
        <tr><td class="mono"><?= esc($s['path']) ?></td><td class="text-right hide-narrow"><?= $fmt($s['n']) ?></td>
          <td class="text-right nowrap"><?= esc(t(':n мс', ['n' => $fmt($s['ms'])])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
