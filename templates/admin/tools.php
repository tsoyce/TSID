<?php /** Инструменты (§5.2): импорт §13, кэши, миграции. */ ?>

<div class="bento">
  <div class="bento-card col-12" id="import">
    <div class="bc-title"><?= esc(t('Импорт пользователей (§13)')) ?></div>

    <?php if ($importReport): ?>
    <div class="bento-card mb-16 surface-2">
      <div><?= esc(t('Создано:')) ?> <strong class="text-success"><?= (int)$importReport['created'] ?></strong> <?= esc(t('· Пропущено (уже есть):')) ?> <strong><?= (int)$importReport['skipped'] ?></strong> <?= esc(t('· Ошибок:')) ?> <strong class="<?= $importReport['errors'] ? 'text-danger' : '' ?>"><?= count($importReport['errors']) ?></strong></div>
      <?php if (!empty($importReport['phone_dropped'])): ?>
      <p class="hint mt-8"><?= esc(t('Заведены без телефона: номер уже был у другого человека (строки :rows). Сами люди на месте.', ['rows' => implode(', ', array_slice($importReport['phone_dropped'], 0, 30))])) ?></p>
      <?php endif; ?>
      <?php if ($importReport['errors']): ?>
      <details class="mt-8"><summary class="hint pointer"><?= esc(t('детали ошибок')) ?></summary>
        <div class="code-block mt-8 pre-wrap"><?= esc(implode("\n", array_slice($importReport['errors'], 0, 50))) ?></div>
      </details>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$preview): ?>
    <p class="muted"><?= esc(t('CSV (заголовки в первой строке, разделитель , или ;) или JSON-массив объектов. Чужие bcrypt-хэши принимаются как есть — при первом входе перехэшируются в argon2id.')) ?></p>
    <form method="post" action="/admin/tools/import" enctype="multipart/form-data" class="row">
      <?= csrf_field() ?><input type="hidden" name="step" value="upload">
      <input class="input mw-xs" type="file" name="file" accept=".csv,.json,text/csv,application/json" required
             aria-label="<?= esc(t('Файл со списком пользователей (CSV или JSON)')) ?>">
      <button class="btn btn-primary" type="submit"><?= esc(t('Загрузить и разобрать')) ?></button>
    </form>

    <?php else: ?>
    <p class="muted"><?= esc(t('Файл')) ?> <strong><?= esc($preview['filename']) ?></strong>:
    <?= esc(t('строк — :n. Сопоставьте колонки с полями:', ['n' => count($preview['rows'])])) ?></p>
    <?php /* Раньше почта была обязательной, и файл из сервиса с регистрацией по
             логину отвергался целиком. Достаточно любого из двух. */ ?>
    <p class="hint mb-12"><?= esc(t('Сопоставьте хотя бы одно поле: email или логин. Люди без почты — обычное дело: их переносят из сервисов, где регистрация шла логином и паролём. Войти они смогут логином, но восстановить доступ сами — нет.')) ?></p>
    <form method="post" action="/admin/tools/import">
      <?= csrf_field() ?><input type="hidden" name="step" value="map">
      <div class="grid-2">
        <?php foreach ([ 'email' => t('Email'), 'login' => t('Логин'), 'last_name' => t('Фамилия'), 'first_name' => t('Имя'), 'phone' => t('Телефон'), 'password_hash' => t('Хэш пароля (bcrypt/argon2)'), 'created_at' => t('Дата регистрации')] as $field => $label): ?>
        <?php
        $colOptions = ['' => t('— не импортировать')];
        $guess = '';
        foreach ($preview['columns'] as $col) {
            $colOptions[$col] = $col;
            if (strtolower($col) === $field
                || ($field === 'password_hash' && str_contains(strtolower($col), 'pass'))) $guess = $col;
        }
        ?>
        <?= field_select('map_' . $field, $label, $colOptions, $guess) ?>
        <?php endforeach; ?>
      </div>
      <?= field_toggle('send_set_link', t('Письмо «Задайте пароль» тем, у кого нет хэша'), false,
            t('Иначе такой человек попадёт в базу, но войти не сможет, пока не восстановит пароль сам.')) ?>
      <div class="table-wrap mb-16">
        <table class="table">
          <thead><tr><?php foreach ($preview['columns'] as $col): ?><th><?= esc($col) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach ($preview['sample'] as $row): ?>
          <tr><?php foreach ($preview['columns'] as $col): ?><td class="hint"><?= esc(mb_substr((string)($row[$col] ?? ''), 0, 40)) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="row">
        <button class="btn btn-primary" type="submit"><?= esc(t('Импортировать :n', ['n' => count($preview['rows'])])) ?></button>
        <button class="btn btn-secondary" type="submit" name="step" value="cancel"><?= esc(t('Отмена')) ?></button>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <?php /* Приглашение в СИСТЕМУ — не в портал. Стоит рядом с импортом людей,
           потому что отвечает на тот же вопрос: как аккаунт появляется, когда
           человек не может завести его сам. */ ?>
  <div class="bento-card col-12" id="invites">
    <div class="bc-title"><?= esc(t('Приглашение на регистрацию')) ?></div>
    <?php if (App\Core\Signup::open()): ?>
      <p class="muted"><?= esc(t('Регистрация сейчас открытая — приглашение не нужно, аккаунт заводится сам. Закрыть её можно в настройках регистрации.')) ?></p>
      <p class="hint mt-8"><a href="/admin/settings/section/signup"><?= esc(t('Настройки регистрации →')) ?></a></p>
    <?php else: ?>
      <p class="muted"><?= esc(t('Регистрация закрыта: завести аккаунт можно только по такой ссылке. Укажете почту — ссылка сработает только с ней; оставите пустой — подойдёт любому, кто её откроет.')) ?></p>
      <form method="post" action="/admin/tools/invite" class="row mt-16">
        <?= csrf_field() ?>
        <?= field_text('email', t('Кому (необязательно)'), '', [
              'type' => 'email', 'placeholder' => 'ivan@example.com', 'id' => 'f-invite-email']) ?>
        <?= field_submit(t('Выдать ссылку')) ?>
      </form>
      <?php if (!empty($signupInvite)): ?>
      <?= field_copy('signup-invite', t('Ссылка-приглашение'), $signupInvite['url'], ['class' => 'mt-16',
            'hint' => $signupInvite['email'] !== ''
                ? t('Действует только для :email. Одноразовая: после регистрации перестаёт работать.', ['email' => $signupInvite['email']])
                : t('Подойдёт любому, кто её откроет. Одноразовая: после регистрации перестаёт работать.')]) ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="bento-card col-12" id="api">
    <div class="bc-title"><?= esc(t('Токены Admin API')) ?></div>
    <p class="muted"><?= esc(t('Программное управление пользователями и порталами из скриптов и CI. Схема —')) ?> <a href="/api/v1/openapi.json" target="_blank" rel="noopener">openapi.json</a><?= esc(t(', её можно скормить Postman или нейросети. Лимит: 120 запросов в минуту на токен, все изменения попадают в аудит с именем токена.')) ?></p>

    <?php if (!empty($freshApiToken)): ?>
    <div class="secret-plate mb-16"><?= esc(t('Токен показывается один раз — скопируйте его сейчас.')) ?></div>
    <?= field_copy('api-token', '', $freshApiToken, ['class' => 'mb-16']) ?>
    <div class="code-block mb-16">curl -H "Authorization: Bearer <?= esc($freshApiToken) ?>" \
  <?= esc(base_url('/api/v1/users?limit=5')) ?></div>
    <?php endif; ?>

    <div class="bento">
      <div class="bento-card col-4 surface-2">
        <form method="post" action="/admin/tools/api-token">
          <?= csrf_field() ?>
          <?php
          $scopeOptions = [];
          foreach ($apiScopes as $scope => $label) $scopeOptions[$scope] = [$label, $scope];
          ?>
          <?= field_text('name', t('Название'), '', ['max' => 120, 'required' => true,
                'placeholder' => t('CI деплоя')]) ?>
          <?= field_checks('scopes', t('Права'), $scopeOptions, [],
                ['hint' => t('Право «*» — это уровень суперадмина для программы: оно покрывает всё, включая права, которые появятся позже. Такому токену стоит задать срок.')]) ?>
          <?= field_text('days', t('Срок, дней'), '0', ['type' => 'number', 'low' => 0, 'high' => 3650,
                'note' => t('(0 — бессрочно)'), 'class' => 'mw-xs']) ?>
          <button class="btn btn-primary btn-block" type="submit"><?= icon('plus', 16) ?> <?= esc(t('Выдать токен')) ?></button>
        </form>
      </div>
      <div class="bento-card col-8 surface-2">
        <?php if ($apiTokens === []): ?>
          <?= empty_state('key', t('Токенов пока нет.')) ?>
        <?php else: ?>
        <div class="table-wrap">
          <table class="table table-cards">
            <thead><tr><th><?= esc(t('Название')) ?></th><th><?= esc(t('Права')) ?></th><th><?= esc(t('Использован')) ?></th><th><?= esc(t('Статус')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($apiTokens as $t): ?>
              <?php $revoked = $t['revoked_at'] !== null;
                    $expired = $t['expires_at'] !== null && strtotime($t['expires_at'] . ' UTC') <= time(); ?>
              <tr>
                <td data-label="<?= esc(t('Название')) ?>" class="cell-main"><?= esc($t['name']) ?>
                  <div class="hint mono"><?= esc($t['prefix']) ?>…</div></td>
                <td data-label="<?= esc(t('Права')) ?>" class="hint"><?= esc(implode(', ', $t['scopes'])) ?: '—' ?></td>
                <td data-label="<?= esc(t('Использован')) ?>" class="hint nowrap"><?= esc(fmt_ago($t['last_used_at'])) ?></td>
                <td data-label="<?= esc(t('Статус')) ?>">
                  <?php if ($revoked): ?><span class="badge badge-muted"><?= esc(t('отозван')) ?></span>
                  <?php elseif ($expired): ?><span class="badge badge-muted"><?= esc(t('истёк')) ?></span>
                  <?php else: ?><span class="badge badge-ok"><?= esc(t('активен')) ?></span><?php endif; ?>
                </td>
                <td>
                  <?php if (!$revoked && !$expired): ?>
                  <form method="post" action="/admin/tools/api-token/<?= (int)$t['id'] ?>/revoke"
                        data-confirm="<?= esc(t('Отозвать токен «:name»? Скрипты, использующие его, перестанут работать.', ['name' => $t['name']])) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-s btn-danger-ghost" type="submit"><?= esc(t('Отозвать')) ?></button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php /* Отозванный токен не работает, но строку занимает — через год
                 список состоит из них. Показать ключ ещё раз нельзя: в базе
                 лежит только хэш, и это не строгость ради строгости, а
                 единственный способ хранить ключ так, чтобы его нельзя было
                 достать ни из базы, ни из бэкапа. Нужен снова — выдайте новый. */ ?>
        <?php $revokedCount = count(array_filter($apiTokens, static fn(array $t): bool => $t['revoked_at'] !== null)); ?>
        <div class="row-between mt-16">
          <p class="hint mb-0"><?= esc(t('Ключ показывается один раз при выдаче: в базе лежит только его хэш. Забыли — выдайте новый, старый отзовите.')) ?></p>
          <?php if ($revokedCount > 0): ?>
          <form method="post" action="/admin/tools/api-tokens/purge"
                data-confirm="<?= esc(t('Убрать отозванные токены из списка? Они и так не работают.')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-secondary btn-s" type="submit">
              <?= esc(t('Убрать отозванные (:n)', ['n' => $revokedCount])) ?></button>
          </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php /* Слепок настроек — не бэкап и не замена ему. Бэкап переносит ВСЁ, в
           том числе людей и ключи; слепок переносит только правила и вид, и
           именно поэтому его можно везти во вторую установку. */ ?>
  <?php if (\App\Core\Auth::isSuperadmin()): /* v4.98: перенос настроек — только суперадмину */ ?>
  <div class="bento-card col-12" id="settings">
    <div class="bc-title"><?= esc(t('Настройки одним файлом')) ?></div>

    <?php if (!empty($settingsPlan)): ?>
    <?php /* Экран «что произойдёт» — тот же порядок, что у переноса людей и
             переноса базы: сначала список, потом кнопка. */ ?>
    <div class="bento-card mb-16 bordered-ink surface-2">
      <div class="bc-title"><?= esc(t('Что изменится')) ?></div>
      <p class="hint"><?= esc(t('Файл из «:from», версия :version.', [
          'from' => $settingsPlan['from'], 'version' => $settingsPlan['version']])) ?></p>
      <?php if ($settingsPlan['change'] === [] && $settingsPlan['add'] === []): ?>
        <?= callout(t('Менять нечего: все настройки уже такие.'), 'info') ?>
      <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th><?= esc(t('Настройка')) ?></th><th><?= esc(t('Сейчас')) ?></th><th><?= esc(t('Станет')) ?></th></tr></thead>
          <tbody>
          <?php
          /* Значения обрезаем: тело документа о конфиденциальности — это
             страница текста, и в таблице она бесполезна. */
          $cut = static fn(string $v): string => mb_strlen($v) > 60 ? mb_substr($v, 0, 60) . '…' : ($v === '' ? '—' : $v);
          foreach ($settingsPlan['change'] as $k => [$was, $will]): ?>
          <tr>
            <td class="mono"><?= esc($k) ?></td>
            <td class="hint"><?= esc($cut($was)) ?></td>
            <td class="cell-main"><?= esc($cut($will)) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php foreach ($settingsPlan['add'] as $k => $will): ?>
          <tr>
            <td class="mono"><?= esc($k) ?></td>
            <td class="hint"><?= esc(t('нет')) ?></td>
            <td class="cell-main"><?= esc($cut($will)) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint mt-8"><?= esc(t('Совпадает и потому не тронется: :n.', ['n' => (int)$settingsPlan['same']])) ?></p>
      <?php if ($settingsPlan['refused'] !== []): ?>
      <?php /* «1 ключей» — мелочь, по которой видно, что текст собран машиной
               и никем не прочитан. Склонение у проекта уже есть. */ ?>
      <p class="hint"><?= icon('alert-triangle', 15, 'icon-warn') ?>
        <?= esc(t('Не приняли :n :word — они не переносятся между установками: :list', [
            'n' => count($settingsPlan['refused']),
            'word' => plural(count($settingsPlan['refused']), 'ключ', 'ключа', 'ключей'),
            'list' => implode(', ', array_slice($settingsPlan['refused'], 0, 8))])) ?></p>
      <?php endif; ?>
      <form method="post" action="/admin/tools/settings-import" class="mt-16"
            data-confirm="<?= esc(t('Применить настройки из файла? Нынешние значения перезапишутся.')) ?>">
        <?= csrf_field() ?><input type="hidden" name="step" value="apply">
        <button class="btn btn-primary" type="submit"><?= esc(t('Применить')) ?></button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="grid-2">
      <div>
        <div class="hint mb-8"><?= esc(t('Выгрузить')) ?></div>
        <a data-download class="btn btn-primary btn-block" href="/admin/tools/settings.json">
          <?= icon('download', 17) ?> <?= esc(t('Скачать settings.json')) ?>
        </a>
        <p class="hint mt-8"><?= esc(t('Правила, оформление, документы, сроки и реквизиты. Люди, ключи подписи и владелец не переносятся: в чужой базе они указывают в пустоту.')) ?></p>
        <a data-download class="btn btn-ghost btn-s mt-8" href="/admin/tools/settings.json?with_secrets=1">
          <?= esc(t('…то же с паролями и секретами внутри')) ?>
        </a>
        <p class="hint mt-4"><?= esc(t('По умолчанию пароль SMTP и секреты соцвхода в файл не попадают: он ходит по почте и лежит в загрузках.')) ?></p>
      </div>
      <div>
        <div class="hint mb-8"><?= esc(t('Загрузить')) ?></div>
        <form method="post" action="/admin/tools/settings-import" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="step" value="upload">
          <div class="inline-field">
            <input class="input" type="file" name="file" accept="application/json,.json" required
                   aria-label="<?= esc(t('Файл настроек')) ?>">
            <button class="btn btn-secondary" type="submit"><?= esc(t('Посмотреть')) ?></button>
          </div>
          <p class="hint mt-8"><?= esc(t('Сначала покажем построчно, что изменится, и только потом спросим. Ключи, которых нет в списке переносимых, не примем.')) ?></p>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="bento-card col-6">
    <div class="bc-title"><?= esc(t('Бэкап')) ?></div>
    <p class="muted"><?= esc(t('Ключи подписи, конфиг с APP_KEY и полный дамп базы — одним архивом. Потеря ключей означает, что все порталы перестанут доверять выданным токенам.')) ?></p>
    <a data-download class="btn btn-primary btn-block" href="/admin/tools/backup.zip">
      <?= icon('download', 17) ?> <?= esc(t('Скачать бэкап')) ?>
    </a>
    <div class="secret-plate mt-8"><?= esc(t('Внутри — приватные ключи. Храните архив как пароль от всего и не кладите в общедоступные папки.')) ?></div>
  </div>

  <div class="bento-card col-6" id="migrations">
    <div class="bc-title"><?= esc(t('Обслуживание')) ?></div>
    <div class="stack">
      <form method="post" action="/admin/tools/migrate">
        <?= csrf_field() ?>
        <button class="btn btn-secondary btn-block" type="submit">
          <?= esc(t('Применить миграции')) ?><?= $pendingMigrations !== [] ? ' (' . esc(t(':n ждёт', ['n' => count($pendingMigrations)])) . ')' : '' ?>
        </button>
      </form>
      <?php if ($pendingMigrations !== []): ?>
      <div class="hint mono"><?= esc(implode(', ', $pendingMigrations)) ?></div>
      <?php endif; ?>
      <form method="post" action="/admin/tools/clear-cache">
        <?= csrf_field() ?>
        <button class="btn btn-secondary btn-block" type="submit"><?= esc(t('Очистить кэши')) ?></button>
      </form>
    </div>
    <p class="hint mt-16"><?= esc(t('После обновления кода зайдите сюда и примените pending-миграции (§5.2).')) ?></p>
  </div>
</div>

<?php /* Чистка. Автоматика была и раньше — cron-задачи и сроки хранения, — но
         всё упиралось в планировщик, а он после установки настроен далеко не
         всегда. И решение «что чистить» принималось вслепую: может, логи весят
         три мегабайта, а место съедают аватары удалённых аккаунтов. */ ?>
<div class="bento-card col-12" id="housekeeping">
  <div class="bc-title"><?= esc(t('Место и чистка')) ?></div>
  <?php if (!$cronAlive): ?>
  <?= callout(t('Планировщик не подаёт признаков жизни: автоматическая чистка не идёт. Кнопки ниже делают то же самое вручную.'), 'warning') ?>
  <?php endif; ?>
  <div class="stack mb-16">
    <?php foreach ($usage as $u): ?>
    <div class="row-between">
      <span class="cell-main"><?= esc($u['title']) ?>
        <span class="hint"><?= esc($u['hint']) ?></span></span>
      <span class="mono">
        <?php if ($u['bytes'] > 0): ?><?= esc(App\Core\Housekeeping::human($u['bytes'])) ?> · <?php endif; ?>
        <?= (int)$u['count'] ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="row">
    <form method="post" action="/admin/tools/housekeeping">
      <?= csrf_field() ?>
      <button class="btn btn-secondary" type="submit"><?= icon('refresh', 16) ?> <?= esc(t('Почистить сейчас')) ?></button>
    </form>
    <form method="post" action="/admin/tools/orphans"
          data-confirm="<?= esc(t('Удалить файлы, которым не соответствует ни одна запись в базе? Отменить нельзя.')) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-secondary" type="submit"><?= icon('trash', 16) ?> <?= esc(t('Убрать сирот')) ?></button>
    </form>
  </div>
  <p class="hint mt-8"><?= esc(t('«Почистить сейчас» выполняет ровно те же задачи, что планировщик: истёкшие сессии и токены, ротация логов, протухшие выгрузки. Сроки хранения — в настройках, раздел «Политики».')) ?></p>
  <p class="hint"><?= esc(t('Сироты — файлы без записей в базе: остаются после обрывов и ручных правок. Журнал аудита не трогаем: это журнал безопасности, его срок задаётся отдельно.')) ?></p>
</div>
