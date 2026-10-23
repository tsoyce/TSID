<?php /**
 * Антифрод (v5.5). $days, $suspects, $refused, $fails, $archived, $archiveTotal,
 * $blocks, $nets, $summary, $samples, $keepDays. См. FraudController.
 *
 * Сверху — числа плитками с кривой за месяц, каждая ведёт туда, где с этим
 * что-то делают. Ниже — откуда идут и что известно из архива: признаки,
 * браузеры, домены почты, скорость заполнения формы. Последним — сами образцы.
 */
use App\Controllers\Admin\DashboardController;
use App\Core\BotSignal;

$tiles = [
    ['/admin/users/bots', 'user', t('Похоже на ботов'), $suspects,
        $suspects > 0 ? t('ждут разбора') : t('никого'), [], $suspects > 0],
    ['/admin/logs?event=register.refused', 'shield-check', t('Отказано ботам'), array_sum($refused),
        t('устаревший браузер, за :n дн.', ['n' => $days]), $refused, false],
    ['/admin/logs?event=login.fail', 'lock', t('Неудачные входы'), array_sum($fails),
        t('за :n дн.', ['n' => $days]), $fails, false],
    ['#archive', 'log', t('В архиве'), $archiveTotal,
        t('за :n дн.: :k', ['n' => $days, 'k' => array_sum($archived)]), $archived, false],
];
$top = static function (array $rows): string {
    if ($rows === []) return '<p class="hint mb-0">' . esc(t('Пока пусто.')) . '</p>';
    $out = '<dl class="kv-list">';
    foreach ($rows as $k => $n) $out .= '<dt class="truncate">' . esc((string)$k) . '</dt><dd class="mono">' . (int)$n . '</dd>';
    return $out . '</dl>';
};
$signals = [];
foreach ($summary['signals'] as $code => $n) $signals[BotSignal::signalLabel((string)$code)] = $n; ?>
<div class="page-head">
  <h1><?= esc(t('Антифрод')) ?></h1>
  <a class="btn btn-secondary" href="/admin/users/bots"><?= esc(t('Похоже на ботов')) ?></a>
</div>

<?= logs_tabs('fraud') ?>

<div class="bento">
  <?php foreach ($tiles as [$href, $ico, $title, $value, $sub, $spark, $warn]): ?>
  <div class="bento-card col-3 list-card stat-tile<?= $warn ? ' is-warn' : '' ?>">
    <div class="bc-title"><?= icon($ico, 14) ?> <a href="<?= esc($href) ?>" class="stat-link"><?= esc($title) ?></a></div>
    <div class="kpi-value"><?= number_format((int)$value, 0, '', ' ') ?></div>
    <div class="kpi-sub"><?= esc($sub) ?></div>
    <?php if ($spark !== []): ?><div class="sparkline"><?= DashboardController::sparkSvg($spark, 120, 36, $title) ?></div><?php endif; ?>
  </div>
  <?php endforeach; ?>

  <div class="bento-card col-7">
    <div class="bc-title"><?= esc(t('Откуда идут')) ?> <span class="hint fw-400"><?= esc(t('закрыто правил: :n', ['n' => $blocks])) ?></span></div>
    <?php if ($nets === []): ?>
      <?= empty_state('shield-check', t('Ни регистраций без подтверждения, ни отказов, ни неудачных входов.')) ?>
    <?php else: ?>
    <ul class="check-list health-list">
      <?php foreach ($nets as $n): ?>
      <li><?= status_icon($n['blocked'] ? 'ok' : 'warn', 18) ?>
        <span class="flex-1"><span class="mono"><?= esc($n['net']) ?></span>
          <div class="hint"><?= esc(t('без подтверждения: :u · отказов: :r · неудачных входов: :f', [
              'u' => $n['unconfirmed'], 'r' => $n['refused'], 'f' => $n['fails']])) ?></div></span>
        <?php if (!$n['blocked'] && !$n['mine']): ?><a class="hint nowrap" href="/admin/blocks?ip=<?= esc(rawurlencode($n['net'])) ?>"><?= esc(t('Закрыть →')) ?></a><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>

  <div class="bento-card col-5">
    <div class="bc-title"><?= esc(t('Что сработало')) ?></div>
    <?= $top($signals) ?>
    <p class="hint mt-8 mb-0"><?= esc(t('Оценка риска — сумма весов признаков; от :n отмечены к удалению сразу.', ['n' => BotSignal::SURE])) ?></p>
  </div>

  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Чем притворяются')) ?></div>
    <?= $top($summary['browsers']) ?>
  </div>
  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Домены почты')) ?></div>
    <?= $top($summary['domains']) ?>
  </div>
  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Подсети в архиве')) ?></div>
    <?= $top($summary['nets']) ?>
    <?php if ($summary['form_s'] !== null): ?>
      <p class="hint mt-8 mb-0"><?= esc(t('Форму регистрации заполняют за :s с (медиана).', ['s' => $summary['form_s']])) ?></p>
    <?php endif; ?>
  </div>
