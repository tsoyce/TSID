<div class="bento-card">
  <div class="bc-title"><?= esc(t('Доступ к сервисам')) ?></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th><?= esc(t('Портал')) ?></th><th><?= esc(t('Статус')) ?></th><th><?= esc(t('Первый вход')) ?></th><th><?= esc(t('Последний')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($access as $a): ?>
      <tr>
        <td class="cell-main"><?= esc($a['name']) ?></td>
        <td><?= access_badge((string)$a['status']) ?></td>
        <td class="hint"><?= esc(fmt_dt($a['first_login_at'], 'd.m.Y')) ?></td>
        <td class="hint"><?= esc(fmt_ago($a['last_login_at'])) ?></td>
        <td class="row gap-4">
          <?php if ($a['status'] === 'pending'): ?>
          <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/access"><?= csrf_field() ?>
            <input type="hidden" name="client_id" value="<?= (int)$a['client_id'] ?>"><input type="hidden" name="access" value="approve">
            <button class="btn btn-s btn-primary" type="submit"><?= esc(t('Одобрить')) ?></button></form>
          <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/access"><?= csrf_field() ?>
            <input type="hidden" name="client_id" value="<?= (int)$a['client_id'] ?>"><input type="hidden" name="access" value="deny">
            <button class="btn btn-s btn-secondary" type="submit"><?= esc(t('Отклонить')) ?></button></form>
          <?php elseif ($a['status'] === 'active'): ?>
          <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/access"><?= csrf_field() ?>
            <input type="hidden" name="client_id" value="<?= (int)$a['client_id'] ?>"><input type="hidden" name="access" value="revoke">
            <button class="btn btn-s btn-danger-ghost" type="submit"><?= esc(t('Отозвать')) ?></button></form>
          <?php else: ?>
          <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/access"><?= csrf_field() ?>
            <input type="hidden" name="client_id" value="<?= (int)$a['client_id'] ?>"><input type="hidden" name="access" value="grant">
            <button class="btn btn-s btn-secondary" type="submit"><?= esc(t('Вернуть доступ')) ?></button></form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php /* Через слой полей, как везде: голый <select> с кнопкой рядом вставал
           не по линии и брал ширину от самого длинного названия портала. */ ?>
  <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/access" class="row mt-16">
    <?= csrf_field() ?>
    <input type="hidden" name="access" value="grant">
    <?php $opts = [];
          foreach ($clients as $c) $opts[(int)$c['id']] = $c['name']; ?>
    <?= field_select('client_id', t('Выдать доступ'), $opts, '', ['id' => 'f-grant-client']) ?>
    <?= field_submit(t('Выдать доступ')) ?>
  </form>
</div>
