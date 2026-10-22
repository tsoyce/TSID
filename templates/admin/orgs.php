<?php /** Организации (§ROADMAP v3.0): групповая выдача доступа к порталам. */ ?>
<div class="page-head">
  <h1><?= esc(t('Организации')) ?></h1>
  <button class="btn btn-primary" type="button" data-modal-open="org-modal">
    <?= icon('plus', 16) ?> <?= esc(t('Создать организацию')) ?>
  </button>
</div>

<?php /* v4.90: объяснение — одной строкой под заголовком, а не плашкой во всю
         ширину над списком: его читают один раз, а плашка стояла всегда. */ ?>
<p class="muted mb-16 page-lede"><?= esc(t('Что выдано организации, получают все её участники. Роли внутри неё управляют составом, а не правами в сервисах.')) ?></p>

<?php /* Стена карточек и пустое состояние внутри неё — та же раскладка, что у
         порталов. До этого организации рисовали пустоту голым текстом на сером
         фоне, а порталы — карточкой: одна и та же мысль выглядела как две
         разные страницы. */ ?>
<div class="bento mt-16">
  <?php foreach ($orgs as $o): ?>
  <?php /* Плитка общая с порталами (.list-card, v4.70): щёлкается целиком,
           «Открыть →» повторял название; статус виден, только если он не
           обычный. v4.90: знак той же формы, что у портала; слаг ушёл в
           карточку — его копируют оттуда, а в списке он лишь шумел. */ ?>
  <div class="bento-card col-4 list-card<?= (int)$o['is_active'] ? '' : ' is-dimmed' ?>">
    <div class="list-card-head"><?= org_icon_html($o, 36) ?>
      <div class="list-card-title">
        <h3 class="list-card-name"><a href="/admin/orgs/<?= (int)$o['id'] ?>"><?= esc($o['name']) ?></a></h3>
        <?php if ($o['description']): ?><div class="hint list-card-sub"><?= esc($o['description']) ?></div><?php endif; ?>
      </div>
      <?php if (!(int)$o['is_active']): ?><span class="badge badge-muted"><?= esc(t('выключена')) ?></span><?php endif; ?>
    </div>
    <div class="bc-footer list-card-foot">
      <span><?= icon('users', 14) ?> <?= (int)$o['members'] ?> <?= esc(plural((int)$o['members'], 'участник', 'участника', 'участников')) ?></span>
      <span><?= icon('portal', 14) ?> <?= (int)$o['projects'] > 0
          ? (int)$o['projects'] . ' ' . esc(plural((int)$o['projects'], 'сервис', 'сервиса', 'сервисов'))
          : esc(t('сервисов нет')) ?></span>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if ($orgs === []): ?>
  <div class="bento-card col-12"><?= empty_state('users',
      t('Организаций пока нет. Они пригодятся, когда SSO обслуживает не только ваши порталы, но и компанию с отделами.'),
      '<button class="btn btn-primary" type="button" data-modal-open="org-modal">'
      . icon('plus', 16) . ' ' . esc(t('Создать организацию')) . '</button>') ?></div>
  <?php endif; ?>
</div>

<div class="modal-backdrop" id="org-modal">
  <div class="modal modal-s" role="dialog" aria-modal="true">
    <div class="modal-head"><h2 class="mb-0"><?= esc(t('Новая организация')) ?></h2>
      <button class="btn-icon btn-icon-s" type="button" data-modal-close aria-label="<?= esc(t('Закрыть')) ?>"><?= icon('x', 18) ?></button></div>
    <form method="post" action="/admin/orgs/create">
      <?= csrf_field() ?>
      <?= field_text('name', t('Название'), '', ['max' => 120, 'required' => true,
            'placeholder' => t('ООО «Ромашка»')]) ?>
      <?= field_text('description', t('Описание'), '', ['max' => 255,
            'note' => t('(необязательно)'), 'placeholder' => t('Отдел продаж и поддержки')]) ?>
      <button class="btn btn-primary btn-block" type="submit"><?= esc(t('Создать')) ?></button>
    </form>
  </div>
</div>
