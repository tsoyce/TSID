<?php /** Дашборд (§5.2, v4.99): период, входы по способам, «Требует внимания», порталы. */
use App\Controllers\Admin\DashboardController;
use App\Core\Stats;

/* Подпись периода — одна на все плитки, чтобы число и кривая под ним
   говорили про один и тот же срок. */
$periodLabel = $days === 1 ? t('сегодня') : t('за :n дней', ['n' => $days]);
$prevLabel = $days === 1 ? t('ко вчера') : t('к прошлым :n дням', ['n' => $days]);
$delta = static fn(array $pair, bool $upIsGood): string => kpi_delta($pair, $upIsGood, $prevLabel);
$fmt = static fn(int $n): string => number_format($n, 0, '', ' ');
$methods = Stats::methods();
?>
<?= dash_head('pult', $days, t('Дашборд')) ?>

<?php if (!$onboarding['hidden'] && $onboarding['steps'] !== []): ?>
<?php /* Свёрнуты в одну строку (v4.99): развёрнутые, они занимали весь первый
         экран телефона, и до цифр нужно было листать. Открыты, пока не сделано
         ничего, — тогда это и есть главное на экране. */ ?>
<details class="bento-card onboarding-fold mb-16"<?= $onboarding['done'] === 0 ? ' open' : '' ?>>
  <summary class="row-between">
    <span class="row gap-8">
      <?= icon('check-circle', 18, 'icon-muted') ?>
      <span class="bc-title mb-0"><?= esc(t('Первые шаги · :done из :total', ['done' => (int)$onboarding['done'], 'total' => (int)$onboarding['total']])) ?></span>
    </span>
    <span class="score-bar score-bar-fixed"><i style="width:<?= (int)round($onboarding['done'] / max(1, $onboarding['total']) * 100) ?>%"></i></span>
  </summary>
  <div class="onboarding-grid mt-16">
    <?php foreach ($onboarding['steps'] as $s): ?>
    <div class="onboarding-step<?= $s['done'] ? ' is-done' : '' ?>">
      <span class="onboarding-mark">
        <?= $s['done'] ? icon('check-circle', 20, 'icon-ok') : icon($s['icon'], 20, 'icon-muted') ?>
      </span>
      <div class="flex-1">
        <div class="cell-main"><?= esc($s['label']) ?></div>
        <div class="hint"><?= esc($s['hint']) ?></div>
      </div>
      <?php if (!$s['done']): ?>
        <a class="btn btn-s btn-secondary" href="<?= esc($s['url']) ?>"><?= esc($s['cta']) ?></a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <form class="mt-16" method="post" action="/admin/onboarding/hide" data-confirm="<?= esc(t('Скрыть чеклист? Вернуть его можно, очистив настройку onboarding.hidden.')) ?>">
    <?= csrf_field() ?>
    <button class="btn btn-ghost btn-s" type="submit"><?= esc(t('Скрыть')) ?></button>
  </form>
</details>
<?php endif; ?>

