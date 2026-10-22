<?php /** Порталы (§5.2) + мастер «Подключить сервис» (§6.1, шаг 1 — анкета). */ ?>
<div class="page-head">
  <h1><?= esc(t('Порталы')) ?></h1>
  <?php if ($canCreate): ?>
  <button class="btn btn-primary" type="button" data-modal-open="wizard">
    <?= icon('plus', 16) ?> <?= esc(t('Подключить сервис')) ?>
  </button>
  <?php endif; ?>
</div>

<?php /* Плитка — та же, что у организаций (.list-card): щёлкается целиком,
         поэтому отдельной ссылки «Карточка →» под ней нет — она повторяла
         название (v4.70).

         Метки — только отличия от обычного портала. Раньше «first-party» и
         «open» стояли на каждой карточке по-английски и машинными словами:
         метка, которая есть у всех, ничего не различает, и особенный портал
         в списке было не найти. Так же со статусом: «активен» — норма, видно
         только «выключен». */ ?>
<?php /* v4.90: плитка отвечает на вопросы о портале, а не пересказывает его
         реквизиты. Под именем — адрес без протокола и организация-владелец;
         метки — как входят и чем портал отличается от обычного; внизу —
         сколько людей, когда входили и не застряли ли события. */ ?>
<div class="bento">
<?php foreach ($clients as $c):
    $tags = [];
    $display = ['popup' => t('вход окном поверх сайта'), 'window' => t('вход отдельным окном'), 'page' => t('вход страницей')];
    if (isset($display[(string)($c['login_display'] ?? '')])) $tags[] = ['badge-ink', $display[(string)$c['login_display']]];
    if ($c['access_policy'] !== 'open') $tags[] = ['badge-muted', \App\Models\Client::policyLabel((string)$c['access_policy'])];
    if ($c['require_2fa']) $tags[] = ['badge-muted', t('нужен 2FA')];
    if (!$c['is_trusted']) $tags[] = ['badge-muted', t('сторонний')];
    if ($c['same_site_family']) $tags[] = ['badge-muted', t('родственный домен')]; ?>
  <div class="bento-card col-4 list-card<?= $c['is_active'] ? '' : ' is-dimmed' ?>">
    <div class="list-card-head"><?= client_icon_html($c, 36) ?>
      <div class="list-card-title">
        <h3 class="list-card-name"><a href="/admin/clients/<?= (int)$c['id'] ?>"><?= esc($c['name']) ?></a></h3>
        <div class="hint list-card-sub"><?= esc(host_label((string)$c['base_url'])) ?><?= !empty($c['org_name']) ? ' · ' . esc($c['org_name']) : '' ?></div>
      </div>
      <?php if (!$c['is_active']): ?><span class="badge badge-muted"><?= esc(t('выключен')) ?></span><?php endif; ?>
    </div>
    <?php if ($tags !== []): ?>
    <div class="list-card-chips">
      <?php foreach ($tags as [$cls, $label]): ?><span class="badge <?= $cls ?>"><?= esc($label) ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="bc-footer list-card-foot">
      <span><?= icon('users', 14) ?> <?= (int)$c['users_count'] > 0
          ? (int)$c['users_count'] . ' ' . esc(plural((int)$c['users_count'], 'человек', 'человека', 'человек'))
          : esc(t('пока никого')) ?></span>
      <?php if ($c['last_activity']): ?><span><?= icon('clock', 14) ?> <?= esc(t('вход :ago', ['ago' => fmt_ago($c['last_activity'])])) ?></span><?php endif; ?>
      <?php if ((int)$c['events_stuck'] > 0): ?><span class="is-warn"><?= icon('alert-triangle', 14) ?>
        <?= (int)$c['events_stuck'] ?> <?= esc(plural((int)$c['events_stuck'], 'событие не доставлено', 'события не доставлены', 'событий не доставлено')) ?></span><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php if ($clients === []): ?>
  <div class="bento-card col-12"><?= empty_state('portal',
      t('Порталов пока нет. Подключение — один адрес, остальное подставим сами.'),
      $canCreate ? '<button class="btn btn-primary" type="button" data-modal-open="wizard">'
          . icon('plus', 16) . ' ' . esc(t('Подключить сервис')) . '</button>' : '') ?></div>
<?php endif; ?>
</div>

<?php if ($canCreate): ?>
<?php /* Мастер общий с разделом «Сервисы» организации: см. _client_wizard. */ ?>
<?= App\Core\View::fetch('admin/_client_wizard', ['wizardOrgId' => $wizardOrgId]) ?>
<?php endif; ?>
