<?php /**
 * Роли сервиса — только сами роли: какие есть, какого уровня, как называются
 * на портале.
 *
 * Кому что выдано — в «Пользователях», в строке человека (v4.68). Здесь стояла
 * вторая копия той же выдачи: таблица «кому что» со своим списком людей. У
 * администратора было два места для одного действия, а раздел ролей был самым
 * тяжёлым в карточке — замечание владельца 12.09. Связь с порталом (манифест,
 * тема входа) переехала в «Подключение», перенос людей — в «Пользователей».
 */ ?>
<?php /* Роли сервиса — таблицей, а не рядом плашек.
         Плашки отвечали ровно на один вопрос: «какие роли есть». Не отвечали:
         какого они уровня, кому выданы, как завести новую и как убрать
         ненужную. Завести можно было только на общей странице ролей — то есть
         уйдя из карточки сервиса, о котором и речь. */ ?>
<div class="bento-card">
  <div class="bc-title"><?= esc(t('Роли этого сервиса')) ?></div>
  <?php if ($roles === []): ?>
    <?= empty_state('role', t('Своих ролей у сервиса пока нет.'),
        '', 'pad-16') ?>
    <p class="hint mt-8"><?= esc(t('Роль сервиса — метка, которую портал получит в claim roles. Заведите её здесь или дождитесь, пока портал расскажет о своих сам.')) ?></p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <th><?= esc(t('Роль')) ?></th><th><?= esc(t('Уровень')) ?></th>
        <th><?= esc(t('Выдана')) ?></th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($roles as $r): $lvl = (int)($r['access_level'] ?? 0); ?>
      <tr>
        <td>
          <a href="/admin/roles/<?= (int)$r['id'] ?>"><?= esc($r['name']) ?></a>
          <div class="hint mono"><?= esc($c['slug']) ?>:<?= esc($r['slug']) ?></div>
        </td>
        <td class="hint"><?= $lvl ?> · <?= esc(App\Core\AccessLevel::title($lvl)) ?></td>
        <td class="hint"><?php $n = (int)($roleGrantCount[(int)$r['id']] ?? 0); ?>
          <?= $n > 0 ? esc(t(':n :word', ['n' => $n,
                'word' => plural($n, 'человеку', 'людям', 'людям')]))
                     : '<span class="muted">' . esc(t('никому')) . '</span>' ?></td>
        <td>
          <?php if (empty($r['is_system'])): ?>
          <form method="post" action="/admin/roles/<?= (int)$r['id'] ?>/delete"
                data-confirm="<?= esc(t('Удалить роль «:slug»? У тех, кому она выдана, она пропадёт.', ['slug' => $r['slug']])) ?>">
            <?= csrf_field() ?><?= back_field() ?>
            <button class="btn btn-ghost btn-s" type="submit"><?= esc(t('Удалить')) ?></button>
          </form>
          <?php else: ?>
          <span class="hint"><?= esc(t('системная')) ?></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php /* Завести роль — здесь же. Уровень не спрашиваем: он выводится из
           имени по справочнику, как и у роли, приехавшей из портала. Так
           «editor» везде значит одно и то же, кто бы его ни завёл. */ ?>
  <form method="post" action="/admin/roles/create" class="row mt-16">
    <?= csrf_field() ?><?= back_field() ?>
    <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
    <?= field_text('slug', t('Машинное имя'), '', [
          'mono' => true, 'required' => true, 'placeholder' => 'editor', 'id' => 'f-newrole-slug']) ?>
    <?= field_text('name', t('Название'), '', [
          'required' => true, 'placeholder' => t('Редактор'), 'id' => 'f-newrole-name']) ?>
    <?= field_submit(t('Завести роль')) ?>
  </form>
  <p class="hint mt-8"><?= esc(t('Уровень определится по имени: «admin» — 70, «editor» — 60, «moderator» — 55, незнакомое — 20. Прав в самом :app роль сервиса не получает никогда.',
      ['app' => App\Core\Setting::appName()])) ?></p>
  <p class="hint"><?= esc(t('Выдать роль человеку — в разделе «Пользователи», в его строке.')) ?>
    <a href="/admin/clients/<?= (int)$c['id'] ?>/people"><?= esc(t('Открыть «Пользователей»')) ?></a></p>
</div>

<?php if ($seen !== []): ?>
<?php /* Роль, о которой сообщил портал, заводится одной кнопкой и сразу с
         уровнем по имени (ROLES.md §4): «blog_editor» — редактор, 60,
         «admin» — администратор сервиса, 70, незнакомое — 20.

         У заведённой роли показан её НАСТОЯЩИЙ уровень — тот, что уезжает
         порталу. До v4.61 здесь во всех строках стоял уровень «по имени», и у
         заведённой роли он мог не совпадать с действующим. Настоящий ниже
         того, что значит имя, — кнопка «выставить»: молча переписывать
         нельзя, уровень меняет то, что портал видит о людях. Так было с
         «admin» Архива: заведён до v4.61 с двадцаткой. */ ?>
