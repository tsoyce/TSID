<?php /** Раздел настроек «Вход через сервисы» (§5.6).
 *  Поля объявлением: у каждого провайдера одинаковая пара «идентификатор +
 *  секрет», и рисовать её руками по разу на провайдера значит однажды забыть
 *  подпись у одного из них. */
use App\Core\Setting;
?>
  <div class="bento-card">
    <div class="bc-title"><?= esc(t('Вход через внешние сервисы')) ?></div>
    <p class="muted"><?= esc(t('Все провайдеры выключены по умолчанию. Включайте только те, что нужны: каждому нужна пара «идентификатор + секрет» из кабинета разработчика соответствующего сервиса.')) ?></p>
    <form method="post" action="/admin/settings/save">
      <?= csrf_field() ?><input type="hidden" name="section" value="social">
      <?php foreach (App\Social\Provider::all() as $p): $id = $p->id(); ?>
      <div class="bento-card mb-16 surface-2">
        <?php /* Подпись тумблера — имя провайдера, а пояснение объясняет, чего
                 не хватает: включённый провайдер без ключей просто не появится
                 на экране входа, и понять это было неоткуда. */ ?>
        <?= field_toggle('enabled_' . $id, $p->title(),
              Setting::get('social.' . $id . '.enabled') === '1',
              Setting::get('social.' . $id . '.client_id', '') === ''
                ? t('Без ключей ниже кнопка на экране входа не появится.')
                : '') ?>
        <div class="grid-2">
          <?= field_text('client_id_' . $id,
                $id === 'telegram' ? t('Имя бота (без @)') : 'Client ID',
                (string)Setting::get('social.' . $id . '.client_id', ''),
                ['mono' => true, 'max' => 190, 'autocomplete' => 'off']) ?>
          <?php /* Плейсхолдер точками, если секрет уже сохранён: пустое поле
                   неотличимо от «не заполнено». Само значение наружу не
                   отдаётся — в value его нет. */ ?>
          <?= field_text('secret_' . $id,
                $id === 'telegram' ? t('Токен бота') : 'Client secret', '',
                ['type' => 'password', 'autocomplete' => 'new-password',
                 'note' => t('(пусто = не менять)'),
                 'placeholder' => Setting::get('social.' . $id . '.secret', '') !== '' ? '••••••••' : '']) ?>
        </div>
        <?php if ($id !== 'telegram'): ?>
        <?= field_copy('ru-' . $id, t('Redirect URI — впишите его в кабинете провайдера'),
              $p->redirectUri(), ['class' => 'mb-0']) ?>
        <?php else: ?>
        <p class="hint mb-0"><?= esc(t('В BotFather выполните')) ?> <code>/setdomain</code> <?= esc(t('и укажите')) ?>
          <code><?= esc((string)parse_url(base_url(), PHP_URL_HOST)) ?></code> <?= esc(t('— иначе виджет не появится.')) ?></p>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <p class="hint mt-8"><?= esc(t('Совет: у входа через сервис нет пароля, поэтому он не заменяет второй фактор — это просто ещё один способ подтвердить, что вы — это вы.')) ?></p>
      <?= form_actions() ?>
    </form>
  </div>