</div>

<div class="bento-card mt-16" id="archive">
  <div class="page-head">
    <div class="bc-title"><?= esc(t('Архив образцов')) ?> <span class="muted fw-400"><?= (int)$archiveTotal ?></span></div>
    <?php if ($archiveTotal > 0): ?><a class="btn btn-secondary btn-s" href="/admin/fraud/samples.json"><?= esc(t('Выгрузить JSON')) ?></a><?php endif; ?>
  </div>
  <p class="hint mb-12"><?= esc(t('Образец откладывается, когда аккаунт удаляют как бота. Без почты, имени и адреса — подсеть, браузер, домен почты, признаки и поведение. Подсети и браузеры из архива помечают следующих ботов.')) ?>
    <?= esc($keepDays > 0 ? t('Хранится :n дн., срок — в политиках.', ['n' => $keepDays]) : t('Хранится без срока, срок — в политиках.')) ?></p>
  <?php if ($samples === []): ?>
    <?= empty_state('log', t('Архив пуст. Он наполняется при удалении на «Похоже на ботов».')) ?>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table table-cards">
    <thead><tr>
      <th><?= esc(t('Когда')) ?></th><th><?= esc(t('Откуда')) ?></th><th><?= esc(t('Признаки')) ?></th><th><?= esc(t('Поведение')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($samples as $s): $act = $s['activity']; ?>
      <tr>
        <td data-label="<?= esc(t('Когда')) ?>" class="hint nowrap"><div class="cell-stack">
          <span><?= esc(fmt_ago((string)$s['created_at'])) ?></span>
          <?php if (!empty($s['registered_at'])): ?><span><?= esc(t('завёлся :d', ['d' => fmt_dt((string)$s['registered_at'])])) ?></span><?php endif; ?>
        </div></td>
        <td data-label="<?= esc(t('Откуда')) ?>" class="hint"><div class="cell-stack">
          <span class="mono"><?= esc((string)($s['net'] ?? '—')) ?></span>
          <div class="truncate"><?= esc((string)($s['outdated'] ?? device_text(device_label((string)($s['user_agent'] ?? ''))))) ?><?php if (!empty($s['email_domain'])): ?> · @<?= esc((string)$s['email_domain']) ?><?php endif; ?></div>
        </div></td>
        <td data-label="<?= esc(t('Признаки')) ?>"><span class="chip-row">
          <span class="badge badge-warn"><?= (int)$s['score'] ?></span>
          <?php foreach ($s['signals'] as $c): ?><span class="badge badge-muted"><?= esc(BotSignal::signalLabel((string)$c)) ?></span><?php endforeach; ?>
        </span></td>
        <td data-label="<?= esc(t('Поведение')) ?>" class="hint"><?= esc(t('запросов: :n', ['n' => (int)($act['requests'] ?? 0)])) ?><?php
          if (($act['form_s'] ?? null) !== null): ?> · <?= esc(t('форма за :s с', ['s' => (int)$act['form_s']])) ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
