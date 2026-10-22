  <?php /* Чеклист приёмки просит портал убедиться, что «выйти везде» гасит
           его сессию, а блокировка выкидывает человека. Проверить это было
           нечем: событие приходит от нас, а подделать его нельзя — подпись.
           Кнопка шлёт настоящее подписанное событие безобидного типа. */ ?>
  <?php if ($isGlobalAdmin): ?>
  <div class="row mb-16">
    <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/action">
      <?= csrf_field() ?><input type="hidden" name="action" value="test_event">
      <button class="btn btn-secondary" type="submit"<?= empty($c['backchannel_uri']) ? ' disabled' : '' ?>>
        <?= icon('activity', 16) ?> <?= esc(t('Отправить тестовое событие')) ?>
      </button>
    </form>
    <span class="hint">
      <?= empty($c['backchannel_uri'])
          ? esc(t('Сначала укажите адрес приёмника событий во вкладке «Реквизиты».'))
          : esc(t('Настоящее подписанное событие типа «test». Портал обязан ответить 200 и ничего не делать.')) ?>
    </span>
  </div>
  <?php endif; ?>
  <div class="bento-card">
  <div class="bc-title"><?= esc(t('Доставка событий')) ?></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th><?= esc(t('Событие')) ?></th><th><?= esc(t('Статус')) ?></th><th><?= esc(t('Попыток')) ?></th><th><?= esc(t('Создано')) ?></th><th><?= esc(t('Ошибка')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($events as $e): ?>
      <tr>
        <td><span class="badge badge-muted mono"><?= esc($e['event']) ?></span></td>
        <td><span class="badge <?= $e['status'] === 'sent' ? 'badge-ok' : ($e['status'] === 'failed' ? 'badge-danger' : 'badge-warn') ?>"><?= esc($e['status']) ?></span></td>
        <td class="hint"><?= (int)$e['attempts'] ?></td>
        <td class="hint"><?= esc(fmt_dt($e['created_at'], 'd.m H:i')) ?></td>
        <td class="hint"><?= esc($e['last_error'] ?? '') ?></td>
        <td>
          <?php if ($e['status'] !== 'sent'): ?>
          <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/action"><?= csrf_field() ?>
            <input type="hidden" name="action" value="retry_event"><input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
            <button class="btn btn-s btn-secondary" type="submit"><?= esc(t('Повторить')) ?></button></form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($events === []): ?><tr><td colspan="6" class="muted"><?= esc(t('Событий ещё не было')) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$c['backchannel_uri']): ?>
  <p class="hint mt-8"><?= icon('alert-triangle', 15, 'icon-warn') ?>
    <?= esc(t('Backchannel URI не задан — logout и блокировки не доедут до портала, пока его не настроят.')) ?></p>
  <?php endif; ?>
</div>
