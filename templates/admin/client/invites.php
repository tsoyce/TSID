  <?php if ($freshInvite): ?>
  <div class="bento-card mb-16 bordered-ink">
    <div class="bc-title"><?= esc(t('Ссылка создана — скопируйте её сейчас')) ?></div>
    <?= field_copy('fresh-invite', '', $freshInvite, [
          'hint' => t('Отправьте её человеку любым удобным способом. Принять — один клик, даже если аккаунта ещё нет: после регистрации доступ выдастся автоматически.')]) ?>
  </div>
  <?php endif; ?>

  <div class="bento">
    <div class="bento-card col-4">
      <div class="bc-title"><?= esc(t('Новое приглашение')) ?></div>
      <?php if ($c['access_policy'] !== 'invite'): ?>
        <p class="hint mb-16"><?= icon('info', 15, 'icon-muted') ?>
        <?= esc(t('Политика доступа портала — «:policy»: люди и так смогут войти сами. Приглашения нужны прежде всего для политики invite, но работают в любой — они сразу выдают доступ и роль.', ['policy' => $c['access_policy']])) ?></p>
      <?php endif; ?>
      <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/action">
        <?= csrf_field() ?><input type="hidden" name="action" value="create_invite">
        <?php
        $roleOptions = ['' => t('— без роли')];
        foreach ($roles as $r) $roleOptions[(int)$r['id']] = $r['slug'] . ' — ' . $r['name'];
        ?>
        <?= field_select('invite_role', t('Роль при принятии'), $roleOptions, '', [
              'note' => t('(необязательно)')]) ?>
        <?= field_text('invite_email', t('Только для email'), '', [
              'type' => 'email', 'placeholder' => 'ivan@example.ru',
              'note' => t('(пусто = для любого)')]) ?>
        <div class="grid-2">
          <?= field_text('invite_uses', t('Использований'), '1', [
                'type' => 'number', 'low' => 1, 'high' => 1000]) ?>
          <?= field_text('invite_days', t('Срок, дней'), '7', [
                'type' => 'number', 'low' => 1, 'high' => 365]) ?>
        </div>
        <?= field_text('invite_note', t('Заметка'), '', [
              'max' => 200, 'placeholder' => t('Доступ для отдела продаж'),
              'note' => t('(увидит приглашённый)')]) ?>
        <button class="btn btn-primary btn-block" type="submit">
          <?= icon('plus', 16) ?> <?= esc(t('Создать ссылку')) ?>
        </button>
      </form>
    </div>

    <div class="bento-card col-8">
      <div class="bc-title"><?= esc(t('Выданные приглашения')) ?></div>
      <?php if ($invites === []): ?>
        <?= empty_state('mail', t('Приглашений пока нет.')) ?>
      <?php else: ?>
      <div class="table-wrap">
        <table class="table table-cards">
          <thead><tr><th><?= esc(t('Кому / заметка')) ?></th><th><?= esc(t('Роль')) ?></th><th><?= esc(t('Использовано')) ?></th><th><?= esc(t('Действует до')) ?></th><th><?= esc(t('Статус')) ?></th><th></th></tr></thead>
          <tbody>
          <?php foreach ($invites as $i): [$cls, $label] = App\Models\Invite::status($i); ?>
            <tr>
              <td data-label="<?= esc(t('Кому')) ?>" class="cell-main">
                <?= $i['email'] ? esc($i['email']) : '<span class="hint">' . esc(t('для любого')) . '</span>' ?>
                <?php if ($i['note']): ?><div class="hint"><?= esc($i['note']) ?></div><?php endif; ?>
              </td>
              <td data-label="<?= esc(t('Роль')) ?>" class="hint"><?= $i['role_slug'] ? esc($i['role_slug']) : '—' ?></td>
              <td data-label="<?= esc(t('Использовано')) ?>"><?= (int)$i['used'] ?> / <?= (int)$i['max_uses'] ?></td>
              <td data-label="<?= esc(t('Действует до')) ?>" class="hint nowrap"><?= esc(fmt_dt($i['expires_at'], 'd.m.Y H:i')) ?></td>
              <td data-label="<?= esc(t('Статус')) ?>"><span class="badge <?= $cls ?>"><?= esc(t($label)) ?></span></td>
              <td>
                <?php if ($label === 'активен'): ?>
                <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/action"
                      data-confirm="<?= esc(t('Отозвать приглашение? Ссылка перестанет работать.')) ?>">
                  <?= csrf_field() ?><input type="hidden" name="action" value="revoke_invite">
                  <input type="hidden" name="invite_id" value="<?= (int)$i['id'] ?>">
                  <button class="btn btn-s btn-danger-ghost" type="submit"><?= esc(t('Отозвать')) ?></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
