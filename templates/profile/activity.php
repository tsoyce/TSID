<?php /**
 * История аккаунта (v5.3): входы, неудачные попытки, изменения защиты.
 *
 * По дням и по местному времени, новые сверху. У строки — что произошло,
 * когда, с какого устройства и адреса (адрес — маской, как в «Устройствах»)
 * и в какой сервис. Неудачи — красным: ради них сюда и приходят.
 * $kind, $page, $fails, $continued.
 */
use App\Core\Audit;
use App\Core\Stats;
use App\Core\UserHistory;

/* Вход словами про способ: «Вход выполнен» не говорит, чем вошли. */
$titles = ['login.success' => t('Вход паролем')] + array_map(static fn($m) => t('Вход: :m', ['m' => mb_strtolower($m)]),
    array_diff_key(Stats::methods(), ['login.success' => 1]));
?>
<div class="page-head">
  <h1><?= esc(t('История')) ?></h1>
</div>

<?php if ($fails >= 3 && !$continued): ?>
<?php /* Перебор пароля — повод действовать, а не читать: обе двери рядом. */ ?>
<div class="callout callout-warning mb-16">
  <?= icon('alert-triangle', 18) ?>
  <div><?= esc(t('За неделю было :n неудачных попыток войти в аккаунт. Если это были не вы — смените пароль и завершите чужие сеансы.', ['n' => $fails])) ?>
    <div class="row gap-8 mt-8">
      <a class="btn btn-s btn-secondary" href="/profile/security"><?= esc(t('Сменить пароль')) ?></a>
      <a class="btn btn-s btn-secondary" href="/profile/sessions"><?= esc(t('Устройства')) ?></a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="tabs mb-16">
  <?php foreach (UserHistory::kinds() as $k => $label): ?>
  <a<?= $k === $kind ? ' class="active" aria-current="page"' : '' ?> href="/profile/activity<?= $k === 'all' ? '' : '?k=' . $k ?>"><?= esc($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($page['rows'] === []): ?>
  <div class="bento-card"><?= empty_state('clock', $kind === 'alerts' ? t('Неудачных попыток не было') : t('История пока пуста'), '', 'pad-16') ?></div>
<?php else: ?>
<?php /* По дням — сначала в группы, потом в разметку: так у каждого дня
         свой закрытый список, без «открыть в одном витке, закрыть в другом». */
$days = [];
foreach ($page['rows'] as $r) $days[UserHistory::dayLabel((string)$r['created_at'])][] = $r; ?>
<div class="bento-card">
  <?php foreach ($days as $label => $rows): ?>
  <div class="bc-title history-day"><?= esc($label) ?></div>
  <div class="act-list">
    <?php foreach ($rows as $r): $alarm = Audit::alarming((string)$r['event']); ?>
    <div class="act-item<?= $alarm ? ' is-alarm' : '' ?>">
      <?= $alarm ? icon('x-circle', 18, 'icon-danger') : (in_array($r['event'], Stats::LOGIN_EVENTS, true) ? icon('check-circle', 18, 'icon-ok') : icon('shield', 18, 'icon-muted')) ?>
      <div class="act-text">
        <div class="act-name"><?= esc($titles[$r['event']] ?? Audit::title((string)$r['event'])) ?><?php if (!empty($r['client_name'])): ?>
          <span class="hint"> · <?= esc((string)$r['client_name']) ?></span><?php endif; ?></div>
        <div class="hint"><?= esc(fmt_dt((string)$r['created_at'], 'H:i')) ?>
          <?php if (!empty($r['user_agent']) && $r['user_agent'] !== 'cli'): ?> · <?= esc(device_text(device_label((string)$r['user_agent']))) ?><?php endif; ?>
          <?php if (!empty($r['ip'])): ?> · <span class="mono"><?= esc(ip_masked($r['ip'])) ?></span><?php endif; ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php if ($page['more'] !== null): ?>
<div class="row mt-16">
  <a class="btn btn-secondary" href="/profile/activity?<?= esc(http_build_query(array_filter(['k' => $kind === 'all' ? null : $kind, 'before' => $page['more']]))) ?>"><?= esc(t('Раньше')) ?></a>
</div>
<?php endif; ?>
<?php endif; ?>
