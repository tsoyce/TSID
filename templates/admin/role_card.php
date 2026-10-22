<?php /**
 * Карточка роли: таблица прав и уровень доступа (v4.3, оформление v4.3.6).
 * $role, $perms, $usersCount, $locked. Разбор — ACCESS_MODEL.md.
 */
use App\Core\Permission;
use App\Core\AccessLevel; ?>
<?= breadcrumbs([[t('Роли'), '/admin/roles'], [$role['name'], null]]) ?>
<?php /* Роль — такой же объект, как организация, портал и человек: имя,
         машинный идентификатор, состав. Шапка та же, иначе четвёртая карточка
         выглядит чужой рядом с тремя одинаковыми.
         Вкладки «Связи» и «История» здесь пока нет: у события в журнале нет
         измерения роли — оно есть у человека, портала и организации, а роль
         попадает в meta строкой. Заводить четвёртую колонку ради одной вкладки
         сейчас не за чем: см. дорожную карту. */ ?>
<?php /* v4.93: у роли портала рядом стояло машинное «client_12» — теперь имя
         портала ссылкой. Число людей — ссылкой на список с этой ролью. */
$owner = $role['client_id'] ? App\Models\Client::find((int)$role['client_id']) : null; ?>
<?= obj_head(
    icon('role', 28, 'icon-muted'),
    $role['name'],
    [$role['slug']],
    ((int)$usersCount > 0
        ? '<a class="badge badge-muted" href="/admin/users?role=' . (int)$role['id'] . '">'
          . (int)$usersCount . ' ' . esc(plural((int)$usersCount, 'человек', 'человека', 'человек')) . '</a>'
        : '<span class="badge badge-muted">' . esc(t('никого')) . '</span>')
    . ($role['client_id'] ? '<span class="hint">' . esc(t('роль портала')) . ' '
        . ($owner !== null ? '<a href="/admin/clients/' . (int)$owner['id'] . '">' . esc($owner['name']) . '</a>' : '#' . (int)$role['client_id'])
        . '</span>' : '')
) ?>

<?php if ($locked): ?>
<?= callout(t('Права суперадмина не редактируются: ему разрешено всё. Галочки позволили бы снять их с себя и запереть систему.'), 'info') ?>
<?php endif; ?>

