<?php /**
 * Таблица журнала активности (v4.72) — одна на вкладку «Запросы», страницу
 * адреса и раздел «Активность» человека.
 * $rows, $showUser, $showIp.
 *
 * Действие — словами, адрес — мелко под ним: по словам читают, по адресу
 * сверяют. Отказы (4xx) и сбои (5xx) видны цветом ответа: ради них журнал
 * чаще всего и открывают — куда стучались без прав.
 */
use App\Core\ActivityLog;
?>
<?php if ($rows === []): ?>
  <p class="hint mb-0"><?= esc(t('Записей нет. Журнал пишется с версии 4.72 и хранится по сроку из «Политик входа».')) ?></p>
<?php else: ?>
<div class="table-wrap borderless">
  <table class="table table-cards">
    <thead><tr>
      <th><?= esc(t('Когда')) ?></th><th><?= esc(t('Действие')) ?></th><th><?= esc(t('Ответ')) ?></th>
      <?php if ($showUser): ?><th><?= esc(t('Кто')) ?></th><?php endif; ?>
      <?php if ($showIp): ?><th>IP</th><?php endif; ?>
      <th><?= esc(t('Устройство')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $st = (int)$r['status'];
      $cls = $st >= 500 ? 'badge-danger' : ($st >= 400 ? 'badge-warn' : 'badge-muted'); ?>
      <tr>
        <td data-label="<?= esc(t('Когда')) ?>" class="hint nowrap"><?= esc(fmt_dt((string)$r['created_at'], 'd.m H:i:s')) ?></td>
        <td data-label="<?= esc(t('Действие')) ?>"><?= esc(ActivityLog::label((string)$r['method'], (string)$r['path'])) ?>
          <div class="hint mono"><?= esc($r['method'] . ' ' . $r['path'] . ($r['query_keys'] ? '?' . $r['query_keys'] : '')) ?></div></td>
        <td data-label="<?= esc(t('Ответ')) ?>"><span class="badge <?= $cls ?> mono"><?= $st ?></span></td>
        <?php if ($showUser): ?>
        <td data-label="<?= esc(t('Кто')) ?>" class="hint"><?php if ($r['user_id']): ?>
          <a href="/admin/users/<?= (int)$r['user_id'] ?>/activity"><?= esc((string)($r['user_email'] ?? ('#' . $r['user_id']))) ?></a>
          <?php else: ?><?= esc(t('не вошёл')) ?><?php endif; ?></td>
        <?php endif; ?>
        <?php if ($showIp): ?><td data-label="IP" class="hint"><?= ip_link($r['ip']) ?></td><?php endif; ?>
        <td data-label="<?= esc(t('Устройство')) ?>" class="hint"><?= esc(device_text(device_label((string)($r['user_agent'] ?? '')))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
