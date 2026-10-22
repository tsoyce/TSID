<?php /**
 * Сервисы организации — то, чем она ВЛАДЕЕТ.
 *
 * Не путать с «Доступами»: там чужие порталы, куда пускают её участников.
 * Владение отвечает на вопрос «чей проект», допуск — «кого туда пускают», и
 * роль, выданная на организацию, действует именно во владении.
 */ ?>
<?php /* Завести сервис — здесь, а не «где-то в общем списке».
         Обычный порядок работы именно такой: администратор стоит в своей
         организации и заводит ей сервис. Раньше кнопки тут не было вовсе, а в
         общем списке порталов она гасла, стоило выбрать организацию, — то есть
         ровно в том положении, из которого её и ищут. */ ?>
<?php if ($canCreate): ?>
<div class="row-between mb-16">
  <p class="muted mb-0"><?= esc(t('Сервис, заведённый отсюда, сразу принадлежит этой организации.')) ?></p>
  <button class="btn btn-primary" type="button" data-modal-open="wizard">
    <?= icon('plus', 16) ?> <?= esc(t('Подключить сервис')) ?>
  </button>
</div>
<?php endif; ?>

<?php if ($projects === []): ?>
  <?= empty_state('portal', t('Сервисов пока нет.'),
      $canCreate
        ? '<button class="btn btn-primary" type="button" data-modal-open="wizard">'
          . icon('plus', 16) . ' ' . esc(t('Подключить сервис')) . '</button>'
        : esc(t('Присоедините существующий портал ниже или укажите организацию в карточке самого портала.'))) ?>
<?php else: ?>
<div class="bento-card">
  <div class="bc-title"><?= esc(t('Свои сервисы')) ?></div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr>
      <th><?= esc(t('Сервис')) ?></th><th><?= esc(t('Адрес')) ?></th>
      <th><?= esc(t('Людей')) ?></th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($projects as $p): ?>
    <tr>
      <td><div class="row gap-8"><?= client_icon_html($p, 24) ?>
        <a href="/admin/clients/<?= (int)$p['id'] ?>"><?= esc($p['name']) ?></a></div></td>
      <td class="hint mono"><?= esc(url_label((string)$p['base_url'])) ?></td>
      <td class="hint"><?= (int)App\Models\Client::usersCount((int)$p['id']) ?></td>
      <td>
        <form method="post" action="/admin/orgs/<?= (int)$org['id'] ?>/action"
              data-confirm="<?= esc(t('Отсоединить сервис от организации? Роли, выданные на организацию, перестанут в нём действовать.')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="project_detach">
          <input type="hidden" name="client_id" value="<?= (int)$p['id'] ?>">
          <button class="btn btn-ghost btn-s" type="submit"><?= esc(t('Отсоединить')) ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="bento-card mt-16">
  <div class="bc-title"><?= esc(t('Присоединить сервис')) ?></div>
  <?php if ($free === []): ?>
    <p class="muted"><?= esc(t('Свободных порталов нет: каждый уже принадлежит какой-то организации. Забрать чужой можно только в карточке самого портала — мимоходом, из списка, такое делать нельзя.')) ?></p>
  <?php else: ?>
  <form method="post" action="/admin/orgs/<?= (int)$org['id'] ?>/action" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="project_attach">
    <?php $opts = ['' => t('— выберите портал')];
          foreach ($free as $c) $opts[(int)$c['id']] = $c['name'] . ' · ' . url_label((string)$c['base_url']); ?>
    <?= field_select('client_id', t('Ничей портал'), $opts, '') ?>
    <?= field_submit(t('Присоединить')) ?>
  </form>
  <p class="hint mt-8"><?= esc(t('Показаны только ничьи порталы. Роль, выданная на организацию, начнёт действовать в присоединённом сразу.')) ?></p>
  <?php endif; ?>
</div>

<?php if ($canCreate): ?>
<?= App\Core\View::fetch('admin/_client_wizard', ['wizardOrgId' => (int)$org['id']]) ?>
<?php endif; ?>
