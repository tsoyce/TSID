<?php /**
 * Живые сессии человека.
 *
 * Содержимое раздела лежит в карточке — как во всех соседних. Раньше таблица
 * стояла на странице голой, и раздел выглядел иначе, чем «Роли» или «Люди»
 * рядом: одна и та же оболочка, а внутри то карточка, то ничего.
 *
 * v4.92: строка — устройство (браузер с одного адреса), а не сессия: у того,
 * кто входит ссылками из писем, их были десятки одинаковых. У каждой строки —
 * «Завершить», у своей текущей — только остальные; «Разлогинить везде» —
 * под списком, чтобы не стоять первым, что видно на странице.
 */
$current = (int)(App\Core\Auth::sessionRecord()['id'] ?? 0);
$groups = App\Models\SessionModel::groups($sessions, $current);
?>
<div class="bento-card">
  <div class="bc-title"><?= esc(t('Активные сессии')) ?></div>
  <?php if ($sessions === []): ?>
    <?= empty_state('desktop', t('Активных сессий нет — человек нигде не вошёл.'), '', 'pad-16') ?>
  <?php else: ?>
  <p class="hint mb-12"><?= esc(t(':n :sessions на :places :devices', [
      'n' => count($sessions), 'sessions' => plural(count($sessions), 'сессия', 'сессии', 'сессий'),
      'places' => count($groups), 'devices' => plural(count($groups), 'устройстве', 'устройствах', 'устройствах')])) ?></p>
  <div class="table-wrap">
    <table class="table table-cards">
      <thead><tr><th><?= esc(t('Устройство')) ?></th><th>IP</th><th><?= esc(t('Активность')) ?></th><th><?= esc(t('Впервые')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($groups as $g): $others = array_values(array_diff($g['ids'], [$current])); ?>
      <tr>
        <td class="cell-primary cell-main">
          <?= icon($g['mobile'] ? 'phone' : 'desktop', 18, 'icon-muted') ?>
          <?= esc($g['label'] ? device_text($g['label']) : t('Устройство')) ?>
          <?php if ($g['current']): ?><span class="badge badge-ink"><?= esc(t('ваша текущая')) ?></span><?php endif; ?>
          <?php if ($g['count'] > 1): ?><span class="badge badge-muted"><?= esc(t(':n :sessions', ['n' => $g['count'], 'sessions' => plural($g['count'], 'сессия', 'сессии', 'сессий')])) ?></span><?php endif; ?>
        </td>
        <td data-label="IP" class="mono"><?= esc(ip_str($g['ip'])) ?></td>
        <td data-label="<?= esc(t('Активность')) ?>" class="hint"><?= esc(fmt_ago($g['last_seen_at'])) ?></td>
        <td data-label="<?= esc(t('Впервые')) ?>" class="hint"><?= esc(fmt_dt($g['created_at'])) ?></td>
        <td class="text-right">
          <?php if ($isGlobalAdmin && $others !== []): ?>
          <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/action">
            <?= csrf_field() ?><input type="hidden" name="action" value="logout_sessions">
            <input type="hidden" name="session_ids" value="<?= esc(implode(',', $others)) ?>">
            <button class="btn btn-s btn-ghost" type="submit"><?= esc($g['current'] ? t('Завершить остальные') : t('Завершить')) ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($isGlobalAdmin): ?>
  <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/action" class="row mt-16"
        data-confirm="<?= esc(t('Разлогинить пользователя на всех устройствах и порталах?')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="logout_everywhere">
    <button class="btn btn-danger-ghost" type="submit"><?= esc(t('Разлогинить везде')) ?></button>
    <span class="hint"><?= esc(t('Порталы получат событие выхода и закроют свои сессии.')) ?></span>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</div>
