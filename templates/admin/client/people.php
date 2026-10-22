<?php /**
 * Люди сервиса: заявки на доступ и те, у кого он уже есть.
 *
 * Раньше в карточке была только вкладка «Заявки» — то есть на вопрос «а кто
 * вообще сюда ходит» карточка портала не отвечала, и за ответом надо было идти
 * в общий список людей и фильтровать. Заявка и доступ — разные состояния
 * одного отношения, поэтому они рядом, но списками врозь.
 */ ?>
<?php $me = (int)\App\Core\Auth::id(); ?>
<?php if ($pending !== []): ?>
<div class="bento-card mb-16 bordered-ink">
  <div class="bc-title"><?= esc(t('Ждут одобрения')) ?> (<?= count($pending) ?>)</div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th><?= esc(t('Пользователь')) ?></th><th><?= esc(t('Заявка')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pending as $p): ?>
      <tr>
        <td class="cell-main"><?= esc(user_title($p)) ?></td>
        <td class="hint"><?= esc(fmt_ago($p['created_at'])) ?></td>
        <td class="row gap-4">
          <form method="post" action="/admin/users/<?= (int)$p['user_id'] ?>/access"><?= csrf_field() ?>
            <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="access" value="approve">
            <input type="hidden" name="back" value="/admin/clients/<?= (int)$c['id'] ?>/people">
            <button class="btn btn-s btn-primary" type="submit"><?= esc(t('Одобрить')) ?></button>
          </form>
          <form method="post" action="/admin/users/<?= (int)$p['user_id'] ?>/access"><?= csrf_field() ?>
            <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="access" value="revoke">
            <input type="hidden" name="back" value="/admin/clients/<?= (int)$c['id'] ?>/people">
            <button class="btn btn-s btn-danger-ghost" type="submit"><?= esc(t('Отклонить')) ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="bento-card">
  <div class="row-between">
    <div class="bc-title mb-0"><?= esc(t('Есть доступ')) ?></div>
    <?php /* Поле отбора приезжает с `hidden` и открывается скриптом — и только
             если строк достаточно, чтобы в них было что искать. Показать его
             из разметки нельзя: при выключенном JS вышел бы мёртвый орган —
             человек печатает, а таблица не шевелится. */ ?>
    <input class="input mw-xs" type="search" data-filter="#people-table" hidden
           placeholder="<?= esc(t('Найти человека')) ?>"
           aria-label="<?= esc(t('Найти человека')) ?>">
  </div>
  <?php if ($users === []): ?>
    <?= empty_state('users', t('В этот сервис пока никто не входил.'), '', 'pad-16') ?>
  <?php else: ?>
  <div class="table-wrap mt-12">
    <table class="table" id="people-table">
      <thead><tr>
        <th></th><th><?= esc(t('Человек')) ?></th>
        <th><?= esc(t('Доступ')) ?></th>
        <th><?= esc(t('Роли в сервисе')) ?></th>
        <th><?= esc(t('Последний вход')) ?></th>
        <?php if ($mayManage): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?= avatar_html($u, 32) ?></td>
        <td><a href="/admin/users/<?= (int)$u['id'] ?>"><?= esc(user_name($u)) ?></a>
            <div class="hint"><?= esc(user_sub($u)) ?></div></td>
        <td><?= access_badge((string)$u['access_status']) ?></td>

        <?php /* Роли — здесь же, в строке человека, общим механизмом.
                 Что именно можно выдать в этом сервисе, решает RoleOffer:
                 список собирался запросом «свои плюс системные» и вырастал
                 до двенадцати пунктов с дублями `admin` и `user` и ролями
                 чужих областей. */ ?>
        <td>
          <?= role_cell((int)$u['id'], user_name($u), $scope,
                '/admin/clients/' . (int)$c['id'] . '/action',
                $rolesByUser[(int)$u['id']] ?? [], $grantable, $mayRoles) ?>
        </td>

        <td class="hint"><?= esc($u['last_login_at'] ? fmt_ago($u['last_login_at']) : t('ни разу')) ?></td>
        <?php if ($mayManage): ?>
        <td>
          <?php /* Своей строке — ни «Закрыть», ни «Убрать» (v4.67): одним нажатием
                   администратор выкидывал себя из сервиса, которым управляет.
                   Сервер это тоже не пропустит; здесь — чтобы не было соблазна. */ ?>
          <?php if ((int)$u['id'] === $me): ?>
          <span class="hint"><?= esc(t('это вы')) ?></span>
          <?php else: ?>
          <div class="row gap-4">
            <?php if ($u['access_status'] !== 'active'): ?>
            <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/access"><?= csrf_field() ?>
              <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="back" value="/admin/clients/<?= (int)$c['id'] ?>/people">
              <input type="hidden" name="access" value="grant">
              <button class="btn btn-s btn-secondary" type="submit"><?= esc(t('Открыть')) ?></button>
            </form>
            <?php else: ?>
            <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/access"
                  data-confirm="<?= esc(t('Закрыть доступ к сервису? В сервисе учётная запись уйдёт в архив: войти нельзя, данные целы. Откроете доступ снова — вернётся.')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="back" value="/admin/clients/<?= (int)$c['id'] ?>/people">
              <input type="hidden" name="access" value="revoke">
              <button class="btn btn-s btn-secondary" type="submit"><?= esc(t('Закрыть')) ?></button>
            </form>
            <?php endif; ?>
            <?php /* «Убрать» — только у неактивного доступа. У активного он был
                     ловушкой: запись стиралась, портал ничего не узнавал, а в
                     открытый портал человек при следующем входе попадал снова —
                     доступ там выдаётся сам (живой случай 11.09, v4.62).
                     Отрезать — это «Закрыть»: запись остаётся с отметкой и держит. */ ?>
            <?php if ($u['access_status'] !== 'active'): ?>
            <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/access"
                  data-confirm="<?= esc(($c['access_policy'] ?? '') === 'open'
                      ? t('Убрать совсем? Сервис сотрёт учётную запись этого человека и все его данные — безвозвратно. Портал открытый: войти снова человек сможет, но с пустой учётной записью.')
                      : t('Убрать совсем? Сервис сотрёт учётную запись этого человека и все его данные — безвозвратно.')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="back" value="/admin/clients/<?= (int)$c['id'] ?>/people">
              <input type="hidden" name="access" value="deny">
              <button class="btn btn-ghost btn-s" type="submit"><?= esc(t('Убрать')) ?></button>
            </form>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      <?php /* Пустой отбор обязан сказать об этом словами: таблица, из которой
               пропали все строки, читается как «доступа нет ни у кого». */ ?>
      <tr data-filter-empty hidden>
        <td colspan="<?= $mayManage ? 6 : 5 ?>">
          <?= empty_state('search', t('Никого не нашлось — попробуйте другое слово.'), '', 'pad-16') ?>
        </td>
      </tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php /* Дать доступ — здесь же. Обратная сторона отношения уже была в
           карточке человека, а отсюда пустить было нельзя: чтобы добавить
           пятерых в один сервис, приходилось открыть пять чужих карточек. */ ?>
  <?php if ($mayManage): ?>
  <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/people" class="row mt-16">
    <?= csrf_field() ?>
    <?= field_text('identity', t('Дать доступ'), '', [
          'placeholder' => t('почта или логин'), 'required' => true,
          'id' => 'f-grant-identity']) ?>
    <?= field_submit(t('Дать доступ')) ?>
  </form>
  <p class="hint mt-8"><?= esc(t('Аккаунт при этом не заводится: человек должен уже быть в системе. Завести нового — в разделе «Пользователи».')) ?></p>
  <?php endif; ?>

  <?php /* Почему список ролей такой короткий — словами и там же, где он.
           Короткий список без объяснения читается как поломка: «а где
           остальные роли?» */ ?>
  <?php if ($mayRoles): ?>
  <p class="hint mt-8"><?= esc($offerWhy) ?></p>
  <?php endif; ?>

  <?php if ($orgs !== []): ?>
  <p class="hint mt-8"><?= esc(t('Часть людей приходит сюда через организации, которым выдан доступ целиком — отзывать их надо там, а не поштучно.')) ?></p>
  <?= rel_group(t('Организации с доступом'), array_map(static fn(array $o): array => [
        'href' => '/admin/orgs/' . (int)$o['id'],
        'label' => $o['name'],
    ], $orgs), '') ?>
  <?php endif; ?>