<form method="post" action="/admin/roles/<?= (int)$role['id'] ?>/permissions" data-perm-form>
  <?= csrf_field() ?>
  <div class="bento-card col-12">
    <?php /* Абзаца-объяснения под заголовком больше нет: первую его половину
             говорит сама таблица, вторую — полая галочка автопроставленного
             чтения. Осталась одна строка справа от заголовка. */ ?>
    <div class="row-between">
      <div class="bc-title mb-0"><?= esc(t('Что можно')) ?></div>
      <span class="hint"><?= esc(t('Строки — области, столбцы — действия')) ?></span>
    </div>

    <div class="table-wrap mt-12">
      <?php /* table-cards: единственная таблица в системе, у которой не было
               карточного режима, — на телефоне она уезжала в горизонтальный
               скролл целиком. */ ?>
      <table class="table table-cards perm-grid">
        <thead>
          <tr>
            <th><?= esc(t('Область')) ?></th>
            <?php foreach (Permission::ACTIONS as $action => $label): ?>
            <?php /* Шапка столбца работает кнопкой «весь столбец»: восемнадцать
                     галочек по одной ставили руками. */ ?>
            <th class="text-center"><button class="perm-col" type="button" data-col="<?= esc($action) ?>"
                <?= $locked ? 'disabled' : '' ?>><?= esc(t($label)) ?></button></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php /* data-area и data-action — опоры для скрипта. Каталог областей
                 живёт в PHP, и дублировать его массивом в JS значило бы завести
                 второй источник правды, который однажды разойдётся с первым. */ ?>
        <?php foreach (Permission::AREAS as $area => [$title, $descr, $actions, $why]): ?>
          <tr data-area="<?= esc($area) ?>">
            <td>
              <div class="cell-main"><?= esc(t($title)) ?></div>
              <div class="hint"><?= esc(t($descr)) ?></div>
            </td>
            <?php foreach (Permission::ACTIONS as $action => $label): ?>
            <td class="text-center" data-action="<?= esc($action) ?>">
              <?php if (in_array($action, $actions, true)): ?>
                <?php /* Квадрат рисует соседний span, а не сам input: нативная
                         галочка не берёт акцент владельца и в тёмной теме
                         остаётся системно-синей. Подпись внутри label заменяет
                         aria-label, поэтому его тут больше нет. */ ?>
                <label class="perm-cell">
                  <input type="checkbox" name="perms[]" value="<?= esc($area . '.' . $action) ?>"
                         <?= in_array($area . '.' . $action, $perms, true) ? 'checked' : '' ?>
                         <?= $locked ? 'disabled' : '' ?>>
                  <span class="perm-box" aria-hidden="true"></span>
                  <span class="perm-name"><?= esc(t($label)) ?></span>
                </label>
              <?php else: ?>
                <?php /* Прочерк читался как «выключено», то есть как «можно
                         включить». Штриховка не путается с пустым квадратом, а
                         причина наконец доезжает до интерфейса из каталога. */ ?>
                <span class="perm-na" title="<?= esc(t($why)) ?>"></span>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php /* Та же раскладка одной строкой. Её можно продиктовать, вставить в
             тикет и сравнить глазами с другой ролью — таблицу нельзя. */ ?>
    <div class="perm-mode" data-perm-mode aria-label="<?= esc(t('Права одной строкой')) ?>"></div>
    <p class="hint mt-8 mb-0"><?= esc(t('Точка — действие к области неприменимо.')) ?></p>
  </div>

  <div class="bento">
    <div class="bento-card col-7">
      <div class="bc-title"><?= esc(t('Особые права')) ?></div>
      <?php /* Плиткой, а не стопкой: четыре .checkbox подряд давали четыре
               строки серого текста, в которых пояснение спорило с названием. */ ?>
      <div class="special-grid">
        <?php foreach (Permission::SPECIAL as $perm => [$title, $descr]): ?>
        <label class="special-item">
          <input type="checkbox" name="perms[]" value="<?= esc($perm) ?>"
                 <?= in_array($perm, $perms, true) ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
          <span class="perm-box" aria-hidden="true"></span>
          <span><span class="special-title"><?= esc(t($title)) ?></span>
            <span class="hint"><?= esc(t($descr)) ?></span></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="bento-card col-5">
      <div class="bc-title"><?= esc(t('Уровень доступа')) ?></div>
      <div class="field mb-0">
        <label for="f-level"><?= esc(t('Что увидит портал')) ?></label>
        <select class="select" id="f-level" name="access_level" data-level <?= $locked ? 'disabled' : '' ?>>
          <?php foreach (AccessLevel::TIERS as $value => [$slug, $tierTitle]): ?>
          <option value="<?= (int)$value ?>" data-slug="<?= esc($slug) ?>"
                  <?= (int)$role['access_level'] === (int)$value ? 'selected' : '' ?>>
            <?= (int)$value ?> — <?= esc(t($tierTitle)) ?> (<?= esc($slug) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php /* Список на шестнадцать пунктов прячет ответ на единственный вопрос —
               какое число уедет порталу. Выносим его из списка наружу. */ ?>
      <div class="level-plate">
        <span class="level-num" data-level-num><?= (int)$role['access_level'] ?></span>
        <div class="flex-1">
          <div class="cell-main mono" data-level-slug><?= esc(AccessLevel::TIERS[(int)$role['access_level']][0] ?? '') ?></div>
          <div class="hint"><?= esc(t('уезжает порталам в claim')) ?> <span class="mono">access_level</span></div>
        </div>
      </div>
      <p class="hint mt-12 mb-0"><?= esc(t('Внутри SSO уровень не решает ничего — здесь решает таблица слева.')) ?></p>
    </div>
  </div>

  <?php if (!$locked): ?>
  <?= form_actions('', '<button class="btn btn-ghost" type="button" data-perm-reset hidden>'
      . esc(t('Вернуть как было')) . '</button>'
      . '<span class="diff-count spacer" data-perm-diff hidden></span>') ?>
  <?php endif; ?>
</form>
