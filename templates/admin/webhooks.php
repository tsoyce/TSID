<?php /** Webhooks (§ROADMAP v2.3): подписки сторонних систем на события SSO. */
/* Перечень событий один на оба списка — подписку правят в карточке и задают
   при создании, и разъехаться они не должны. Машинное имя показывается рядом:
   в приёмнике сверяются именно с ним. */
$eventOptions = [];
foreach ($catalog as $event => $label) $eventOptions[$event] = [$label, $event];
?>
<?php if ($freshSecret): ?>
<div class="bento-card mb-16 bordered-ink">
  <div class="bc-title"><?= esc(t('Секрет подписи — показывается один раз')) ?></div>
  <?= field_copy('hook-secret', '', $freshSecret['secret']) ?>
  <p class="hint mt-8"><?= esc(t('Приёмник должен проверять подпись так:')) ?>
  <code>hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret)</code> <?= esc(t('— сравнить с заголовком')) ?> <code>X-SSO-Signature</code> <?= esc(t('(после')) ?> <code>sha256=</code><?= esc(t('), а')) ?> <code>$timestamp</code> <?= esc(t('взять из')) ?> <code>X-TSOY-Timestamp</code><?= esc(t('. Расхождение больше пяти минут — отклоняйте запрос.')) ?></p>
</div>
<?php endif; ?>

<?php /* Стена карточек и пустое состояние внутри неё — как у порталов и
         организаций: раздел, где ничего нет, не должен выглядеть иначе, чем
         раздел, где что-то есть. */ ?>
<div class="bento">
<?php if ($hooks === []): ?>
  <div class="bento-card col-12"><?= empty_state('external',
      t('Подписчиков пока нет. Webhook пригодится, чтобы завести события SSO в CRM, аналитику или телеграм-бота администратора.'),
      '<button class="btn btn-primary" type="button" data-modal-open="hook-modal">'
      . icon('plus', 16) . ' ' . esc(t('Добавить webhook')) . '</button>') ?></div>
<?php endif; ?>
<?php foreach ($hooks as $h): ?>
  <div class="bento-card col-6">
    <div class="row-between">
      <h3 class="mb-0"><?= esc($h['name']) ?></h3>
      <?= (int)$h['is_active']
        ? '<span class="badge badge-ok">' . esc(t('активен')) . '</span>'
        : '<span class="badge badge-muted">' . esc(t('выключен')) . '</span>' ?>
    </div>
    <div class="hint mono mt-8 break-any"><?= esc($h['url']) ?></div>

    <form method="post" action="/admin/webhooks/<?= (int)$h['id'] ?>/action" class="mt-16">
      <?= csrf_field() ?><input type="hidden" name="action" value="update_events">
      <div class="bc-title"><?= esc(t('События')) ?></div>
      <?= field_checks('events', '', $eventOptions, $h['events']) ?>
      <button class="btn btn-s btn-secondary" type="submit"><?= esc(t('Сохранить подписку')) ?></button>
    </form>

    <div class="row mt-16">
      <form method="post" action="/admin/webhooks/<?= (int)$h['id'] ?>/action">
        <?= csrf_field() ?><input type="hidden" name="action" value="test">
        <button class="btn btn-s btn-secondary" type="submit"><?= esc(t('Отправить пробное')) ?></button>
      </form>
      <form method="post" action="/admin/webhooks/<?= (int)$h['id'] ?>/action">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle">
        <button class="btn btn-s btn-ghost" type="submit"><?= esc((int)$h['is_active'] ? t('Выключить') : t('Включить')) ?></button>
      </form>
      <form method="post" action="/admin/webhooks/<?= (int)$h['id'] ?>/action"
            data-confirm="<?= esc(t('Удалить webhook «:name»? История доставок тоже исчезнет.', ['name' => $h['name']])) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete">
        <button class="btn btn-s btn-danger-ghost" type="submit"><?= esc(t('Удалить')) ?></button>
      </form>
    </div>

    <?php $dl = $deliveries[(int)$h['id']] ?? []; ?>
    <?php if ($dl !== []): ?>
    <div class="bc-footer">
      <div class="bc-title"><?= esc(t('Последние доставки')) ?></div>
      <?php foreach ($dl as $d): ?>
        <div class="row gap-8 fs-13">
          <?= status_icon($d['status'] === 'sent' ? 'ok' : ($d['status'] === 'pending' ? 'warn' : 'fail'), 15) ?>
          <span class="mono"><?= esc($d['event']) ?></span>
          <span class="hint nowrap"><?= esc(fmt_ago($d['created_at'])) ?></span>
          <?php if ($d['response_code']): ?><span class="hint">HTTP <?= (int)$d['response_code'] ?></span><?php endif; ?>
          <?php if ($d['last_error']): ?><span class="hint text-danger"><?= esc($d['last_error']) ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<div class="modal-backdrop" id="hook-modal">
  <div class="modal modal-m" role="dialog" aria-modal="true">
    <div class="modal-head"><h2 class="mb-0"><?= esc(t('Новый webhook')) ?></h2>
      <button class="btn-icon btn-icon-s" type="button" data-modal-close aria-label="<?= esc(t('Закрыть')) ?>"><?= icon('x', 18) ?></button></div>
    <form method="post" action="/admin/webhooks/create">
      <?= csrf_field() ?>
      <?= field_text('name', t('Название'), '', ['max' => 120, 'required' => true,
            'placeholder' => t('Телеграм-бот администратора')]) ?>
      <?= field_text('url', t('URL приёмника'), '', ['mono' => true, 'type' => 'url', 'required' => true,
            'placeholder' => 'https://example.com/hooks/sso']) ?>
      <?= field_checks('events', t('События'), $eventOptions, []) ?>
      <button class="btn btn-primary btn-block" type="submit"><?= esc(t('Создать и получить секрет')) ?></button>
    </form>
  </div>
</div>
