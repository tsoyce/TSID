<?php /** Аналитика входа и регистрации (v5.1): то, что пульт показывает одной цифрой, — разложенным. */
use App\Core\Stats;

$periodLabel = $days === 1 ? t('сегодня') : t('за :n дней', ['n' => $days]);
$fmt = static fn(int $n): string => number_format($n, 0, '', ' ');
$pct = static fn(int $a, int $b): int => $b > 0 ? (int)round($a / $b * 100) : 0;

/* Длинный хвост сворачиваем в «Другие»: шестой браузер с одним входом ничего
   не говорит, а список длиной в экран прячет главное. */
$top = static function (array $rows, int $n = 5): array {
    if (count($rows) <= $n + 1) return $rows;
    $head = array_slice($rows, 0, $n, true);
    $head[t('Другие')] = array_sum(array_slice($rows, $n));
    return $head;
};
$rank = static function (array $rows) use ($fmt): void {
    $max = max(1, ...array_values($rows)); ?>
    <ul class="rank-list">
      <?php foreach ($rows as $label => $n): ?>
      <li><span class="rank-label"><?= esc((string)$label) ?></span><span class="rank-n"><?= $fmt($n) ?></span>
        <span class="rank-track"><i style="width:<?= round($n / $max * 100, 1) ?>%"></i></span></li>
      <?php endforeach; ?>
    </ul>
<?php };

$steps = [
    'signup.started' => t('Ввели почту'),
    'signup.verified' => t('Подтвердили почту'),
    'register' => t('Завершили регистрацию'),
];
$reasonNames = [
    'identity' => t('Нет такого пользователя'),
    'password' => t('Неверный пароль'),
    'passkey_unknown' => t('Незнакомый passkey'),
    'passkey' => t('Passkey не прошёл проверку'),
    'other' => t('Прочее'),
];
$weekdays = [1 => t('Пн'), t('Вт'), t('Ср'), t('Чт'), t('Пт'), t('Сб'), t('Вс')];
$heatMax = max(1, ...array_map(static fn($row) => max($row), $heat));
$heatTotal = array_sum(array_map('array_sum', $heat));
$methodNames = Stats::methods();
$methodTotal = array_sum($methods);
?>
<?= dash_head('analytics', $days, t('Аналитика')) ?>

