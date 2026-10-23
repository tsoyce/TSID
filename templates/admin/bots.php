<?php /**
 * Похоже на ботов (v4.95). $suspects, $refused. См. App\Core\BotSignal.
 *
 * Сильный признак (устаревший браузер, закрытый адрес, толпа с одной подсети)
 * — галочка стоит сразу; без него человек только показан: может быть, он
 * просто ещё не дошёл до почты. Удаление — одним нажатием, без пароля: сюда
 * попадают лишь те, кто не подтвердил почту и ни разу не пришёл в портал.
 */
$sure = count(array_filter($suspects, static fn(array $s): bool => $s['sure'])); ?>
<div class="page-head">
  <h1><?= esc(t('Похоже на ботов')) ?> <span class="muted fw-400"><?= count($suspects) ?></span></h1>
  <a class="btn btn-secondary" href="/admin/blocks"><?= esc(t('Адреса')) ?></a>
</div>

<p class="hint mb-12"><?= esc(t('Сами завели аккаунт, не подтвердили почту и ни разу не пришли ни в один портал. Галочка стоит у тех, за кем есть сильный признак: браузер, которого у людей нет, закрытый адрес или ещё несколько регистраций с той же подсети.')) ?>
  <?php if ($refused > 0): ?><?= esc(t('За сутки отказано в регистрации устаревшим браузерам: :n.', ['n' => $refused])) ?><?php endif; ?></p>

<?php if ($suspects === []): ?>
  <div class="bento-card"><?= empty_state('shield-check', t('Похожих на ботов нет.')) ?></div>
<?php else: ?>
<form method="post" action="/admin/users/bots" data-bulk
      data-confirm-tpl="<?= esc(t('Удалить выбранные аккаунты (:n)? Это окончательно.')) ?>">
  <?= csrf_field() ?>
  <div class="bulk-bar mb-12">
    <span class="hint" data-bulk-count data-tpl="<?= esc(t('Выбрано: :n')) ?>"><?= esc(t('Выбрано: :n', ['n' => $sure])) ?></span>
    <button class="btn btn-danger" type="submit" name="block" value="0"><?= esc(t('Удалить')) ?></button>
    <button class="btn btn-secondary" type="submit" name="block" value="1"><?= esc(t('Удалить и закрыть их подсети')) ?></button>
  </div>
  <div class="table-wrap">
  <table class="table table-cards">
    <thead><tr>
      <th class="cell-check"><input type="checkbox" data-check-all aria-label="<?= esc(t('Выбрать всех')) ?>"<?= $sure === count($suspects) ? ' checked' : '' ?>></th>
      <th><?= esc(t('Аккаунт')) ?></th><th><?= esc(t('Почему')) ?></th><th><?= esc(t('Откуда')) ?></th><th><?= esc(t('Когда')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($suspects as $s): ?>
      <tr>
        <td class="cell-check"><input type="checkbox" name="ids[]" value="<?= (int)$s['id'] ?>"
            aria-label="<?= esc((string)$s['email']) ?>"<?= $s['sure'] ? ' checked' : '' ?>></td>
        <td data-label="<?= esc(t('Аккаунт')) ?>" class="cell-primary">
          <div class="name-line"><a class="cell-main truncate" href="/admin/users/<?= (int)$s['id'] ?>" title="<?= esc((string)$s['email']) ?>"><?= esc((string)$s['email']) ?></a></div>
          <div class="hint name-line"><span class="truncate"><?= esc(trim((string)$s['first_name'] . ' ' . (string)$s['last_name'])) ?></span></div>
        </td>
        <td data-label="<?= esc(t('Почему')) ?>"><span class="chip-row"><?php foreach ($s['why'] as $w): ?><span class="badge badge-warn"><?= esc($w) ?></span><?php endforeach; ?><?php if ($s['why'] === []): ?><span class="badge badge-muted"><?= esc(t('нет сильных признаков')) ?></span><?php endif; ?></span></td>
        <td data-label="<?= esc(t('Откуда')) ?>" class="hint"><div class="cell-stack">
          <span class="mono"><?= ip_link($s['reg_ip'] !== null ? (string)$s['reg_ip'] : null) ?></span>
          <div class="truncate"><?= esc(device_text(device_label((string)($s['reg_ua'] ?? '')))) ?></div>
        </div></td>
        <td data-label="<?= esc(t('Когда')) ?>" class="hint nowrap"><?= esc(fmt_ago((string)$s['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</form>
<?php endif; ?>
