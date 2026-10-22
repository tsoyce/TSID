<?php /** Перенос людей с портала (§13.3): сначала «что будет», потом кнопка. */ ?>
<?= breadcrumbs([[t('Порталы'), '/admin/clients'], [$c['name'], '/admin/clients/' . (int)$c['id']], [t('Перенос людей'), null]]) ?>
<div class="page-head"><h1><?= esc(t('Перенос людей — :name', ['name' => $c['name']])) ?></h1></div>

<?php if (!empty($freshKey)): ?>
<?php /* Ключ показывается ровно один раз и только что выдан. Полем с кнопкой
         «копировать», а не тостом: тост уезжает через пять секунд, а тут сорок
         восемь знаков, которые надо перенести в чужой config.php. */ ?>
<div class="bento-card col-12 mb-16 bordered-ink">
  <div class="bc-title"><?= esc(t('Ключ переноса выдан — скопируйте его сейчас')) ?></div>
  <div class="secret-plate mb-16"><?= esc(t('Больше он не покажется. Потеряли — выдайте заново, старый перестанет работать.')) ?></div>
  <?= field_copy('users-key', 'users_secret', $freshKey, [
        'hint' => t('Впишите в sso/config.php портала как users_secret. Ключ отдельный от client_secret: его смена вход не ломает.')]) ?>
</div>
<?php endif; ?>

