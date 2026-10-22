<?php /**
 * Обзор организации: числа — куда идти дальше, форма — что правится здесь.
 *
 * v4.91: числа — плитками во всю ширину, каждое ведёт в свой раздел. Раньше
 * это были три пилюли в колонке «Состояние» рядом с кнопкой «Выключить», и
 * «Участников: 6» спорило с «Пользователи 9» в колонке разделов: теперь оба
 * числа названы и объяснены. Выключение — тумблером в той же форме, как у
 * портала; кнопка, стоявшая здесь с 4.29, слала действие, которого нет.
 */
$oid = (int)$org['id'];
$tiles = [
    ['/people', 'users', t('Участники'), count($members), t('в составе организации')],
    ['/people', 'user', t('Люди'), $people, t('участники и те, кто ходит в её сервисы')],
    ['/projects', 'portal', t('Сервисы'), count($projects), t('принадлежат организации')],
    ['/access', 'key', t('Доступы'), count($clients), t('к чужим порталам для всех участников')],
]; ?>
<div class="bento">
  <?php foreach ($tiles as [$path, $ico, $title, $n, $sub]): ?>
  <div class="bento-card col-3 list-card stat-tile">
    <div class="bc-title"><?= icon($ico, 14) ?> <a href="/admin/orgs/<?= $oid . $path ?>" class="stat-link"><?= esc($title) ?></a></div>
    <div class="kpi-value"><?= (int)$n ?></div>
    <div class="kpi-sub"><?= esc($sub) ?></div>
  </div>
  <?php endforeach; ?>

  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Основное')) ?></div>
    <form method="post" action="/admin/orgs/<?= $oid ?>/action">
      <?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="active_present" value="1">
      <div class="grid-2">
        <?= field_text('name', t('Название'), $org['name'], ['max' => 120, 'required' => true, 'id' => 'f-org-name']) ?>
        <?= field_text('description', t('Описание'), $org['description'], ['max' => 255, 'id' => 'f-org-descr']) ?>
      </div>
      <?= field_toggle('is_active', t('Организация включена'), (int)$org['is_active'] === 1,
            t('Выключенная не даёт своим участникам входить в порталы, к которым доступ выдан через неё.')) ?>
      <?php /* Слаг не редактируется: его уже разобрали на стороне порталов,
               и смена сломала бы им разбор claim. */ ?>
      <p class="hint"><?= esc(t('Слаг :slug не меняется: он уходит порталам в claim orgs.', ['slug' => $org['slug']])) ?></p>
      <?= form_actions() ?>
    </form>
  </div>

  <?php if ($projects === []): ?>
  <div class="bento-card col-12">
    <?= empty_state('portal',
        t('У организации нет сервисов — роли, выданной на неё, действовать негде.'),
        '<a class="btn btn-primary" href="/admin/orgs/' . $oid . '/projects">' . esc(t('Добавить сервис')) . '</a>') ?>
  </div>
  <?php endif; ?>
</div>
