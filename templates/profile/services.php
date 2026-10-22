<?php /** Мои сервисы (§5.1): сетка порталов, статус заявки, роли, отзыв. */ ?>
<div class="page-head"><h1><?= esc(t('Мои сервисы')) ?></h1></div>

<div class="bento">
<?php foreach ($services as $s): ?>
  <?php /* Плитка щёлкается целиком и ведёт в сервис (v4.71) — как плитки
           порталов в админке. «Подключён» стояло на каждой карточке и ничего
           не различало: видно только заявку и закрытый доступ. */ ?>
  <div class="bento-card col-4 list-card">
    <div class="list-card-head"><?= client_icon_html(['id' => $s['client_id'], 'icon_path' => $s['icon_path'], 'name' => $s['name'], 'slug' => $s['slug']], 28) ?>
      <h3 class="list-card-name"><a href="<?= esc($s['base_url']) ?>" rel="noopener"><?= esc($s['name']) ?></a></h3>
      <?php if ($s['status'] === 'pending'): ?><span class="badge badge-warn"><?= esc(t('заявка на рассмотрении')) ?></span>
      <?php elseif ($s['status'] !== 'active'): ?><span class="badge badge-danger"><?= esc(t('доступ закрыт')) ?></span><?php endif; ?>
    </div>
    <?php if (!empty($rolesByClient[(int)$s['client_id']])): ?>
      <div class="mt-8"><?php foreach ($rolesByClient[(int)$s['client_id']] as $rn): ?>
        <span class="badge badge-ink"><?= esc($rn) ?></span>
      <?php endforeach; ?></div>
    <?php endif; ?>
    <div class="bc-footer row">
      <span class="muted"><?= $s['last_login_at'] ? esc(t('вход :ago', ['ago' => fmt_ago($s['last_login_at'])])) : esc(t('ещё не входили')) ?></span>
      <?php if (!(int)$s['is_trusted']): ?>
      <form method="post" action="/profile/services/revoke" data-confirm="<?= esc(t('Отозвать доступ «:name»?', ['name' => $s['name']])) ?>">
        <?= csrf_field() ?><input type="hidden" name="client_id" value="<?= (int)$s['client_id'] ?>">
        <button class="btn btn-s btn-danger-ghost list-card-action" type="submit"><?= esc(t('Отозвать доступ')) ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php foreach ($others as $c): ?>
  <div class="bento-card col-4 list-card is-dimmed">
    <div class="list-card-head">
      <h3 class="list-card-name"><a href="<?= esc($c['base_url']) ?>" rel="noopener"><?= esc($c['name']) ?></a></h3>
      <span class="badge badge-muted"><?= esc(t('ещё не подключён')) ?></span>
    </div>
    <?php if ($c['description']): ?><p class="muted mt-8"><?= esc($c['description']) ?></p><?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($services === [] && $others === []): ?>
  <div class="bento-card col-12"><?= empty_state('portal', t('Порталы ещё не зарегистрированы в SSO.')) ?></div>
<?php endif; ?>
</div>
