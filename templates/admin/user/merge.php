<?php /**
 * Объединить аккаунты (v4.88). $u, $unlocked, $hints, $query, $other, $sides.
 *
 * Слева — этот аккаунт, справа — второй; администратор выбирает, какой
 * остаётся. Слияние делает AccountMerge — тот же механизм, что у человека в
 * профиле; здесь только выбор сторон. Необратимо, поэтому за паролем, как и
 * окончательное удаление.
 */
$base = '/admin/users/' . (int)$u['id'] . '/merge'; ?>
<div class="bento">
<div class="bento-card col-12" id="merge">
  <div class="bc-title"><?= esc(t('Объединить с другим аккаунтом')) ?></div>
  <p class="hint"><?= esc(t('Для дублей одного человека: второй аккаунт через другую почту или другой аккаунт сервиса. Оставшемуся переедут сервисы входа, ключи, доступы, роли и организации; почта влитого станет дополнительной, пустые поля профиля дополнятся. Влитый аккаунт получит статус «удалён» со ссылкой на оставшийся, его сессии закроются, порталам уйдёт событие удаления с новым адресом.')) ?></p>

  <?php if ($hints !== []): ?>
  <div class="mt-16">
    <div class="fw-600 mb-8"><?= esc(t('Возможные дубли')) ?></div>
    <ul class="check-list" data-merge-hints>
      <?php foreach ($hints as $h): ?>
      <li class="mb-4"><a href="<?= esc($base . '?with=' . (int)$h['user']['id']) ?>"><?= esc(user_title($h['user'])) ?></a>
        <span class="hint">· <?= esc($h['why'] === 'name_birthdate' ? t('совпали фамилия, имя и дата рождения') : t('совпали фамилия и имя')) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <form method="get" action="<?= esc($base) ?>" class="mt-16">
    <div class="inline-field mw-md">
      <input class="input" name="with" value="<?= esc($query) ?>" placeholder="<?= esc(t('Почта, логин, телефон или #номер')) ?>"
             aria-label="<?= esc(t('Второй аккаунт')) ?>">
      <button class="btn btn-secondary" type="submit"><?= icon('search', 16) ?> <?= esc(t('Найти')) ?></button>
    </div>
    <?php if ($query !== '' && $other === null): ?>
    <p class="hint mt-8"><?= esc(t('Такого живого аккаунта нет — или это он сам.')) ?></p>
    <?php endif; ?>
  </form>
</div>

<?php if ($other !== null): ?>
<div class="bento-card col-12 bordered-danger">
  <div class="bc-title"><?= esc(t('Какой аккаунт оставить')) ?></div>
  <?php /* Обе стороны одной таблицей: строка — признак, столбец — аккаунт.
           Так видно, что потеряется, если оставить не тот: у влитого почта
           уйдёт в дополнительные, а пустые поля оставшегося дополнятся. */ ?>
  <div class="table-wrap mt-8">
    <table class="table" data-merge-sides>
      <thead><tr><th></th>
        <?php foreach ($sides as $i => $s): ?>
        <th><?= $i === 0 ? esc(t('Этот')) : esc(t('Второй')) ?> · #<?= (int)$s['user']['id'] ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php foreach (array_keys($sides[0]['rows']) as $label): ?>
        <tr><th scope="row" class="hint"><?= esc($label) ?></th>
          <td><?= esc($sides[0]['rows'][$label]) ?></td><td><?= esc($sides[1]['rows'][$label]) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!$unlocked): ?>
  <?= danger_gate('/admin/users/' . (int)$u['id'] . '/danger', 'mt-16', ['back' => 'merge', 'with' => (int)$other['id']], false) ?>
  <?php else: ?>
  <form method="post" action="<?= esc($base) ?>" class="mt-16"
        data-confirm="<?= esc(t('Объединить аккаунты? Это необратимо.')) ?>">
    <?= csrf_field() ?><input type="hidden" name="with" value="<?= (int)$other['id'] ?>">
    <?= field_choices('keep', t('Оставить'), [
          'this' => ['title' => t('Этот — :who', ['who' => user_handle($u)]), 'hint' => t('Второй вольётся сюда')],
          'other' => ['title' => t('Второй — :who', ['who' => user_handle($other)]), 'hint' => t('Этот вольётся туда')],
        ], 'this', ['cols' => 2]) ?>
    <button class="btn btn-danger" type="submit"><?= esc(t('Объединить')) ?></button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>