</div>

<?php /* Перенос людей с портала (v4.68 — переехал сюда из «Ролей»): он про
         людей, и искать его среди ролей не должен никто. Сам перенос —
         отдельным экраном: он заводит учётные записи в источнике истины, и
         «нажал и посмотрим» тут не годится. */ ?>
<?php if (\App\Core\Auth::can('clients.write')): $pu = App\Core\PortalUsers::linkedCount((int)$c['id']); ?>
<div class="bento-card mt-16" id="users">
  <div class="bc-title"><?= esc(t('Перенос людей с портала')) ?></div>
  <div class="row">
    <a class="btn btn-secondary" href="/admin/clients/<?= (int)$c['id'] ?>/users/preview">
      <?= icon('users', 16) ?> <?= esc(t('Забрать людей')) ?>
    </a>
    <?php if ($pu > 0): ?>
    <span class="hint"><?= esc(t('уже связано: :n', ['n' => $pu])) ?></span>
    <?php endif; ?>
  </div>
  <p class="hint mt-8">
    <?php if (App\Core\PortalUsers::configured($c)): ?>
      <?= esc(t('Настроено. Портал отдаёт своих людей по :url, повторный перенос дописывает уже перенесённых.', ['url' => (string)$c['users_url']])) ?>
    <?php else: ?>
      <?= esc(t('Пока не настроено: нужен файл sso/users.php на портале и ключ переноса. Что именно не хватает — на экране переноса.')) ?>
    <?php endif; ?>
  </p>
</div>
<?php endif; ?>