<?php $known = array_column($roles, null, 'slug'); ?>
<div class="bento-card mt-16">
  <div class="bc-title"><?= esc(t('Портал сообщает о своих ролях')) ?></div>
  <div class="table-wrap">
    <table class="table">
      <tbody>
      <?php foreach ($seen as $rs):
          $byName = App\Core\RoleCatalog::levelForIncoming((string)$rs['slug']);
          $mine = $known[$rs['slug']] ?? null;
          $lvl = $mine !== null ? (int)($mine['access_level'] ?? 0) : $byName; ?>
      <tr>
        <td><span class="badge badge-muted mono"><?= esc($rs['slug']) ?></span>
          <?php if ($rs['title']): ?><span class="hint"><?= esc($rs['title']) ?></span><?php endif; ?></td>
        <td class="hint"><?= (int)$lvl ?> · <?= esc(App\Core\AccessLevel::title($lvl)) ?></td>
        <td>
          <?php if ($mine !== null && $lvl < $byName): ?>
          <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/action">
            <?= csrf_field() ?><input type="hidden" name="action" value="role_relevel">
            <input type="hidden" name="slug" value="<?= esc($rs['slug']) ?>">
            <button class="btn btn-secondary btn-s" type="submit"
                    title="<?= esc(t('По имени роли — :tier', ['tier' => App\Core\AccessLevel::title($byName)])) ?>"><?= esc(t('Выставить :n', ['n' => $byName])) ?></button>
          </form>
          <?php elseif ($mine !== null): ?>
            <span class="hint"><?= esc(t('уже заведена')) ?></span>
          <?php else: ?>
          <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/action">
            <?= csrf_field() ?><input type="hidden" name="action" value="role_adopt">
            <input type="hidden" name="slug" value="<?= esc($rs['slug']) ?>">
            <button class="btn btn-secondary btn-s" type="submit"><?= esc(t('Завести роль')) ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint mt-8"><?= esc(t('Список присылает сам портал — SSO спрашивает его раз в час.')) ?>
    <a href="/admin/clients/<?= (int)$c['id'] ?>/artifacts#portal-link"><?= esc(t('Спросить сейчас — в «Подключении»')) ?></a></p>
</div>
<?php endif; ?>

<?php /* Карта ролей: как роль SSO называется на стороне портала. Пустое поле —
         роль этому порталу не передаётся вовсе; набор ролей инсталляции его
         не касается. Пустая карта целиком означает «не настраивали»: тогда
         портал получает слаги как раньше. */ ?>
<?php if (App\Models\ClientRoleMap::ready()):
  $roleMap = App\Models\ClientRoleMap::forClient((int)$c['id']);
  /* Подсказка, а не значение: карта управляет доступами, и заполнять её
     автоматически по чужому файлу нельзя. Кнопка ниже переносит подсказки
     в поля одним нажатием — решение остаётся за человеком. */
  $suggest = App\Models\ClientRoleMap::suggest((int)$c['id'], $mappableRoles); ?>
<?php /* Карта ролей свёрнута по умолчанию: её настраивают один раз при
         подключении и потом не трогают годами. Загрузка карты из JSON — внутри
         неё же: это та же карта, только из файла. */ ?>
<details class="bento-card disclosure mt-16" id="rolemap"<?= $roleMap === [] ? '' : ' open' ?>>
  <summary>
    <span class="bc-title mb-0"><?= esc(t('Карта ролей')) ?></span>
    <span class="hint"><?= esc($roleMap === []
        ? t('не настроена — портал получает слаги как есть')
        : t('настроено соответствий: :n', ['n' => count($roleMap)])) ?></span>
  </summary>
  <p class="hint mb-16 mt-8">
    <?= esc(t('Слева роль в :app, справа — как её увидит портал в claim roles. Пусто — роль не передаётся. Если карта пуста целиком, портал получает слаги как есть.', ['app' => App\Core\Setting::appName()])) ?>
  </p>
  <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/rolemap">
    <?= csrf_field() ?>
    <div class="stack mb-16">
      <?php foreach ($mappableRoles as $role): ?>
      <div class="row-between">
        <label class="cell-main" for="f-role-<?= (int)$role['id'] ?>">
          <span class="mono"><?= esc($role['slug']) ?></span>
          <span class="hint"><?= esc($role['name']) ?></span>
        </label>
        <input class="input mono mw-xs" id="f-role-<?= (int)$role['id'] ?>"
               name="map[<?= esc($role['slug']) ?>]"
               value="<?= esc($roleMap[$role['slug']] ?? '') ?>"
               placeholder="<?= esc($suggest[$role['slug']] ?? t('не передавать')) ?>"
               <?= isset($suggest[$role['slug']]) ? 'data-suggest="' . esc($suggest[$role['slug']]) . '"' : '' ?>>
      </div>
      <?php endforeach; ?>
    </div>
    <?php /* Полоса действий одна на весь проект, поэтому соседние кнопки едут
             внутрь неё, а не встают рядом отдельным рядом: иначе «сохранить»
             прилипает к низу экрана, а «выгрузить» уезжает вверх вместе со
             страницей — два действия одного блока в разных местах. */ ?>
    <?= form_actions(t('Сохранить карту'),
        ($suggest !== []
            ? '<button class="btn btn-secondary" type="button" data-fill-suggest>'
              . esc(t('Подставить предложенное (:n)', ['n' => count($suggest)])) . '</button>'
            : '')
        . '<a data-download class="btn btn-secondary" href="/admin/clients/' . (int)$c['id'] . '/rolemap.json">'
        . esc(t('Выгрузить JSON')) . '</a>') ?>
  </form>

  <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/rolemap/import"
        enctype="multipart/form-data" class="mt-16">
    <?= csrf_field() ?>
    <label class="hint" for="f-rolemap-file"><?= esc(t('Загрузить карту из JSON')) ?></label>
    <div class="inline-field mt-8">
      <input class="input" id="f-rolemap-file" type="file" name="map" accept="application/json,.json" required>
      <button class="btn btn-secondary" type="submit"><?= esc(t('Загрузить')) ?></button>
    </div>
    <p class="hint mt-8"><?= esc(t('Неизвестные роли пропускаются со списком, а не роняют импорт целиком.')) ?></p>
  </form>
</details>
<?php endif; ?>
