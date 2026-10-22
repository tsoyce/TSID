<?php /** Настройки аккаунта (§5.1): логин/пояс, смена email, удаление. */ ?>
<div class="page-head"><h1><?= esc(t('Настройки аккаунта')) ?></h1></div>

<div class="bento">
  <div class="bento-card col-6">
    <div class="bc-title"><?= esc(t('Аккаунт')) ?></div>
    <form method="post" action="/profile/settings/account">
      <?= csrf_field() ?>
      <?= field_text('login', t('Логин'), $user['login'], [
            'mono' => true, 'max' => 64, 'placeholder' => t('необязательно'),
            'hint' => t('Можно входить по логину вместо email.')]) ?>
      <?= field_select('timezone', t('Часовой пояс'), $timezones, $tzSelected, [
            'note' => '(' . t('сейчас') . ': ' . tz_gmt_label($user['timezone']) . ')']) ?>
      <?php /* Названия языков пишутся на своём языке нарочно — их не переводят. */ ?>
      <?= field_select('locale', t('Язык'), ['ru' => 'Русский', 'en' => 'English'], $user['locale']) ?>
      <?= form_actions() ?>
    </form>
  </div>

  <div class="bento-card col-6">
    <div class="bc-title"><?= esc(t('Смена email')) ?></div>
    <p class="muted"><?= esc(t('Сейчас')) ?>: <strong><?= esc($user['email']) ?></strong></p>
    <form method="post" action="/profile/settings/email">
      <?= csrf_field() ?>
      <?= field_text('new_email', t('Новый email'), '', ['type' => 'email', 'required' => true]) ?>
      <?= field_text('password', t('Пароль для подтверждения'), '', [
            'type' => 'password', 'autocomplete' => 'current-password', 'required' => true]) ?>
      <button class="btn btn-primary" type="submit"><?= esc(t('Отправить подтверждение')) ?></button>
    </form>
    <p class="hint mt-8"><?= esc(t('Подтверждение уйдёт на новый адрес; на старый — уведомление с кнопкой «это не я» (она откатит смену и заморозит сессии).')) ?></p>
  </div>

  <div class="bento-card col-6" id="notifications">
    <div class="bc-title"><?= esc(t('Уведомления безопасности')) ?></div>
    <p class="muted"><?= esc(t('Письма о важных событиях. Любое можно выключить — ничего не навязываем.')) ?></p>
    <form method="post" action="/profile/settings/notifications">
      <?= csrf_field() ?>
      <div class="stack mb-16">
        <?php foreach ($notifyPrefs as $event => $p): ?>
        <?= field_toggle('notify_' . str_replace('.', '_', $event), t($p['label']), (bool)$p['enabled']) ?>
        <?php endforeach; ?>
      </div>
      <?= form_actions() ?>
    </form>
  </div>

  <div class="bento-card col-6" id="data">
    <div class="bc-title"><?= esc(t('Мои данные')) ?></div>
    <p class="muted"><?= esc(t('Архив со всем, что о вас хранится: профиль, устройства, сервисы, журнал событий, аватар. Пароль и ключи в выгрузку не входят — они хранятся только в виде хэшей.')) ?></p>
    <?php if ($exportJob && $exportJob['status'] === 'ready'): ?>
      <div class="row">
        <span class="badge badge-ok"><?= esc(t('архив готов')) ?></span>
        <span class="hint"><?= esc(t('собран :when, :size КБ · ссылка в письме действует сутки', [
          'when' => fmt_ago($exportJob['ready_at']),
          'size' => (int)round(((int)$exportJob['size_bytes']) / 1024)])) ?></span>
      </div>
    <?php elseif ($exportJob && $exportJob['status'] === 'pending'): ?>
      <?php /* Архив собирается сразу при заказе. Если он всё-таки в очереди —
               значит, сборка не удалась и ждёт планировщика. Когда планировщик
               не настроен, ждать бессмысленно, и об этом надо сказать. */ ?>
      <div class="row"><span class="badge badge-warn"><?= esc(t('в очереди')) ?></span>
        <span class="hint"><?= esc($cronAlive
            ? t('соберётся ближайшей фоновой задачей')
            : t('планировщик не настроен — задача не выполнится, см. «Здоровье»')) ?></span></div>
      <form method="post" action="/profile/settings/export" class="mt-8">
        <?= csrf_field() ?>
        <button class="btn btn-secondary btn-s" type="submit"><?= icon('refresh', 15) ?> <?= esc(t('Собрать сейчас')) ?></button>
      </form>
    <?php elseif (empty($exportReady)): ?>
      <?php /* Схема отстаёт от кода: показываем причину, а не мёртвую кнопку */ ?>
      <div class="hint"><?= esc(t('Раздел появится после применения миграций базы.')) ?></div>
    <?php else: ?>
      <form method="post" action="/profile/settings/export">
        <?= csrf_field() ?>
        <button class="btn btn-secondary" type="submit"><?= icon('download', 16) ?> <?= esc(t('Заказать выгрузку')) ?></button>
      </form>
    <?php endif; ?>
  </div>

  <?php /* Карточка скрыта, пока браузер сам не подтвердит, что установка
           возможна: критерии у браузеров разные и меняются, а кнопка, которая
           иногда ничего не делает, хуже отсутствующей. Safari события не
           присылает и ставит только через «Поделиться» — для него отдельная
           строка вместо кнопки. */ ?>
  <div class="bento-card col-6" id="app" data-pwa-install hidden>
    <div class="bc-title"><?= esc(t('Приложение')) ?></div>
    <p class="muted"><?= esc(t('Иконка на домашнем экране, запуск без адресной строки.')) ?></p>
    <button class="btn btn-primary" type="button" data-pwa-prompt><?= icon('download-app', 16) ?> <?= esc(t('Установить')) ?></button>
    <p class="hint mb-0" data-pwa-ios hidden><?= esc(t('«Поделиться» → «На экран «Домой»».')) ?></p>
  </div>

  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Опасная зона')) ?></div>
    <div class="row-between">
      <p class="muted mb-0 mw-lg"><?= esc(t('Удаление помечает аккаунт на 14 дней (в письме будет кнопка отмены), затем данные анонимизируются безвозвратно: email превратится в хэш, имя и аватар исчезнут. Порталы получат событие удаления.')) ?></p>
      <form method="post" action="/profile/settings/delete" class="row"
            data-confirm="<?= esc(t('Точно удалить аккаунт? Через 14 дней восстановить будет невозможно.')) ?>">
        <?= csrf_field() ?>
        <?php /* Ширину поле берёт от остатка строки, а не требует своих 320px:
                 рядом стоит объяснение на всю ширину карточки, форме остаётся
                 358px, и фиксированное поле выдавливало кнопку на строку ниже —
                 на любом настольном экране. Замерено, а не на глаз. */ ?>
        <input class="input flex-1" type="password" name="password" placeholder="<?= esc(t('Пароль')) ?>" required
               aria-label="<?= esc(t('Пароль для подтверждения удаления')) ?>">
        <button class="btn btn-danger" type="submit"><?= esc(t('Удалить аккаунт')) ?></button>
      </form>
    </div>
  </div>
</div>