<div class="bento">
  <div class="bento-card col-6">
    <div class="bc-title"><?= esc(t('Регистрация по шагам')) ?> · <?= esc($periodLabel) ?></div>
    <?php $first = max(1, $funnel[0]['n'], $funnel[2]['n']); ?>
    <ol class="funnel">
      <?php foreach ($funnel as $i => $f): ?>
      <li>
        <span class="rank-label"><?= esc($steps[$f['key']]) ?></span>
        <span class="rank-n"><?= $fmt($f['n']) ?><?php if ($i > 0 && $funnel[$i - 1]['n'] > 0): ?>
          <span class="funnel-conv"><?= esc(t(':p % от шага выше', ['p' => $pct($f['n'], $funnel[$i - 1]['n'])])) ?></span><?php endif; ?></span>
        <span class="funnel-bar"><i style="width:<?= round($f['n'] / $first * 100, 1) ?>%"></i></span>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php /* Шаги пишутся с 5.1: раньше было видно только итог, и пустая
             воронка при живых регистрациях — не поломка, а отсутствие истории. */ ?>
    <?php if ($funnel[0]['n'] === 0 && $funnel[2]['n'] > 0): ?>
    <p class="hint mt-8"><?= esc(t('Шаги регистрации считаются с версии 5.1 — до неё виден только итог.')) ?></p>
    <?php endif; ?>
  </div>

  <div class="bento-card col-6">
    <div class="bc-title"><?= esc(t('Способы входа')) ?> · <?= esc($periodLabel) ?></div>
    <?php if ($methodTotal === 0): ?>
      <p class="hint"><?= esc(t('За этот период не входили.')) ?></p>
    <?php else: ?>
    <ul class="rank-list">
      <?php $i = 0; foreach ($methods as $e => $n): $i++; ?>
      <li><span class="rank-label"><i class="chart-dot s<?= $i ?>"></i><?= esc($methodNames[$e]) ?></span>
        <span class="rank-n"><?= $fmt($n) ?> · <?= $pct($n, $methodTotal) ?> %</span>
        <span class="rank-track"><i class="s<?= $i ?>" style="width:<?= $pct($n, $methodTotal) ?>%"></i></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>

  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Когда входят')) ?> · <?= esc($days < 7 ? t('за 7 дней') : $periodLabel) ?></div>
    <?php if ($heatTotal === 0): ?>
      <p class="hint"><?= esc(t('За этот период не входили.')) ?></p>
    <?php else: ?>
    <?php /* Часы недели по местному времени: видно, когда нагрузка и когда
             спокойно обновлять. Цвет — один оттенок, светлее-темнее: здесь
             важна величина, а не категория. */ ?>
    <div class="heat-wrap">
      <div class="heat" role="img" aria-label="<?= esc(t('Входы по дням недели и часам')) ?>">
        <span></span>
        <?php for ($h = 0; $h < 24; $h++): ?><span class="heat-h"><?= $h % 3 === 0 ? $h : '' ?></span><?php endfor; ?>
        <?php foreach ($heat as $wd => $row): ?>
          <span class="heat-d"><?= esc($weekdays[$wd]) ?></span>
          <?php foreach ($row as $h => $n): ?><i class="heat-cell l<?= $n === 0 ? 0 : (int)ceil($n / $heatMax * 4) ?>" title="<?= esc($weekdays[$wd] . ' ' . sprintf('%02d:00', $h) . ' · ' . $n) ?>"></i><?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="heat-legend hint"><?= esc(t('меньше')) ?><?php for ($l = 0; $l <= 4; $l++): ?><i class="heat-cell l<?= $l ?>"></i><?php endfor; ?><?= esc(t('больше')) ?> · <?= esc(t('максимум :n за час', ['n' => $heatMax])) ?></div>
    <details class="chart-table">
      <summary><?= esc(t('Таблицей')) ?></summary>
      <div class="table-wrap"><table class="table">
        <thead><tr><th></th><?php for ($h = 0; $h < 24; $h++): ?><th><?= $h ?></th><?php endfor; ?></tr></thead>
        <tbody><?php foreach ($heat as $wd => $row): ?><tr><th><?= esc($weekdays[$wd]) ?></th><?php foreach ($row as $n): ?><td><?= $n ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
      </table></div>
    </details>
    <?php endif; ?>
  </div>

  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Почему не вошли')) ?> · <?= esc($periodLabel) ?></div>
    <?php if ($reasons === []): ?>
      <p class="hint"><?= esc(t('Неудачных попыток не было.')) ?></p>
    <?php else: ?>
      <?php $named = []; foreach ($reasons as $k => $n) $named[$reasonNames[$k] ?? $k] = $n; ?>
      <?php $rank($top($named)); ?>
    <?php endif; ?>
    <div class="bc-footer"><a href="/admin/logs?event=login.fail"><?= esc(t('В журнал →')) ?></a></div>
  </div>

  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Браузеры')) ?> · <?= esc($periodLabel) ?></div>
    <?php if ($devices['browsers'] === []): ?>
      <p class="hint"><?= esc(t('За этот период не входили.')) ?></p>
    <?php else: ?>
      <?php $named = []; foreach ($devices['browsers'] as $k => $n) $named[device_text((string)$k)] = $n; ?>
      <?php $rank($top($named)); ?>
    <?php endif; ?>
  </div>

  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Системы')) ?> · <?= esc($periodLabel) ?></div>
    <?php if ($devices['systems'] === []): ?>
      <p class="hint"><?= esc(t('За этот период не входили.')) ?></p>
    <?php else: ?>
      <?php $rank($top($devices['systems'])); ?>
    <?php endif; ?>
  </div>
</div>