<div class="bento">
  <div class="bento-card col-3 kpi-tile"><div class="bc-title"><?= esc(t('Входы')) ?></div>
    <div class="kpi-value" data-count><?= $fmt($kpi['logins'][0]) ?></div>
    <div class="kpi-sub"><?= esc($periodLabel) ?><?= $delta($kpi['logins'], true) ?></div>
    <?php /* Кривая у каждой плитки (v4.84), за тот же период, что число (v4.99). */ ?>
    <div class="sparkline"><?= DashboardController::sparkSvg($loginSpark, 120, 36, t('Входы за период')) ?></div></div>
  <div class="bento-card col-3 kpi-tile"><div class="bc-title"><?= esc(t('Новые люди')) ?></div>
    <div class="kpi-value" data-count><?= $fmt($kpi['users_new'][0]) ?></div>
    <div class="kpi-sub"><?= esc($periodLabel) ?><?= $delta($kpi['users_new'], true) ?> · <?= esc(t('всего :n', ['n' => $fmt($kpi['users_total'])])) ?></div>
    <div class="sparkline"><?= DashboardController::sparkSvg($regSpark, 120, 36, t('Регистрации за период')) ?></div></div>
  <div class="bento-card col-3 kpi-tile"><div class="bc-title"><?= esc(t('Неудачные входы')) ?></div>
    <div class="kpi-value" data-count><?= $fmt($kpi['fails'][0]) ?></div>
    <div class="kpi-sub"><?= esc($periodLabel) ?><?= $delta($kpi['fails'], false) ?></div>
    <div class="sparkline"><?= DashboardController::sparkSvg($failSpark, 120, 36, t('Неудачные входы за период')) ?></div></div>
  <div class="bento-card col-3 kpi-tile"><div class="bc-title"><?= esc(t('Сейчас в сети')) ?></div>
    <div class="kpi-value" data-count><?= $fmt($kpi['sessions_active']) ?></div>
    <?php /* Число — живые сессии, кривая — новые по дням (v4.98): подпись
             говорит про обе, иначе кривая читалась как «онлайн по дням». */ ?>
    <div class="kpi-sub"><?= esc(t('активных сессий · кривая — новые за день')) ?></div>
    <div class="sparkline"><?= DashboardController::sparkSvg($sessionSpark, 120, 36, t('Новые сессии за период')) ?></div></div>

  <div class="bento-card col-8">
    <div class="bc-title"><?= esc(t('Входы по способам')) ?> · <?= esc($periodLabel) ?></div>
    <div class="chart-legend">
      <?php $i = 0; foreach ($methods as $name): $i++; ?>
      <span><i class="s<?= $i ?>"></i><?= esc($name) ?></span>
      <?php endforeach; ?>
    </div>
    <div class="chart chart-bars" data-names="<?= esc(implode('|', $methods)) ?>">
      <?= DashboardController::barsSvg($byMethod, $days, t('Входы по способам')) ?>
    </div>
    <?php /* Таблица-двойник: цвет не единственный носитель смысла, а у
             скринридера нет курсора, чтобы навести на столбец. */ ?>
    <details class="chart-table">
      <summary><?= esc(t('Таблицей')) ?></summary>
      <div class="table-wrap borderless mt-8">
        <table class="table">
          <thead><tr><th></th><?php foreach ($methods as $name): ?><th class="text-right"><?= esc($name) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach (array_keys(reset($byMethod) ?: []) as $k): ?>
          <tr><td class="nowrap"><?= esc($days === 1 ? $k . ':00' : substr((string)$k, 8, 2) . '.' . substr((string)$k, 5, 2)) ?></td>
            <?php foreach ($byMethod as $row): ?><td class="text-right"><?= (int)$row[$k] ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  </div>

  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Требует внимания')) ?></div>
    <ul class="attention">
    <?php foreach ($attention as $a): ?>
      <li class="is-<?= esc($a['level']) ?>">
        <?= status_icon($a['level'], 18) ?>
        <a href="<?= esc($a['url']) ?>"><span class="cell-main"><?= esc($a['title']) ?></span>
          <?php if ($a['note'] !== ''): ?><span class="hint"><?= esc($a['note']) ?></span><?php endif; ?></a>
      </li>
    <?php endforeach; ?>
    <?php if ($attention === []): ?>
      <li class="is-ok"><?= status_icon('ok', 18) ?><span><span class="cell-main"><?= esc(t('Всё в порядке')) ?></span>
        <span class="hint"><?= esc(t('База, ключи, cron, почта и доставка событий')) ?></span></span></li>
    <?php endif; ?>
    </ul>
    <div class="bc-footer"><a href="/admin/health"><?= esc(t('Здоровье подробно →')) ?></a></div>
  </div>

  <div class="bento-card col-6">
    <div class="bc-title"><?= esc(t('Порталы по входам')) ?> · <?= esc($periodLabel) ?></div>
    <?php if ($portals === []): ?>
      <p class="hint"><?= esc(t('Через порталы за этот период не входили.')) ?></p>
    <?php else: $top = max(1, $portals[0]['n']); ?>
    <ul class="rank-list">
      <?php foreach ($portals as $pt): ?>
      <li><a href="/admin/clients/<?= (int)$pt['id'] ?>"><?= esc($pt['name']) ?></a><span class="rank-n"><?= $fmt($pt['n']) ?></span>
        <span class="rank-track"><i style="width:<?= round($pt['n'] / $top * 100, 1) ?>%"></i></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <div class="bc-footer"><a href="/admin/clients"><?= esc(t('Все порталы →')) ?></a></div>
  </div>

  <div class="bento-card col-6">
    <div class="bc-title"><?= esc(t('Защита аккаунтов')) ?></div>
    <?php $share = $protection['total'] > 0 ? (int)round($protection['protected'] / $protection['total'] * 100) : 0; ?>
    <div class="ring-row">
      <svg class="ring" viewBox="0 0 112 112" role="img" aria-label="<?= esc(t(':p % аккаунтов с passkey или вторым фактором', ['p' => $share])) ?>">
        <circle cx="56" cy="56" r="44" class="ring-track"/>
        <?php /* Ноль — без дуги: скруглённый конец нулевой длины рисуется точкой. */ ?>
        <?php if ($share > 0): ?><circle cx="56" cy="56" r="44" class="ring-value" pathLength="100" stroke-dasharray="<?= $share ?> 100" transform="rotate(-90 56 56)"/><?php endif; ?>
        <text x="56" y="62" text-anchor="middle"><?= $share ?> %</text>
      </svg>
      <div class="ring-text">
        <div><span class="cell-main"><?= $fmt($protection['protected']) ?></span> <?= esc(t('с passkey или вторым фактором')) ?></div>
        <div class="hint"><?= esc(t('ещё :n входят только паролем или кодом', ['n' => $fmt($protection['total'] - $protection['protected'])])) ?></div>
        <div class="hint"><?= esc(t('Это рекомендация, а не требование: число просто видно.')) ?></div>
      </div>
    </div>
  </div>

  <div class="bento-card col-8">
    <div class="bc-title"><?= esc(t('Последние события')) ?></div>
    <div class="table-wrap borderless">
      <table class="table">
        <tbody>
        <?php foreach ($audit as $a): ?>
        <tr>
          <td class="hint nowrap"><?= esc(fmt_dt($a['created_at'], 'd.m H:i')) ?></td>
          <td><?= event_label($a['event']) ?></td>
          <td class="hint"><?= esc($a['on_email'] ?? $a['actor_email'] ?? '—') ?></td>
          <td class="hint hide-narrow"><?= esc($a['client_name'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="bc-footer"><a href="/admin/logs"><?= esc(t('Весь аудит →')) ?></a></div>
  </div>

  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Быстрые действия')) ?></div>
    <div class="stack">
      <a class="btn btn-secondary btn-block" href="/admin/clients#открыть-wizard"><?= esc(t('Подключить сервис')) ?></a>
      <a class="btn btn-secondary btn-block" href="/admin/users"><?= esc(t('Найти пользователя')) ?></a>
      <a class="btn btn-secondary btn-block" href="/admin/tools"><?= esc(t('Импорт пользователей')) ?></a>
      <a class="btn btn-secondary btn-block" href="/admin/logs/mail"><?= esc(t('Почтовая очередь')) ?></a>
    </div>
  </div>
</div>