<div class="bento">
  <?php if (!App\Core\PortalUsers::configured($c)): ?>
  <?php /* Не настроен — объясняем оба недостающих куска сразу, а не по одному
           на каждое нажатие: администратор всё равно пойдёт делать оба. */ ?>
  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Перенос ещё не настроен')) ?></div>
    <?= empty_state('portal', t('Нужны две вещи'),
        t('Модуль выгрузки на стороне портала — файл sso/users.php из набора — и ключ переноса, выданный здесь. Адрес выгрузки портал сообщает манифестом сам; ключ вписывают в его config.php.')) ?>
    <ul class="hint mt-16">
      <li><?= esc(t('Адрес выгрузки:')) ?> <span class="mono"><?= esc($c['users_url'] ?: t('портал ещё не сообщил — нажмите «Спросить у портала» в карточке')) ?></span></li>
      <li><?= esc(t('Ключ переноса:')) ?> <?= $c['users_secret'] ? esc(t('выдан')) : esc(t('не выдан')) ?></li>
    </ul>
    <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/users/key" class="mt-16">
      <?= csrf_field() ?>
      <button class="btn btn-primary" type="submit"><?= esc(t('Выдать ключ переноса')) ?></button>
    </form>
  </div>

  <?php elseif (!$preview['ok']): ?>
  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Портал не отдал список')) ?></div>
    <p class="text-danger"><?= esc($preview['error']) ?></p>
    <p class="hint mt-8"><?= esc(t('Проверьте, что файл sso/users.php лежит на месте и что ключ в его config.php совпадает с выданным здесь. Ключ можно выдать заново — старый при этом перестанет работать.')) ?></p>
    <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/users/key" class="mt-16">
      <?= csrf_field() ?>
      <button class="btn btn-secondary" type="submit"><?= esc(t('Выдать ключ заново')) ?></button>
    </form>
  </div>

  <?php /* Портал ответил, но отдавать нечего: список пуст. Раньше на это
           рисовались три нуля и кнопка «Забрать людей» — предложение сделать
           то, чего не существует. */ ?>
  <?php elseif ((int)$preview['new'] === 0 && (int)$preview['linked'] === 0
                && (int)$preview['skipped'] === 0): ?>
  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Забирать некого')) ?></div>
    <?= empty_state('users', t('Портал отвечает, но людей у него нет.'),
        '', 'pad-16') ?>
    <p class="hint mt-8"><?= esc(t('Связь настроена и работает — это не поломка. Заведутся люди на портале, приходите сюда снова.')) ?></p>
    <p class="hint mt-8"><a href="/admin/clients/<?= (int)$c['id'] ?>/people#users">← <?= esc(t('Вернуться к порталу')) ?></a></p>
  </div>

  <?php /* Все, кого портал отдаёт, у нас уже есть. Тоже отдельное состояние:
           «0 будет заведено» рядом с большой кнопкой читается как поломка,
           хотя это ровно тот результат, ради которого перенос и делали. */ ?>
  <?php elseif ((int)$preview['new'] === 0 && (int)$preview['skipped'] === 0): ?>
  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Все на месте')) ?></div>
    <div class="health-row">
      <span class="health-icon"><?= status_icon('ok') ?></span>
      <div>
        <div class="cell-main"><?= esc(t('Переносить нечего: все :n человек уже здесь.',
            ['n' => (int)$preview['linked']])) ?></div>
        <div class="hint"><?= esc(t('Мы узнали каждого — задвоений не будет. Новые появятся на портале — забирайте снова, повторный перенос безопасен.')) ?></div>
      </div>
    </div>
    <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/users/pull" class="row mt-16">
      <?= csrf_field() ?>
      <button class="btn btn-secondary" type="submit"><?= esc(t('Всё равно забрать заново')) ?></button>
      <a class="btn btn-ghost" href="/admin/clients/<?= (int)$c['id'] ?>/people#users"><?= esc(t('Вернуться к порталу')) ?></a>
    </form>
  </div>

  <?php else: ?>
  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Что произойдёт')) ?></div>
    <?php /* Три числа вместо абзаца: администратор решает по ним, а не по
             формулировке. «Дописано» отдельно от «заведено» именно потому, что
             это разные последствия — новые учётные записи и правка старых. */ ?>
    <div class="grid-3 mb-16">
      <div><div class="kpi-value text-success"><?= (int)$preview['new'] ?></div>
        <div class="kpi-sub"><?= esc(t('будет заведено')) ?></div></div>
      <div><div class="kpi-value"><?= (int)$preview['linked'] ?></div>
        <div class="kpi-sub"><?= esc(t('узнаны — допишем, не задвоим')) ?></div></div>
      <div><div class="kpi-value <?= $preview['skipped'] ? 'text-warning' : '' ?>"><?= (int)$preview['skipped'] ?></div>
        <div class="kpi-sub"><?= esc(t('пропущено: не по чему узнавать')) ?></div></div>
    </div>
    <p class="hint">
      <?php if ($preview['total'] !== null): ?>
        <?= esc(t('Портал сообщает, что всего у него :n. Считали по первой странице — :p.', [
            'n' => (int)$preview['total'], 'p' => App\Core\PortalUsers::PAGE])) ?>
      <?php else: ?>
        <?= esc(t('Считали по первой странице — :p.', ['p' => App\Core\PortalUsers::PAGE])) ?>
      <?php endif; ?>
      <?php if ($linked > 0): ?>
        <?= esc(t('Уже связано с этим порталом: :n.', ['n' => $linked])) ?>
      <?php endif; ?>
    </p>

    <?php if ($preview['sample'] !== []): ?>
    <div class="table-wrap mt-16">
      <table class="table">
        <thead><tr>
          <th><?= esc(t('Номер у портала')) ?></th><th><?= esc(t('Кто')) ?></th>
          <th><?= esc(t('Имя')) ?></th><th><?= esc(t('Что сделаем')) ?></th><th><?= esc(t('Пароль')) ?></th><th><?= esc(t('Роли в сервисе')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($preview['sample'] as $s): ?>
        <tr>
          <td class="mono hint"><?= esc($s['external_id']) ?></td>
          <td class="cell-main"><?= esc($s['handle']) ?></td>
          <td><?= esc($s['name'] ?: '—') ?></td>
          <td><?php
            $how = ['link' => t('узнан по прошлому переносу'), 'email' => t('узнан по почте'),
                    'login' => t('узнан по логину'), 'none' => t('заведём нового')];
            echo esc($how[$s['how']] ?? $s['how']);
          ?></td>
          <td><?= $s['password'] ? esc(t('переедет хэшем')) : '<span class="hint">' . esc(t('нет — задаст сам')) . '</span>' ?></td>
          <?php /* Что портал говорит о ролях человека — до нажатия (v4.66): пустая
                   колонка у всех сразу видна, и это вопрос к выгрузке портала. */ ?>
          <td class="mono"><?= ($s['roles'] ?? []) !== [] ? esc(implode(', ', $s['roles'])) : '<span class="hint">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php /* Оговорки, которые администратор обязан прочитать ДО нажатия, а не
             узнать после: что перенос не откатывает и чего не трогает. */ ?>
    <ul class="hint mt-16">
      <li><?= esc(t('Уже заполненное у нас перенос не перезаписывает: портал — источник истины о себе, но не о человеке.')) ?></li>
      <li><?= esc(t('Роли не переносятся. Ими управляет карта соответствия, и менять доступы переносом нельзя.')) ?></li>
      <li><?= esc(t('Повторный перенос безопасен: людей узнают по номеру у портала и дописывают, а не заводят заново.')) ?></li>
      <li><?= esc(t('За одно нажатие переносится не больше :n человек.', ['n' => App\Core\PortalUsers::MAX_PULL])) ?></li>
    </ul>

    <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/users/pull" class="mt-16"
          data-confirm="<?= esc(t('Забрать людей с портала? Учётные записи появятся в SSO.')) ?>">
      <?= csrf_field() ?>
      <?= form_actions(t('Забрать людей'),
          '<a class="btn btn-secondary" href="/admin/clients/' . (int)$c['id'] . '">' . esc(t('Отмена')) . '</a>') ?>
    </form>
  </div>
  <?php endif; ?>
</div>

<p class="hint mt-16"><a href="/admin/clients/<?= (int)$c['id'] ?>">← <?= esc(t('Вернуться к порталу')) ?></a></p>
