<?php /* Адреса возврата, которые сервис прислал, а у нас их нет. Стоят над
         формой, а не внутри неё: у каждого своя кнопка, а вложенная форма в
         HTML не работает. См. App\Core\RedirectMiss. */ ?>
<?php foreach (['redirect' => t('Сервис просит вернуть на адрес, которого здесь нет'),
                'logout' => t('После выхода сервис просит вернуть на адрес, которого здесь нет')] as $kind => $missTitle):
      $misses = App\Core\RedirectMiss::pending($c, $kind); if ($misses === []) continue; ?>
<div class="bento-card mb-16" role="status">
  <div class="bc-title"><?= esc($missTitle) ?></div>
  <p class="hint"><?= esc($kind === 'logout'
      ? t('После выхода человека вернули на сам сервис, а не на этот адрес: адреса сравниваются байт-в-байт. Если адрес ваш — добавьте его.')
      : t('Такой вход не пропущен: адрес возврата сравнивается байт-в-байт. Если адрес ваш — добавьте его, и следующий вход пройдёт.')) ?></p>
  <?php foreach ($misses as $m): ?>
  <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/redirect-miss" class="row-between mt-8">
    <?= csrf_field() ?>
    <input type="hidden" name="uri" value="<?= esc($m['uri']) ?>">
    <input type="hidden" name="kind" value="<?= esc($kind) ?>">
    <span><code><?= esc($m['uri']) ?></code>
      <span class="hint"><?= esc(t(':n раз, последний — :at', ['n' => (int)$m['n'], 'at' => fmt_dt((string)$m['at'])])) ?></span></span>
    <span class="row">
      <button class="btn btn-primary" type="submit" name="mode" value="accept"><?= esc(t('Добавить')) ?></button>
      <button class="btn btn-secondary" type="submit" name="mode" value="forget"><?= esc(t('Забыть')) ?></button>
    </span>
  </form>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
  <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/update" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="bento">
      <div class="bento-card col-8">
        <div class="bc-title"><?= esc(t('Основное')) ?></div>
        <div class="grid-2">
          <?= field_text('name', t('Название'), $c['name'], ['max' => 120]) ?>
          <?= field_text('base_url', t('Базовый URL'), $c['base_url'], ['mono' => true, 'type' => 'url']) ?>
        </div>
        <?php /* Машинное имя правится наравне с человеческим. Оно назначается
                 при подключении из домена и попадает пальцем в небо чаще, чем
                 хотелось бы; жить с этим вечно портал не обязан. */ ?>
        <?= field_text('slug', t('Машинное имя'), $c['slug'], [
              'mono' => true, 'max' => 50, 'pattern' => '[a-z0-9-]{2,50}',
              'hint' => t('Латиница, цифры и дефис. Уезжает префиксом в claim roles («:slug:editor») и в имена файлов набора. Пока карта ролей пуста, портал разбирает роли по этому префиксу — переименование он заметит.',
                  ['slug' => $c['slug']])]) ?>
        <?php /* client_id стоял в «Политиках» вместе с иконкой — по той же
                 причине, по которой там оказалась иконка: место было. Это имя
                 портала для машины, ему место рядом с именем для человека. */ ?>
        <?= field_copy('cid2', 'client_id', $c['client_id'], [
              'hint' => t('Его подставляют в конфиг на стороне портала')]) ?>
        <?php /* Владение, а не допуск: роль, выданная на организацию,
                 действует во всех её проектах (SCOPES §2). Допуск чужой
                 организации к этому порталу — отдельная вещь, ниже. */ ?>
        <?php $orgOptions = ['' => t('— ничей (уровень системы)')];
              foreach ($allOrgs as $o) $orgOptions[(int)$o['id']] = $o['name']; ?>
        <?= field_select('org_id', t('Организация'), $orgOptions, (string)($c['org_id'] ?? ''), [
              'hint' => t('Чей это проект. Роли, выданные на организацию, действуют во всех её проектах.')]) ?>
        <?= field_text('description', t('Описание'), $c['description'], ['max' => 255]) ?>
        <?php /* Иконка стояла в «Политиках» — не потому, что она политика, а
                 потому, что колонка была свободна. Здесь ей место: имя, адрес,
                 описание и знак — это одно и то же «кто он». */ ?>
        <?= field_image('icon', t('Иконка портала'), client_icon_html($c, 32), (bool)$c['icon_path'], [
              'note' => t('(квадрат, до 2 МБ)'),
              'remove' => t('Убрать иконку'),
              'hint' => t('Покажется на экране входа рядом с названием')]) ?>
        <?= field_textarea('redirect_uris', t('Redirect URI'), implode("\n", $c['redirect_uris']), [
              'rows' => 3, 'mono' => true,
              'hint' => t('По одному на строку. Сравнение байт-в-байт: лишний слэш на конце — уже другой адрес')]) ?>
        <div class="grid-2">
          <?= field_textarea('post_logout_uris', 'Post-logout URI', implode("\n", $c['post_logout_uris']), [
                'rows' => 2, 'mono' => true, 'hint' => t('Куда вернуть после выхода')]) ?>
          <?= field_text('backchannel_uri', 'Backchannel URI', $c['backchannel_uri'], [
                'mono' => true, 'type' => 'url', 'hint' => t('Сюда придёт весть о выходе и блокировке')]) ?>
        </div>
      </div>
      <div class="bento-card col-4">
        <div class="bc-title"><?= esc(t('Политики')) ?></div>
        <?php /* Раньше в списке стояли три голых слова — open, approval,
                 invite, — и что выберет портал, было ясно только тому, кто
                 читал спеку. Выбор обязан объясняться на месте: сюда заходят
                 раз в полгода.
                 Объяснение — под списком, а не в пунктах (v4.70): фразы по
                 восемьдесят знаков в колонке шириной в треть экрана
                 обрезались на полуслове, «Любого, у кого есть аккаунт (». */ ?>
        <?php /* Карточками (v4.71): у каждого варианта своё объяснение прямо в
                 нём, и не надо сверять пункт списка с подсказкой под ним. */ ?>
        <?= field_choices('access_policy', t('Кого пускать'), [
              'open' => ['title' => \App\Models\Client::policyLabel('open'), 'hint' => t('Входит сразу')],
              'approval' => ['title' => \App\Models\Client::policyLabel('approval'), 'hint' => t('Человек просит, вы одобряете')],
              'invite' => ['title' => \App\Models\Client::policyLabel('invite'), 'hint' => t('Только те, кого позвали вы')],
            ], (string)$c['access_policy'], ['cols' => 1, 'class' => 'choices-rows']) ?>
        <?php /* Тумблеры, а не галочки: у каждой строки два СОСТОЯНИЯ портала,
                 а не выбор из списка (§ поля). Пояснение обязательно — до
                 этого здесь стояло «Доверенный (без consent)», и что случится
                 при включении, знал только тот, кто читал спеку OIDC. */ ?>
        <div class="stack">
          <?= field_toggle('is_active', t('Портал включён'), (bool)$c['is_active'],
                t('Выключенный не пускает никого и не обновляет токены. Настройки сохраняются.')) ?>
          <?= field_toggle('is_trusted', t('Доверенный'), (bool)$c['is_trusted'],
                t('Для своих сервисов: на первом входе без перечня данных. Сам экран убирает настройка «Спрашивать при первом входе в каждый сервис».')) ?>
          <?= field_toggle('same_site_family', t('Родственный домен'), (bool)$c['same_site_family'],
                t('Тихий вход без формы (prompt=none) — для соседних доменов одной организации.')) ?>
          <?= field_toggle('require_2fa', t('Требовать второй фактор'), (bool)$c['require_2fa'],
                t('Без второго фактора не пустит; у кого его нет — предложим настроить на входе.')) ?>
          <?= field_toggle('allow_offline', t('Долгие токены (offline_access)'), in_array('offline_access', $c['allowed_scopes'], true),
                t('Обновление доступа без участия человека — для фоновых задач.')) ?>
          <?php /* Витрина на гостевой выключена по умолчанию: перечень
                   внутренних сервисов — информация об устройстве организации,
                   и отдавать её любому, кто набрал адрес, нельзя. */ ?>
          <?= field_toggle('show_on_home', t('Предлагать этот сервис'), !empty($c['show_on_home']),
                t('Витрина на главной (видна до входа) и «Мои сервисы» в профиле. Выключено — сервис работает, но сам о себе не рассказывает.')) ?>
          <?php /* Обложка на витрине (v4.76): загрузить её было негде, и
                   карточки стояли пустыми. Своя картинка главнее; без неё
                   SSO берёт картинку превью ссылок с главной портала. */
                $cover = App\Core\PortalCover::meta((int)$c['id']);
                $coverHas = is_file(App\Core\PortalCover::path((int)$c['id']));
                $coverHint = match (true) {
                    $cover['source'] === 'upload' => t('Своя картинка — сайт портала её не заменяет. Уберите, чтобы снова брать картинку с сайта.'),
                    $cover['source'] === 'site' => t('Взята с сайта портала :at — картинка превью ссылок (og:image). Обновляется раз в сутки.', ['at' => fmt_dt($cover['at'])]),
                    $cover['source'] === 'shot' => t('Снимок главной сайта портала :at (сервис Microlink). Обновляется раз в сутки; картинка превью ссылок (og:image), если портал её отдаст, главнее снимка.', ['at' => fmt_dt($cover['at'])]),
                    $cover['why'] !== '' => t('С сайта взять не вышло: :why Пока — карточка в цвете портала с его знаком.', ['why' => $cover['why']]),
                    default => t('Своей нет — SSO возьмёт картинку превью ссылок (og:image) с главной портала. Пока её нет — карточка в цвете портала с его знаком.'),
                }; ?>
          <?= field_image('card_cover', t('Обложка на витрине'), portal_card_shot($c, 'card-cover-thumb'), $cover['source'] === 'upload' && $coverHas, [
                'note' => t('(JPEG, PNG, GIF или WebP до 5 МБ, лучше 1200×630)'),
                'remove' => t('Убрать свою картинку'),
                'hint' => $coverHint]) ?>
          <?php if ($cover['source'] !== 'upload'): ?>
          <button class="btn btn-secondary btn-s mb-16" type="submit" formaction="/admin/clients/<?= (int)$c['id'] ?>/cover-fetch"
                  formnovalidate><?= icon('refresh', 15) ?> <?= esc(t('Взять с сайта сейчас')) ?></button>
          <?php endif; ?>
        </div>
        <?= form_actions() ?>
      </div>
    </div>
  </form>
