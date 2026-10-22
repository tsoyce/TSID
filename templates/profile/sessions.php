<?php /**
 * Устройства (§5.1): центральные сессии, маскированный IP.
 *
 * Строка — устройство, а не сессия (v4.92): сессии одного браузера с одного
 * адреса сложены, их число — плашкой. «Завершить» закрывает все сессии этого
 * устройства; у текущего — все, кроме этой.
 */
$groups = App\Models\SessionModel::groups($sessions, $currentSessionId); ?>
<div class="page-head">
  <h1><?= esc(t('Устройства')) ?></h1>
  <?php if (count($sessions) > 1): ?>
  <form method="post" action="/profile/sessions/revoke" data-confirm="<?= esc(t('Завершить все сессии, кроме этой? Порталы получат событие выхода.')) ?>">
    <?= csrf_field() ?><input type="hidden" name="all" value="1">
    <button class="btn btn-danger-ghost" type="submit"><?= esc(t('Завершить все остальные')) ?></button>
  </form>
  <?php endif; ?>
</div>

<div class="table-wrap">
<table class="table table-cards">
  <thead><tr><th><?= esc(t('Устройство')) ?></th><th>IP</th><th><?= esc(t('Появилась')) ?></th><th><?= esc(t('Активность')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($groups as $g): $others = array_values(array_diff($g['ids'], [$currentSessionId])); ?>
    <tr>
      <td class="cell-primary cell-main">
        <?= icon($g['mobile'] ? 'phone' : 'desktop', 18, 'icon-muted') ?>
        <?= esc($g['label'] ? device_text($g['label']) : t('Устройство')) ?>
        <?php if ($g['current']): ?><span class="badge badge-ink"><?= esc(t('текущая')) ?></span><?php endif; ?>
        <?php if ($g['count'] > 1): ?><span class="badge badge-muted"><?= esc(t(':n :sessions', ['n' => $g['count'], 'sessions' => plural($g['count'], 'сессия', 'сессии', 'сессий')])) ?></span><?php endif; ?>
        <?php if ($g['remembered']): ?><span class="badge badge-muted"><?= esc(t('30 дней')) ?></span><?php endif; ?>
      </td>
      <td data-label="IP" class="mono"><?= esc(ip_masked($g['ip'])) ?></td>
      <td data-label="<?= esc(t('Появилась')) ?>"><?= esc(fmt_dt($g['created_at'])) ?></td>
      <td data-label="<?= esc(t('Активность')) ?>"><?= esc(fmt_ago($g['last_seen_at'])) ?></td>
      <td class="text-right">
        <?php if ($others !== []): ?>
        <form method="post" action="/profile/sessions/revoke">
          <?= csrf_field() ?><input type="hidden" name="session_ids" value="<?= esc(implode(',', $others)) ?>">
          <button class="btn btn-s btn-danger-ghost" type="submit"><?= esc($g['current'] ? t('Завершить остальные') : t('Завершить')) ?></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="hint mt-16"><?= esc(t('Завершение сессии разошлёт порталам событие выхода — локальные сессии там тоже закроются.')) ?></p>
