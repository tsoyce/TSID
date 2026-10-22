<?php /**
 * Роли (§5.2, §9.2): системные и роли сервисов; системные защищены.
 *
 * Группами, а не одной лентой. Раньше все роли шли подряд одной таблицей, и на
 * десяти сервисах со своими ролями это превращалось в несколько экранов, где
 * системные — те, которыми управляют, — тонули между чужими. Роли сервиса ему
 * же и принадлежат: пусть лежат в его группе, свёрнутой по умолчанию.
 */
/* v4.93: название впереди, машинное имя — строкой под ним, как в «Ролях»
   человека. Раньше первой колонкой шёл моноширинный слаг, а название — второй,
   и глаз искал «Админ портала» среди «client_admin». Число людей — ссылкой
   на список с этой ролью; «Права» убраны — название и есть ссылка. */
$row = static function (array $r): string {
    $n = (int)$r['users_count'];
    ob_start(); ?>
    <tr>
      <td class="cell-primary">
        <a class="cell-main" href="/admin/roles/<?= (int)$r['id'] ?>"><?= esc($r['name']) ?></a>
        <?php /* Отмечено исключение, а не норма (v4.70). «Системная» стояла на
                 каждой строке группы «Роли системы» — там все встроенные, и
                 метка не различала ничего. Различать стоит роль, заведённую
                 вручную: её можно удалить, и завёл её кто-то из людей. */ ?>
        <?php if (!$r['is_system'] && $r['client_id'] === null): ?><span class="badge badge-muted"><?= esc(t('своя')) ?></span><?php endif; ?>
        <div class="hint"><span class="mono"><?= esc(($r['client_slug'] ? $r['client_slug'] . ':' : '') . $r['slug']) ?></span><?= $r['description'] ? ' · ' . esc($r['description']) : '' ?></div>
      </td>
      <td data-label="<?= esc(t('Людей')) ?>" class="nowrap"><?php if ($n > 0): ?><a href="/admin/users?role=<?= (int)$r['id'] ?>"><?= esc(t(':n :people', ['n' => $n, 'people' => plural($n, 'человек', 'человека', 'человек')])) ?></a><?php else: ?><span class="hint"><?= esc(t('никого')) ?></span><?php endif; ?></td>
      <?php /* Уровень — сводка для сервисов, не право. Показываем в списке:
               по нему чаще всего и ищут «а какая роль даёт что». */ ?>
      <td data-label="<?= esc(t('Уровень')) ?>" class="hint"><?= (int)($r['access_level'] ?? 20) ?> ·
        <?= esc(App\Core\AccessLevel::title((int)($r['access_level'] ?? 20))) ?></td>
      <?php /* Без перевода строки до условия: у встроенной роли ячейка должна
               быть пустой, иначе на телефоне под карточкой висит отступ. */ ?>
      <td class="text-right"><?php if (!$r['is_system']): ?>
        <form method="post" action="/admin/roles/<?= (int)$r['id'] ?>/delete"
              data-confirm="<?= esc(t('Удалить роль «:slug»? Она снимется у всех.', ['slug' => $r['slug']])) ?>">
          <?= csrf_field() ?><?= back_field() ?>
          <button class="btn btn-s btn-danger-ghost" type="submit"><?= esc(t('Удалить')) ?></button>
        </form>
        <?php endif; ?></td>
    </tr>
    <?php return (string)ob_get_clean();
};

$head = '<thead><tr><th>' . esc(t('Роль')) . '</th><th>' . esc(t('Людей')) . '</th>'
      . '<th>' . esc(t('Уровень')) . '</th><th></th></tr></thead>';
?>
<div class="page-head">
  <h1><?= esc(t('Роли')) ?></h1>
  <button class="btn btn-primary" type="button" data-modal-open="role-modal"><?= esc(t('Создать роль')) ?></button>
</div>

<?php if ($system !== []): ?>
<div class="bento-card">
  <div class="bc-title"><?= esc(t('Роли системы')) ?></div>
  <p class="hint mb-16"><?= esc(t('Действуют во всей установке. Выдаются в области: на систему, на организацию или на один сервис — от этого меняется охват, а не смысл.')) ?></p>
  <div class="table-wrap">
    <table class="table table-cards">
      <?= $head ?>
      <tbody><?php foreach ($system as $r) echo $row($r); ?></tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php /* Каждый сервис — своей группой. Свёрнуто: в них заходят, когда занялись
         именно этим сервисом, а не листая общий список. */ ?>
<?php foreach ($byClient as $cid => $grp): ?>
<details class="bento-card disclosure mt-16">
  <summary>
    <span class="bc-title mb-0"><?= esc($grp['name']) ?></span>
    <span class="hint"><?= esc(t('своих ролей: :n', ['n' => count($grp['roles'])])) ?></span>
  </summary>
  <div class="table-wrap mt-8">
    <table class="table table-cards">
      <?= $head ?>
      <tbody><?php foreach ($grp['roles'] as $r) echo $row($r); ?></tbody>
    </table>
  </div>
  <p class="hint mt-8">
    <a href="/admin/clients/<?= (int)$cid ?>/roles"><?= esc(t('Управлять ролями этого сервиса →')) ?></a>
  </p>
</details>
<?php endforeach; ?>

<?php if ($system === [] && $byClient === []): ?>
  <?= empty_state('role', t('Ролей пока нет.')) ?>
<?php endif; ?>

<div class="modal-backdrop" id="role-modal">
  <div class="modal modal-s" role="dialog" aria-modal="true">
    <div class="modal-head"><h2 class="mb-0"><?= esc(t('Новая роль')) ?></h2>
      <button class="btn-icon btn-icon-s" type="button" data-modal-close aria-label="<?= esc(t('Закрыть')) ?>"><?= icon('x', 18) ?></button></div>
    <form method="post" action="/admin/roles/create">
      <?= csrf_field() ?>
      <?php
      $scopeOptions = $isGlobalAdmin ? ['' => t('Глобальная')] : [];
      foreach ($clients as $c) $scopeOptions[(int)$c['id']] = $c['name'];
      ?>
      <?= field_select('client_id', t('Чья роль'), $scopeOptions) ?>
      <div class="grid-2">
        <?= field_text('slug', t('Слаг'), '', [
              'mono' => true, 'placeholder' => 'editor', 'required' => true, 'max' => 64]) ?>
        <?= field_text('name', t('Название'), '', [
              'placeholder' => t('Редактор'), 'required' => true, 'max' => 120]) ?>
      </div>
      <?= field_text('description', t('Описание'), '', ['max' => 255]) ?>
      <button class="btn btn-primary btn-block" type="submit"><?= esc(t('Создать роль')) ?></button>
    </form>
    <p class="hint mt-8"><?= esc(t('Роль сервиса поедет в claim как')) ?> <code><?= esc(t('слаг-портала:роль')) ?></code> <?= esc(t('(§9.3). SSO ими не пользуется — просто доставляет.')) ?></p>
  </div>
</div>
